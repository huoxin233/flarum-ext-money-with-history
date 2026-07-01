<?php

namespace Huoxin\MoneyWithHistory\Job;

use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Service\BalanceManager;

class BatchAdjustBalances extends AbstractJob
{
    /**
     * BEST PRACTICE: To prevent lock wait timeouts and memory exhaustion,
     * DO NOT pass more than 500 users into this job. If you have 10,000 users, 
     * use array_chunk and dispatch 20 separate jobs. This guarantees atomicity 
     * and prevents double-spending if a job fails and retries.
     * 
     * @param array<int, float> $userDeltas
     */
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

        $balances->adjustBalancesByUserIds($this->userDeltas, $this->source, $this->sourceKey, [], $actor);
    }
}
