<?php

namespace Huoxin\MoneyWithHistory\Tests\integration;

use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class MockPostWasLiked {
    public function __construct(public Post $post, public User $user) {}
}

class MockPostWasUnliked {
    public function __construct(public Post $post, public User $user) {}
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
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'liker', 'email' => 'liker@example.com', 'is_email_confirmed' => 1],
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Public Discussion', 'user_id' => 2, 'is_approved' => 1, 'comment_count' => 1, 'is_private' => 0],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => 'First post', 'is_approved' => 1, 'number' => 1],
            ]
        ]);

        $this->setting('huoxin-money-with-history.moneyforlike', 2); // 2 per like

        $this->app();
    }

    /** @test */
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

    /** @test */
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

    private function connection(): ConnectionInterface
    {
        return $this->app()->getContainer()->make(ConnectionInterface::class);
    }
}
