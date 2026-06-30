<?php

namespace Huoxin\MoneyWithHistory\Tests\integration;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleting;
use Flarum\Discussion\Event\Hidden;
use Flarum\Discussion\Event\Restored;
use Flarum\Discussion\Event\Started;
use Flarum\Post\Event\Posted;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Job\BatchAdjustBalances;
use Huoxin\MoneyWithHistory\Job\CascadeDiscussionMoney;
use Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class DiscussionRewardTest extends TestCase
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
            Discussion::class => [
                ['id' => 1, 'title' => 'Public Discussion', 'user_id' => 2, 'is_approved' => 1, 'comment_count' => 2, 'is_private' => 0],
                ['id' => 2, 'title' => 'Unapproved Discussion', 'user_id' => 2, 'is_approved' => 0, 'comment_count' => 1, 'is_private' => 0],
                ['id' => 3, 'title' => 'Private Discussion', 'user_id' => 2, 'is_approved' => 1, 'comment_count' => 1, 'is_private' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => 'First post', 'is_approved' => 1, 'number' => 1],
                ['id' => 2, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => 'Second post', 'is_approved' => 1, 'number' => 2],
            ]
        ]);

        $this->setting('huoxin-money-with-history.post_reward_amount', 5);
        $this->setting('huoxin-money-with-history.discussion_reward_amount', 10);
        $this->setting('huoxin-money-with-history.min_post_length', 0);
        $this->setting('huoxin-money-with-history.remove_money_trigger', 2); // 2 = Deleted

        $this->app();
    }

    #[Test]
    public function starting_discussion_gives_money()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);
        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);

        $subscriber->discussionWasStarted(new Started($discussion, $user));

        $this->assertEquals(10.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function hiding_discussion_removes_money()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // 1 = Hidden

        // Start discussion -> +10
        $subscriber->discussionWasStarted(new Started($discussion, $user));
        $this->assertEquals(10.0, (float) $user->fresh()->money);

        // Hide discussion -> -10
        $subscriber->discussionWasHidden(new Hidden($discussion, $user));
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function restoring_hidden_discussion_gives_money_back()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // 1 = Hidden

        $subscriber->discussionWasStarted(new Started($discussion, $user));
        $subscriber->discussionWasHidden(new Hidden($discussion, $user));
        $this->assertEquals(0.0, (float) $user->fresh()->money);

        // Restore -> +10
        $subscriber->discussionWasRestored(new Restored($discussion, $user));
        $this->assertEquals(10.0, (float) $user->fresh()->money);
        $this->assertSame(3, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function deleting_discussion_removes_money()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);
        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);

        $subscriber->discussionWasStarted(new Started($discussion, $user));
        $this->assertEquals(10.0, (float) $user->fresh()->money);

        $subscriber->discussionWillBeDeleted(new Deleting($discussion, $user));
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function deleting_discussion_cascades_and_removes_post_money()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $cascade = $reflection->getProperty('cascadeMoneyRemoval');
        $cascade->setValue($subscriber, true); // Enable cascade

        // Start discussion (+10) and add a reply (+5)
        $subscriber->discussionWasStarted(new Started($discussion, $user));

        $post = Post::query()->findOrFail(2);
        $subscriber->postWasPosted(new Posted($post, $user));

        $this->assertEquals(15.0, (float) $user->fresh()->money);

        // Deleting discussion should remove the discussion money (-10) AND cascade remove the post money (-5)
        $subscriber->discussionWillBeDeleted(new Deleting($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(4, $this->connection()->table('user_money_history')->count()); // +10, +5, -10, -5
    }

    #[Test]
    public function deleting_discussion_does_not_cascade_if_disabled()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $cascade = $reflection->getProperty('cascadeMoneyRemoval');
        $cascade->setValue($subscriber, false); // Disable cascade

        // Start discussion (+10) and add a reply (+5)
        $subscriber->discussionWasStarted(new Started($discussion, $user));
        $post = Post::query()->findOrFail(2);
        $subscriber->postWasPosted(new Posted($post, $user));

        $this->assertEquals(15.0, (float) $user->fresh()->money);

        // Deleting discussion should ONLY remove the discussion money (-10), leaving the post money (+5)
        $subscriber->discussionWillBeDeleted(new Deleting($discussion, $user));

        $this->assertEquals(5.0, (float) $user->fresh()->money);
        $this->assertSame(3, $this->connection()->table('user_money_history')->count()); // +10, +5, -10
    }

    #[Test]
    public function hiding_discussion_cascades_penalty()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $cascade = $reflection->getProperty('cascadeMoneyRemoval');
        $cascade->setValue($subscriber, true);

        $auto = $reflection->getProperty('removeMoneyTrigger');
        $auto->setValue($subscriber, 1); // Hidden

        $subscriber->discussionWasStarted(new Started($discussion, $user));
        $post = Post::query()->findOrFail(2);
        $subscriber->postWasPosted(new Posted($post, $user));
        $this->assertEquals(15.0, (float) $user->fresh()->money);

        // Hide -> -10 (discussion) and -5 (post)
        $subscriber->discussionWasHidden(new Hidden($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
    }

    #[Test]
    public function restoring_hidden_discussion_cascades_reward()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $cascade = $reflection->getProperty('cascadeMoneyRemoval');
        $cascade->setValue($subscriber, true);

        $auto = $reflection->getProperty('removeMoneyTrigger');
        $auto->setValue($subscriber, 1); // Hidden

        $subscriber->discussionWasStarted(new Started($discussion, $user));
        $post = Post::query()->findOrFail(2);
        $subscriber->postWasPosted(new Posted($post, $user));
        $subscriber->discussionWasHidden(new Hidden($discussion, $user));
        $this->assertEquals(0.0, (float) $user->fresh()->money);

        // Restore -> +10 (discussion) and +5 (post)
        $subscriber->discussionWasRestored(new Restored($discussion, $user));

        $this->assertEquals(15.0, (float) $user->fresh()->money);
    }

    #[Test]
    public function hiding_unapproved_discussion_prevents_double_penalty()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(2); // is_approved = 0

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);

        // Flarum sets is_approved=1 when hiding an unapproved discussion
        $discussion->is_approved = 1;
        $discussion->syncOriginal(); // Simulate the model state before the event
        $discussion->is_approved = 0; // The event will have wasChanged('is_approved') = true

        $subscriber->discussionWasHidden(new Hidden($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function permanently_deleting_unapproved_discussion_prevents_double_penalty()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(2); // is_approved = 0

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);

        $discussion->is_approved = 1;
        $discussion->syncOriginal();
        $discussion->is_approved = 0; // The event will have wasChanged('is_approved') = true

        $subscriber->discussionWillBeDeleted(new Deleting($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function private_discussion_does_not_give_money_by_default()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(3); // is_private = 1

        $discussion->is_private = 1;

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);

        $subscriber->discussionWasStarted(new Started($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function private_discussion_gives_money_when_enabled()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(3); // is_private = 1

        $discussion->is_private = 1;

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('rewardPrivateDiscussion');
        $property->setValue($subscriber, true);

        $subscriber->discussionWasStarted(new Started($discussion, $user));

        $this->assertEquals(10.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function hiding_discussion_keeps_money_if_remove_money_trigger_is_deleted()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 2); // 2 = Deleted

        $subscriber->discussionWasStarted(new Started($discussion, $user));
        $this->assertEquals(10.0, (float) $user->fresh()->money);

        $subscriber->discussionWasHidden(new Hidden($discussion, $user));

        // Balance NOT deducted
        $this->assertEquals(10.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function deleting_discussion_removes_money_if_remove_money_trigger_is_hidden()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // 1 = Hidden

        $subscriber->discussionWasStarted(new Started($discussion, $user));
        $this->assertEquals(10.0, (float) $user->fresh()->money);

        // Delete without hiding first
        $subscriber->discussionWillBeDeleted(new Deleting($discussion, $user));

        // Balance IS deducted because of our patch
        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function restoring_unapproved_discussion_does_not_give_money()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(2); // is_approved = 0

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // Hidden

        $subscriber->discussionWasRestored(new Restored($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function restoring_private_discussion_does_not_give_money_by_default()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(3); // is_private = 1

        $discussion->is_private = 1;

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // Hidden

        $subscriber->discussionWasRestored(new Restored($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function hiding_private_discussion_prevents_double_penalty()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(3); // is_private = 1

        $discussion->is_private = 1;

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);
        $property = $reflection->getProperty('removeMoneyTrigger');
        $property->setValue($subscriber, 1); // Hidden

        $subscriber->discussionWasHidden(new Hidden($discussion, $user));

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function hiding_discussion_dispatches_cascade_job()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);

        $cascade = $reflection->getProperty('cascadeMoneyRemoval');
        $cascade->setValue($subscriber, true);

        $auto = $reflection->getProperty('removeMoneyTrigger');
        $auto->setValue($subscriber, 1); // Hidden

        $mockQueue = Mockery::mock(Queue::class);
        $mockQueue->shouldReceive('push')->once()->withArgs(function ($job) {
            return $job instanceof CascadeDiscussionMoney;
        });

        $this->app()->getContainer()->instance(Queue::class, $mockQueue);

        $subscriber->discussionWasHidden(new Hidden($discussion, $user));

        $this->assertTrue(true);
    }

    #[Test]
    public function deleting_discussion_dispatches_batch_job()
    {
        $user = User::query()->findOrFail(2);
        $discussion = Discussion::query()->findOrFail(1);

        // Add a dummy post so there is a user delta to trigger the job
        $this->connection()->table('posts')->insert([
            'discussion_id' => 1,
            'user_id' => 2,
            'type' => 'comment',
            'content' => 'Dummy post',
            'number' => 3,
            'is_approved' => 1,
            'created_at' => Carbon::now()
        ]);

        $subscriber = $this->app()->getContainer()->make(MoneyBalanceSubscriber::class);
        $reflection = new ReflectionClass($subscriber);

        $cascade = $reflection->getProperty('cascadeMoneyRemoval');
        $cascade->setValue($subscriber, true);

        $auto = $reflection->getProperty('removeMoneyTrigger');
        $auto->setValue($subscriber, 2); // Deleted

        $mockQueue = Mockery::mock(Queue::class);
        $mockQueue->shouldReceive('push')->once()->withArgs(function ($job) {
            return $job instanceof BatchAdjustBalances;
        });

        $this->app()->getContainer()->instance(Queue::class, $mockQueue);

        $subscriber->discussionWillBeDeleted(new Deleting($discussion, $user));

        $this->assertTrue(true);
    }

    private function connection(): ConnectionInterface
    {
        return $this->app()->getContainer()->make(ConnectionInterface::class);
    }
}
