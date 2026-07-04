<?php

namespace Huoxin\MoneyWithHistory\Listeners;

use Flarum\Approval\Event\PostWasApproved;
use Huoxin\MoneyWithHistory\Support\PostContentHelper;

class ApprovalRewardListener
{
    public function __construct(protected MoneyBalanceSubscriber $subscriber)
    {
    }

    public function postWasApproved(PostWasApproved $event): void
    {
        $post = $event->post;

        if (! $this->subscriber->isPrivateDiscussionRewarded() && isset($post->discussion->is_private) && $post->discussion->is_private) {
            return;
        }

        $content = $this->subscriber->isExcludeMentionsFromLength() ? PostContentHelper::stripMentions($post->content) : $post->content;
        if (
            $post->number > 1
            && mb_strlen($content) >= $this->subscriber->getMinPostLength()
        ) {
            $this->subscriber->adjustPostAuthorBalance(
                $post->user,
                $this->subscriber->getPostRewardAmount(),
                $post,
                MoneyBalanceSubscriber::SOURCE_POST_WAS_POSTED,
                $this->subscriber->sourceKey('post-reward'),
                $event->actor
            );
        }

        if ($post->number === 1 && $post->discussion) {
            $this->subscriber->adjustDiscussionAuthorBalance(
                $post->discussion->user,
                $this->subscriber->getDiscussionRewardAmount(),
                $post->discussion,
                MoneyBalanceSubscriber::SOURCE_DISCUSSION_WAS_STARTED,
                $this->subscriber->sourceKey('discussion-reward'),
                $event->actor
            );
        }
    }
}
