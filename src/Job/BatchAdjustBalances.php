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

        $balances->adjustBalancesByUserIds($this->userDeltas, $this->source, $this->sourceKey, [], $actor);
    }
}
