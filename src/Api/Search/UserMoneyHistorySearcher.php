<?php

namespace Huoxin\MoneyWithHistory\Api\Search;

use Flarum\Search\Database\AbstractSearcher;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Model\UserMoneyHistory;
use Illuminate\Database\Eloquent\Builder;

class UserMoneyHistorySearcher extends AbstractSearcher
{
    public function getQuery(User $actor): Builder
    {
        $query = UserMoneyHistory::query();

        if (! $actor->can('money-history.queryOthersMoneyHistory')) {
            $query->where('user_id', $actor->id);
        }

        return $query;
    }
}
