<?php

namespace Huoxin\MoneyWithHistory\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Huoxin\MoneyWithHistory\Model\UserMoneyHistory;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * @extends Resource\AbstractDatabaseResource<UserMoneyHistory>
 */
class UserMoneyHistoryResource extends Resource\AbstractDatabaseResource
{
    public function type(): string
    {
        return 'userMoneyHistory';
    }

    public function model(): string
    {
        return UserMoneyHistory::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        $actor = $context->getActor();

        $query->whereVisibleTo($actor);

        if (!$actor->can('money-history.queryOthersMoneyHistory')) {
            $query->where('user_id', $actor->id);
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->defaultInclude(['actor'])
                ->paginate(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Number::make('balanceDelta')
                ->property('balance_delta'),
            Schema\Str::make('source'),
            Schema\Str::make('sourceKey')
                ->property('source_key'),
            Schema\Arr::make('sourceParams')
                ->property('source_params'),
            Schema\Number::make('balanceBefore')
                ->property('balance_before'),
            Schema\Number::make('balanceAfter')
                ->property('balance_after'),
            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable()
                ->filterable(),

            Schema\Relationship\ToOne::make('actor')
                ->includable()
                ->type('users'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt')
                ->property('created_at'),
            SortColumn::make('id'),
        ];
    }
}
