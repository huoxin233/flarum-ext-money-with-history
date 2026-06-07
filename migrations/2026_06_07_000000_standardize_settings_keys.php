<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();

        $map = [
            'huoxin-money-with-history.moneyforpost' => 'huoxin-money-with-history.post_reward_amount',
            'huoxin-money-with-history.moneyfordiscussion' => 'huoxin-money-with-history.discussion_reward_amount',
            'huoxin-money-with-history.moneyforlike' => 'huoxin-money-with-history.like_reward_amount',
            'huoxin-money-with-history.moneyname' => 'huoxin-money-with-history.money_name',
            'huoxin-money-with-history.postminimumlength' => 'huoxin-money-with-history.min_post_length',
            'huoxin-money-with-history.autoremove' => 'huoxin-money-with-history.remove_money_trigger',
            'huoxin-money-with-history.cascaderemove' => 'huoxin-money-with-history.cascade_money_removal',
            'huoxin-money-with-history.ignorenotifyingusers' => 'huoxin-money-with-history.exclude_mentions_from_length',
            'huoxin-money-with-history.noshowzero' => 'huoxin-money-with-history.hide_zero_balances',
            'huoxin-money-with-history.rewardPrivateDiscussion' => 'huoxin-money-with-history.reward_private_discussion',
            'huoxin-money-with-history.rewardSelfLike' => 'huoxin-money-with-history.reward_self_like',
        ];

        foreach ($map as $old => $new) {
            $existing = $connection->table('settings')->where('key', $old)->first();

            if ($existing !== null) {
                $newExists = $connection->table('settings')->where('key', $new)->exists();

                if ($newExists) {
                    $connection->table('settings')->where('key', $old)->delete();
                } else {
                    $connection->table('settings')->where('key', $old)->update(['key' => $new]);
                }
            }
        }
    },

    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    }
];
