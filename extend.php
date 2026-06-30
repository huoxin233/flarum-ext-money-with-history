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

use Flarum\Api\Context;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Extend;
use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use Flarum\Search\Database\DatabaseSearchDriver;
use Huoxin\MoneyWithHistory\Api\Resource\UserMoneyHistoryResource;
use Huoxin\MoneyWithHistory\Api\Search\UserFilter;
use Huoxin\MoneyWithHistory\Api\Search\UserMoneyHistorySearcher;
use Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber;
use Huoxin\MoneyWithHistory\Model\UserMoneyHistory;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/u/{username}/money/history', 'huoxin-money-with-history.money-history'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Number::make('money')
                ->property('money'),
            Schema\Boolean::make('canEditMoney')
                ->get(fn ($user, Context $context) => $context->getActor()->can('edit_money', $user)),
            Schema\Boolean::make('canQueryOthersMoneyHistory')
                ->get(fn ($user, Context $context) => $context->getActor()->can('money-history.queryOthersMoneyHistory')),
        ]),

    (new Extend\Settings())
        ->serializeToForum('huoxin-money-with-history.money_name', 'huoxin-money-with-history.money_name')
        ->serializeToForum('huoxin-money-with-history.hide_zero_balances', 'huoxin-money-with-history.hide_zero_balances', 'boolval', false)
        ->default('huoxin-money-with-history.money_name', '[money]')
        ->default('huoxin-money-with-history.hide_zero_balances', false)
        ->default('huoxin-money-with-history.post_reward_amount', 0)
        ->default('huoxin-money-with-history.min_post_length', 0)
        ->default('huoxin-money-with-history.discussion_reward_amount', 0)
        ->default('huoxin-money-with-history.like_reward_amount', 0)
        ->default('huoxin-money-with-history.remove_money_trigger', 1)
        ->default('huoxin-money-with-history.cascade_money_removal', false)
        ->default('huoxin-money-with-history.exclude_mentions_from_length', false)
        ->default('huoxin-money-with-history.reward_private_discussion', false)
        ->default('huoxin-money-with-history.reward_self_like', false),

    (new Extend\Event())
        ->subscribe(MoneyBalanceSubscriber::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-likes', fn () => [
            (new Extend\Event())
                ->listen(PostWasLiked::class, MoneyBalanceSubscriber::class.'@postWasLiked')
                ->listen(PostWasUnliked::class, MoneyBalanceSubscriber::class.'@postWasUnliked'),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\Event())
                ->listen(PostWasApproved::class, MoneyBalanceSubscriber::class.'@postWasApproved'),
        ]),

    new Extend\ApiResource(UserMoneyHistoryResource::class),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(UserMoneyHistory::class, UserMoneyHistorySearcher::class)
        ->addFilter(UserMoneyHistorySearcher::class, UserFilter::class),
];
