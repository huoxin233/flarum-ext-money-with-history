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

use Flarum\Extend;
use Flarum\Api\Serializer\UserSerializer;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/u/{username}/money/history', 'huoxin-money-with-history.money-history'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(Api\AddUserAttributes::class),

    (new Extend\Settings())
        ->serializeToForum('huoxin-money-with-history.moneyname', 'huoxin-money-with-history.moneyname')
        ->serializeToForum('huoxin-money-with-history.noshowzero', 'huoxin-money-with-history.noshowzero'),

    (new Extend\Event())
        ->subscribe(Listeners\MoneyBalanceSubscriber::class),

    (new Extend\Routes('api'))
        ->get('/users/{id}/money/history', 'user.money.history', Api\Controller\ListUserMoneyHistoryController::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-likes', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Likes\Event\PostWasLiked::class, [Listeners\MoneyBalanceSubscriber::class, 'postWasLiked'])
                ->listen(\Flarum\Likes\Event\PostWasUnliked::class, [Listeners\MoneyBalanceSubscriber::class, 'postWasUnliked']),
        ]),
];
