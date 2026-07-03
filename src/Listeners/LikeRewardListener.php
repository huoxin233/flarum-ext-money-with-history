<?php

namespace Huoxin\MoneyWithHistory\Listeners;

use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;

class LikeRewardListener
{
    public function __construct(protected MoneyBalanceSubscriber $subscriber)
    {
    }

    public function postWasLiked(PostWasLiked $event): void
    {
        if ($event->post->user === null) {
            return;
        }

        if (! $this->subscriber->rewardSelfLike && $event->post->user->id === $event->user->id) {
            return;
        }

        if (! $this->subscriber->rewardPrivateDiscussion && isset($event->post->discussion->is_private) && $event->post->discussion->is_private) {
            return;
        }

        $this->subscriber->adjustPostAuthorBalance(
            $event->post->user,
            $this->subscriber->likeRewardAmount,
            $event->post,
            MoneyBalanceSubscriber::SOURCE_POST_WAS_LIKED,
            $this->subscriber->sourceKey('post-liked'),
            $event->user
        );
    }

    public function postWasUnliked(PostWasUnliked $event): void
    {
        if ($event->post->user === null) {
            return;
        }

        if (! $this->subscriber->rewardSelfLike && $event->post->user->id === $event->user->id) {
            return;
        }

        if (! $this->subscriber->rewardPrivateDiscussion && isset($event->post->discussion->is_private) && $event->post->discussion->is_private) {
            return;
        }

        $this->subscriber->adjustPostAuthorBalance(
            $event->post->user,
            -1 * $this->subscriber->likeRewardAmount,
            $event->post,
            MoneyBalanceSubscriber::SOURCE_POST_WAS_UNLIKED,
            $this->subscriber->sourceKey('post-unliked'),
            $event->user
        );
    }
}
