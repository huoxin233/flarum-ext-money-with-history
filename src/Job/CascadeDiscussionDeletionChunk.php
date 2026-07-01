<?php

namespace Huoxin\MoneyWithHistory\Job;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Service\BalanceManager;
use Huoxin\MoneyWithHistory\Support\PostContentHelper;

class CascadeDiscussionDeletionChunk extends AbstractJob
{
    public function __construct(
        private array $postsData,
        private int $multiply,
        private string $source,
        private string $sourceKey,
        private array $tagIds,
        private ?int $actorId = null
    ) {
    }

    public function handle(SettingsRepositoryInterface $settings, BalanceManager $balances): void
    {
        if (empty($this->postsData)) {
            return;
        }

        $actor = $this->actorId ? User::find($this->actorId) : null;

        $postRewardAmount = (float) $settings->get('huoxin-money-with-history.post_reward_amount', 0);
        $minPostLength = (int) $settings->get('huoxin-money-with-history.min_post_length', 0);
        $excludeMentionsSetting = (bool) $settings->get('huoxin-money-with-history.exclude_mentions_from_length', false);

        // Fetch all unique users for this chunk
        $userIds = array_unique(array_filter(array_column($this->postsData, 'user_id')));
        if (empty($userIds)) {
            return;
        }

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');
        $userDeltas = [];

        foreach ($this->postsData as $post) {
            $userId = $post['user_id'] ?? null;
            if (! $userId || ! isset($users[$userId])) {
                continue;
            }

            $user = $users[$userId];
            $content = $excludeMentionsSetting ? PostContentHelper::stripMentions($post['content'] ?? '') : ($post['content'] ?? '');

            if (
                mb_strlen($content) >= $minPostLength
                && ($post['number'] ?? 0) > 1
                && is_null($post['hidden_at'] ?? null)
            ) {
                $permissions = true;

                foreach ($this->tagIds as $tagId) {
                    if ($user->hasPermission("tag{$tagId}.discussion.money.disable_money") && ! $user->isAdmin()) {
                        $permissions = false;
                        break;
                    }
                }

                if ($permissions) {
                    if (! isset($userDeltas[$userId])) {
                        $userDeltas[$userId] = 0.0;
                    }
                    $userDeltas[$userId] += ($this->multiply * $postRewardAmount);
                }
            }
        }

        $balances->adjustBalancesByUserIds($userDeltas, $this->source, $this->sourceKey, [], $actor);
    }
}
