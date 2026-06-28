<?php

namespace Huoxin\MoneyWithHistory\Job;

use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Service\BalanceManager;

class BatchAdjustBalances extends AbstractJob
{
    public function __construct(
        private array $userDeltas,
        private string $source,
        private string $sourceKey,
        private ?int $actorId = null
    ) {
    }

    public function handle(BalanceManager $balances): void
    {
        if (empty($this->userDeltas)) {
            return;
        }

        $actor = $this->actorId ? User::find($this->actorId) : null;
        
        $userIds = array_keys($this->userDeltas);

        foreach (array_chunk($userIds, 500) as $chunkedIds) {
            $usersById = User::whereIn('id', $chunkedIds)->get()->keyBy('id');
            $usersByDelta = [];

            foreach ($chunkedIds as $id) {
                if (! isset($usersById[$id])) {
                    continue;
                }

                $delta = $this->userDeltas[$id];
                $deltaString = (string) $delta;
                
                if (! isset($usersByDelta[$deltaString])) {
                    $usersByDelta[$deltaString] = [
                        'delta' => $delta,
                        'users' => []
                    ];
                }
                $usersByDelta[$deltaString]['users'][] = $usersById[$id];
            }

            foreach ($usersByDelta as $group) {
                $balances->adjustBalances($group['users'], $group['delta'], $this->source, $this->sourceKey, [], $actor);
            }
        }
    }
}
