<?php

namespace Huoxin\MoneyWithHistory\Event;

use Flarum\User\User;

class MoneyUpdated
{
    public function __construct(public ?User $user = null, public float $balanceDelta = 0, public string $source = '', public string $sourceKey = '', public array $sourceParams = [], public ?User $actor = null, public ?float $balanceBefore = null, public ?float $balanceAfter = null)
    {
    }
}
