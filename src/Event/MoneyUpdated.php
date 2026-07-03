<?php

namespace Huoxin\MoneyWithHistory\Event;

use Flarum\User\User;

class MoneyUpdated
{
    public ?User $user;
    public float $balanceDelta;
    public string $source;
    public string $sourceKey;
    public array $sourceParams;
    public ?User $actor;
    public ?float $balanceBefore;
    public ?float $balanceAfter;

    public function __construct(
        ?User $user = null,
        float $balanceDelta = 0,
        string $source = '',
        string $sourceKey = '',
        array $sourceParams = [],
        ?User $actor = null,
        ?float $balanceBefore = null,
        ?float $balanceAfter = null
    ) {
        $this->user = $user;
        $this->balanceDelta = $balanceDelta;
        $this->source = $source;
        $this->sourceKey = $sourceKey;
        $this->sourceParams = $sourceParams;
        $this->actor = $actor;
        $this->balanceBefore = $balanceBefore;
        $this->balanceAfter = $balanceAfter;
    }
}
