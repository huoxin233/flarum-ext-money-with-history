<?php

/*
 * This file is part of huoxin/money-with-history.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\MoneyWithHistory;

use Flarum\Api\Serializer\UserSerializer;
use Flarum\Extend;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Api\Schema;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/u/{username}/money/history', 'huoxin-money-with-history.money-history'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ApiResource(\Flarum\Api\Resource\UserResource::class))
        ->fields(fn() => [
            Schema\Number::make('money')
                ->get(fn ($user) => $user->money),
            Schema\Boolean::make('canEditMoney')
                ->get(fn ($user, Context $context) => $context->getActor()->can('edit_money', $user)),
            Schema\Boolean::make('canQueryOthersMoneyHistory')
                ->get(fn ($user, Context $context) => $context->getActor()->can('money-history.queryOthersMoneyHistory')),
        ]),

    (new Extend\Settings())
        ->serializeToForum('huoxin-money-with-history.money_name', 'huoxin-money-with-history.money_name')
        ->serializeToForum('huoxin-money-with-history.hide_zero_balances', 'huoxin-money-with-history.hide_zero_balances'),

    (new Extend\Event())
        ->subscribe(Listeners\MoneyBalanceSubscriber::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-likes', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Likes\Event\PostWasLiked::class, Listeners\MoneyBalanceSubscriber::class . '@postWasLiked')
                ->listen(\Flarum\Likes\Event\PostWasUnliked::class, Listeners\MoneyBalanceSubscriber::class . '@postWasUnliked'),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Approval\Event\PostWasApproved::class, Listeners\MoneyBalanceSubscriber::class . '@postWasApproved'),
        ]),
        
    new Extend\ApiResource(Api\Resource\UserMoneyHistoryResource::class),

    (new Extend\SearchDriver(\Flarum\Search\Database\DatabaseSearchDriver::class))
        ->addSearcher(Model\UserMoneyHistory::class, Api\Search\UserMoneyHistorySearcher::class)
        ->addFilter(Api\Search\UserMoneyHistorySearcher::class, Api\Search\UserFilter::class),
];
