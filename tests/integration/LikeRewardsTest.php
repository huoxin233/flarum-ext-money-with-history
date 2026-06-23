<?php

namespace Huoxin\MoneyWithHistory\Tests\integration;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

class MockPostWasLiked
{
    public function __construct(public Post $post, public User $user)
    {
    }
}

class MockPostWasUnliked
{
    public function __construct(public Post $post, public User $user)
    {
    }
}

class LikeRewardsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-likes');
        $this->extension('huoxin-money-with-history');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'liker', 'email' => 'liker@example.com', 'is_email_confirmed' => 1],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Public Discussion', 'user_id' => 2, 'is_approved' => 1, 'comment_count' => 1, 'is_private' => 0],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => 'First post', 'is_approved' => 1, 'number' => 1],
            ]
        ]);

        $this->setting('huoxin-money-with-history.like_reward_amount', 2); // 2 per like

        $this->app();
    }

    #[Test]
    public function liking_post_gives_author_money()
    {
        $author = User::query()->findOrFail(2);
        $liker = User::query()->findOrFail(3);
        $post = Post::query()->findOrFail(1);
        $post->setRelation('user', $author);

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $subscriber->postWasLiked(new MockPostWasLiked($post, $liker));

        $this->assertEquals(2.0, (float) $author->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function unliking_post_removes_authors_money()
    {
        $author = User::query()->findOrFail(2);
        $liker = User::query()->findOrFail(3);
        $post = Post::query()->findOrFail(1);
        $post->setRelation('user', $author);

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // Like it first (+2)
        $subscriber->postWasLiked(new MockPostWasLiked($post, $liker));
        $this->assertEquals(2.0, (float) $author->fresh()->money);

        // Unlike it (-2)
        $subscriber->postWasUnliked(new MockPostWasUnliked($post, $liker));

        $this->assertEquals(0.0, (float) $author->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function self_liking_gives_no_money_by_default()
    {
        $author = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(1);
        $post->setRelation('user', $author);

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // Author likes their own post
        $subscriber->postWasLiked(new MockPostWasLiked($post, $author));

        $this->assertEquals(0.0, (float) $author->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    #[Test]
    public function self_liking_gives_money_when_enabled()
    {
        $author = User::query()->findOrFail(2);
        $post = Post::query()->findOrFail(1);
        $post->setRelation('user', $author);

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);
        $reflection = new \ReflectionClass($subscriber);
        $property = $reflection->getProperty('rewardSelfLike');
        $property->setAccessible(true);
        $property->setValue($subscriber, true);

        // Author likes their own post
        $subscriber->postWasLiked(new MockPostWasLiked($post, $author));

        $this->assertEquals(2.0, (float) $author->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());

        // Author unlikes their own post
        $subscriber->postWasUnliked(new MockPostWasUnliked($post, $author));

        $this->assertEquals(0.0, (float) $author->fresh()->money);
        $this->assertSame(2, $this->connection()->table('user_money_history')->count());
    }

    private function connection(): ConnectionInterface
    {
        return $this->app()->getContainer()->make(ConnectionInterface::class);
    }
}
