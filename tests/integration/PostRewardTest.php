<?php

namespace Huoxin\MoneyWithHistory\Tests\integration;

use Flarum\Approval\Event\PostWasApproved;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Started;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Post;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

class PostRewardTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-flags');
        $this->extension('flarum-approval');
        $this->extension('flarum-tags');
        $this->extension('fof-byobu');
        $this->extension('huoxin-money-with-history');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
            ],
            Tag::class => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general'],
                ['id' => 2, 'name' => 'No Money', 'slug' => 'nomoney']
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1],
                ['discussion_id' => 4, 'tag_id' => 2]
            ],
            'group_permission' => [
                ['group_id' => 3, 'permission' => 'tag2.discussion.money.disable_money']
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Public Discussion', 'user_id' => 2, 'is_approved' => 1, 'comment_count' => 1, 'is_private' => 0],
                ['id' => 2, 'title' => 'Unapproved Discussion', 'user_id' => 2, 'is_approved' => 0, 'comment_count' => 1, 'is_private' => 0],
                ['id' => 3, 'title' => 'Private Discussion', 'user_id' => 2, 'is_approved' => 1, 'comment_count' => 1, 'is_private' => 1],
                ['id' => 4, 'title' => 'No Money Discussion', 'user_id' => 2, 'is_approved' => 1, 'comment_count' => 1, 'is_private' => 0],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => 'First post', 'is_approved' => 1, 'number' => 1],
                ['id' => 2, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => 'Unapproved post', 'is_approved' => 0, 'number' => 2],
                ['id' => 3, 'discussion_id' => 3, 'user_id' => 2, 'type' => 'comment', 'content' => 'Private post', 'is_approved' => 1, 'number' => 2],
                ['id' => 4, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => 'Approved reply', 'is_approved' => 1, 'number' => 3],
                ['id' => 5, 'discussion_id' => 4, 'user_id' => 2, 'type' => 'comment', 'content' => 'No money reply', 'is_approved' => 1, 'number' => 2],
            ]
        ]);

        $this->setting('huoxin-money-with-history.post_reward_amount', 5);
        $this->setting('huoxin-money-with-history.discussion_reward_amount', 10);
        $this->setting('huoxin-money-with-history.min_post_length', 0);
        $this->setting('huoxin-money-with-history.remove_money_trigger', 2); // 2 = Deleted

        $this->app();
    }

    #[Test]
    public function unapproved_post_does_not_give_money_but_gives_when_approved()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(2); // is_approved = 0
        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // Simulate posting unapproved
        $subscriber->postWasPosted(new Posted($post, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());

        // Simulate admin approving
        $post->is_approved = true;
        $post->save();
        $subscriber->postWasApproved(new PostWasApproved($post, $user));

        $this->assertEquals(5.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());

        // Refresh models to sync state for the deletion phase
        $user = $user->fresh();
        $post = $post->fresh();
        $post->setRelation('user', $user);

        // Simulate deleting the approved post
        $subscriber->postWasDeleted(new Deleted($post, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function approving_private_post_does_not_give_money_by_default()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(3); // Private post
        $post->is_approved = 0; // Make it unapproved initially
        $post->save();

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $post->is_approved = 1;
        $subscriber->postWasApproved(new PostWasApproved($post, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function approving_discussion_starter_post_gives_discussion_money()
    {
        $user = User::query()->findOrFail(2);

        // Post 1 is a discussion starter
        $post = Post::query()->findOrFail(1);
        $post->is_approved = 0;
        $post->save();

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $post->is_approved = 1;
        $subscriber->postWasApproved(new PostWasApproved($post, $user));

        // Should give discussionRewardAmount (10.0), NOT postRewardAmount (5.0)
        $this->assertEquals(10.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function approving_short_post_does_not_give_money()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(2); // Unapproved post (length 15)

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('minPostLength');
        $property->setValue($subscriber, 50);

        $post->is_approved = 1;
        $subscriber->postWasApproved(new PostWasApproved($post, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function unapproved_discussion_does_not_give_money()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(2); // is_approved = 0
        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $subscriber->discussionWasStarted(new Started($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function private_discussion_does_not_give_money_by_default()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(3); // belongs to discussion 3 (is_private = 1)

        $post->discussion->is_private = 1; // Force attribute for test environment

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // With rewardPrivateDiscussion = false (default at boot)
        $subscriber->postWasPosted(new Posted($post, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function private_discussion_gives_money_when_enabled()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(3);

        $post->discussion->is_private = 1;

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('rewardPrivateDiscussion');
        $property->setValue($subscriber, true);

        $subscriber->postWasPosted(new Posted($post, $user));

        $this->assertEquals(5.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function hiding_unapproved_post_prevents_double_penalty()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(2); // is_approved = 0

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // 1 = Hidden

        // User posts unapproved content
        $subscriber->postWasPosted(new Posted($post, $user));
        $this->assertEquals(0.0, (float) $user->fresh()->money); // No money given

        // Admin hides the unapproved post.
        // Flarum's ApproveContent listener sets is_approved = true during the hide.
        $post->is_approved = true;
        $post->syncChanges(); // This populates wasChanged('is_approved')

        // Dispatch hidden event
        $subscriber->postWasHidden(new Hidden($post, $user));

        // Balance should NOT be deducted because it was automatically approved while hiding
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function permanently_deleting_unapproved_post_prevents_double_penalty()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(2); // is_approved = 0

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // Dispatch permanently deleted event directly (is_approved remains 0)
        $subscriber->postWasDeleted(new Deleted($post, $user));

        // Balance should NOT be deducted because it was never approved
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function deleting_private_post_prevents_double_penalty()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(3); // private post

        $post->discussion->is_private = 1;

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // Dispatch deleted event
        $subscriber->postWasDeleted(new Deleted($post, $user));

        // Balance should NOT be deducted because it was a private post (no reward initially)
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function deleting_private_discussion_prevents_double_penalty()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(3); // private discussion
        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // Dispatch discussion deleted event
        $subscriber->discussionWillBeDeleted(new \Flarum\Discussion\Event\Deleting($discussion, $user));

        // Balance should NOT be deducted for the discussion because it was private
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function hiding_approved_post_removes_money()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(4); // is_approved = 1

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // 1 = Hidden

        // Simulate normal posting
        $subscriber->postWasPosted(new Posted($post, $user));
        $this->assertEquals(5.0, (float) $user->fresh()->money);

        // Simulate hiding the post
        $subscriber->postWasHidden(new Hidden($post, $user));

        // Balance should be deducted
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function restoring_hidden_post_gives_money_back()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(4); // is_approved = 1

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // 1 = Hidden

        // Setup: User has a hidden post (net 0.0)
        $subscriber->postWasPosted(new Posted($post, $user));
        $subscriber->postWasHidden(new Hidden($post, $user));
        $this->assertEquals(0.0, (float) $user->fresh()->money);

        // Simulate restoring the post
        $subscriber->postWasRestored(new \Flarum\Post\Event\Restored($post, $user));

        // Balance should be given back
        $this->assertEquals(5.0, (float) $user->fresh()->money);
        $this->assertSame(3, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function deleting_approved_post_removes_money()
    {
        $this->setting('huoxin-money-with-history.remove_money_trigger', 2); // 2 = Deleted

        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(4); // is_approved = 1

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // Setup: User posted and got money
        $subscriber->postWasPosted(new Posted($post, $user));
        $this->assertEquals(5.0, (float) $user->fresh()->money);

        // Simulate permanently deleting the post
        $subscriber->postWasDeleted(new Deleted($post, $user));

        // Balance should be deducted
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function post_content_too_short_does_not_give_money()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(4); // "Approved reply" length is 14

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('minPostLength');
        $property->setValue($subscriber, 50);

        // Simulate posting with content shorter than minimum
        $subscriber->postWasPosted(new Posted($post, $user));

        // Balance should NOT be given
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function remove_money_trigger_never_prevents_penalty()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(4); // is_approved = 1

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 0); // 0 = Never

        // Setup: User posted and got money
        $subscriber->postWasPosted(new Posted($post, $user));
        $this->assertEquals(5.0, (float) $user->fresh()->money);

        // Simulate permanently deleting the post
        $subscriber->postWasDeleted(new Deleted($post, $user));

        // Balance should NOT be deducted because removeMoneyTrigger is 0
        $this->assertEquals(5.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function exclude_mentions_from_length_strips_mentions_for_minimum_length()
    {
        $user = User::query()->findOrFail(2);

        // This post is 15 chars, but 12 chars is the mention
        $post = Post::query()->findOrFail(4);
        $post->content = 'Hi @"admin"#1 !';

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $reflection = new \ReflectionClass($subscriber);
        $min = $reflection->getProperty('minPostLength');
        $min->setValue($subscriber, 10);

        $property = $reflection->getProperty('excludeMentionsFromLength');
        $property->setValue($subscriber, true);

        // Simulate posting
        $subscriber->postWasPosted(new Posted($post, $user));

        // The mention '...@"admin"#1...' is stripped, leaving 'Hi  !', which is 5 chars.
        // 5 < 10, so balance should NOT be given.
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function hiding_post_keeps_money_if_remove_money_trigger_is_deleted()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(4); // is_approved = 1

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 2); // 2 = Deleted

        // Setup: User posted and got money
        $subscriber->postWasPosted(new Posted($post, $user));
        $this->assertEquals(5.0, (float) $user->fresh()->money);

        // Simulate hiding the post
        $subscriber->postWasHidden(new Hidden($post, $user));

        // Balance should NOT be deducted because removeMoneyTrigger is 2
        $this->assertEquals(5.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function deleting_post_removes_money_if_remove_money_trigger_is_hidden()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(4); // is_approved = 1

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // 1 = Hidden

        // Setup: User posted and got money
        $subscriber->postWasPosted(new Posted($post, $user));
        $this->assertEquals(5.0, (float) $user->fresh()->money);

        // Simulate permanently deleting the unhidden post
        $subscriber->postWasDeleted(new Deleted($post, $user));

        // Balance SHOULD be deducted because our patch catches this loophole!
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function discussion_starter_post_does_not_give_reply_money()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(1); // number = 1 (discussion starter)

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // Simulate posting the first post of a discussion
        $subscriber->postWasPosted(new Posted($post, $user));

        // Balance SHOULD BE 0 because number=1 does not give post money (it gives discussion money via Started event)
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function restoring_unapproved_post_does_not_give_money()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(2); // is_approved = 0

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // Hidden

        $subscriber->postWasRestored(new Restored($post, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function restoring_private_post_does_not_give_money_by_default()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(3); // is_private discussion
        $post->discussion->is_private = 1;

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // Hidden

        $subscriber->postWasRestored(new Restored($post, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function hiding_private_post_prevents_double_penalty()
    {
        $user = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(3); // is_private discussion
        $post->discussion->is_private = 1;

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // Hidden

        $subscriber->postWasHidden(new Hidden($post, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function tag_with_disable_money_prevents_post_reward()
    {
        $user = User::query()->findOrFail(2);

        // This post is in discussion 4, which has tag 2.
        // We granted Group 3 (which User 2 belongs to) the 'tag2.discussion.money.disable_money' permission!
        $post = Post::query()->findOrFail(5);

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // When the user posts, the adjustPostAuthorBalance should silently abort because of the tag permission.
        $subscriber->postWasPosted(new Posted($post, $user));

        // Balance should BE 0 because of the tag permission!
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function post_by_deleted_user_does_not_crash_and_gives_no_money()
    {
        // Flarum posts can have user_id = null if the user was deleted
        $post = Post::query()->findOrFail(4);
        $post->user_id = null; // simulate deleted user
        $post->save();

        // The actor might be someone else, but the post author is null
        $actor = User::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // When the post is updated/approved/restored, it shouldn't crash trying to give money to null
        $subscriber->postWasRestored(new Restored($post, $actor));

        // We assert it simply returns safely without crashing
        $this->assertTrue(true);
    }

    #[Test]
    public function exclude_mentions_from_length_switch_false_counts_mentions_towards_minimum_length()
    {
        $user = User::query()->findOrFail(2);

        $post = Post::query()->findOrFail(4);
        $post->content = 'Hi @"admin"#1 !'; // 15 chars total

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $reflection = new \ReflectionClass($subscriber);
        $min = $reflection->getProperty('minPostLength');
        $min->setValue($subscriber, 10);

        // Turn OFF the excludeMentionsFromLength switch
        $excludeMentions = $reflection->getProperty('excludeMentionsFromLength');
        $excludeMentions->setValue($subscriber, false);

        $subscriber->postWasPosted(new Posted($post, $user));

        // Because switch is OFF, the mention is counted, 15 >= 10, so balance IS given!
        $this->assertEquals(5.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function exclude_mentions_from_length_strips_all_regex_mention_formats()
    {
        $user = User::query()->findOrFail(2);

        $post = Post::query()->findOrFail(4);
        // We will include one of each format from the 4 regexes in MoneyBalanceSubscriber
        // @"admin"#1 (User mention)
        // @"post"#p1 (Post mention)
        // @"discussion"#d1 (Discussion mention)
        // @"group" (Group mention)
        $post->content = 'Hi @"admin"#1 @"post"#p1 @"discussion"#d1 @"group" !';

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $reflection = new \ReflectionClass($subscriber);
        $min = $reflection->getProperty('minPostLength');
        $min->setValue($subscriber, 50);

        $excludeMentions = $reflection->getProperty('excludeMentionsFromLength');
        $excludeMentions->setValue($subscriber, true);

        $subscriber->postWasPosted(new Posted($post, $user));

        // The mentions are all stripped, leaving only spaces and text.
        // 50 is well above the length of the remaining string.
        // So balance should NOT be given.
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    private function connection(): ConnectionInterface
    {
        return $this->app()->getContainer()->make(ConnectionInterface::class);
    }
}
