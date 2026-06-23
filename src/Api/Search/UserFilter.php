<?php

namespace Huoxin\MoneyWithHistory\Api\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\Filter\FilterInterface;
use Flarum\Search\SearchState;

/**
 * @implements FilterInterface<DatabaseSearchState>
 */
class UserFilter implements FilterInterface
{
    public function getFilterKey(): string
    {
        return 'user';
    }

    public function filter(SearchState $state, string|array $filterValue, bool $negate): void
    {
        $actor = $state->getActor();

        $userIds = (array) $filterValue;

        foreach ($userIds as $userId) {
            if ((int) $userId !== (int) $actor->id) {
                $actor->assertCan('money-history.queryOthersMoneyHistory');
                break;
            }
        }

        if (count($userIds) === 1) {
            $state->getQuery()->where('user_id', $negate ? '!=' : '=', $userIds[0]);
        } else {
            if ($negate) {
                $state->getQuery()->whereNotIn('user_id', $userIds);
            } else {
                $state->getQuery()->whereIn('user_id', $userIds);
            }
        }
    }
}
