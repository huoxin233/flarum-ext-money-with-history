<?php

namespace Huoxin\MoneyWithHistory\Job;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Service\BalanceManager;

class CascadeDiscussionPostsChunk extends AbstractJob
{
    public function __construct(
        private array $userPostCounts,
        private int $multiply,
        private string $source,
        private string $sourceKey,
        private array $tagIds,
        private ?int $actorId = null
    ) {
    }

    public function handle(SettingsRepositoryInterface $settings, BalanceManager $balances): void
    {
        if (empty($this->userPostCounts)) {
            return;
        }

        $actor = $this->actorId ? User::find($this->actorId) : null;
        $postRewardAmount = (float) $settings->get('huoxin-money-with-history.post_reward_amount', 0);

        // Fetch all unique users for this chunk
        $userIds = array_keys($this->userPostCounts);
        if (empty($userIds)) {
            return;
        }

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');
        $userDeltas = [];

        foreach ($this->userPostCounts as $userId => $count) {
            if (! isset($users[$userId])) {
                continue;
            }

            $user = $users[$userId];
            $permissions = true;

            foreach ($this->tagIds as $tagId) {
                if ($user->hasPermission("tag{$tagId}.discussion.money.disable_money") && ! $user->isAdmin()) {
                    $permissions = false;
                    break;
                }
            }

            if ($permissions) {
                $userDeltas[$userId] = ($this->multiply * $postRewardAmount) * $count;
            }
        }

        $balances->adjustBalancesByUserIds($userDeltas, $this->source, $this->sourceKey, [], $actor);
    }
}
