<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();

        // Migrate settings from antoinefr-money.* to huoxin-money-with-history.*
        $settingsMap = [
            'antoinefr-money.moneyname' => 'huoxin-money-with-history.moneyname',
            'antoinefr-money.moneyforpost' => 'huoxin-money-with-history.moneyforpost',
            'antoinefr-money.postminimumlength' => 'huoxin-money-with-history.postminimumlength',
            'antoinefr-money.moneyfordiscussion' => 'huoxin-money-with-history.moneyfordiscussion',
            'antoinefr-money.moneyforlike' => 'huoxin-money-with-history.moneyforlike',
            'antoinefr-money.autoremove' => 'huoxin-money-with-history.autoremove',
            'antoinefr-money.cascaderemove' => 'huoxin-money-with-history.cascaderemove',
            'antoinefr-money.ignorenotifyingusers' => 'huoxin-money-with-history.ignorenotifyingusers',
            'antoinefr-money.noshowzero' => 'huoxin-money-with-history.noshowzero',
            'money-history.storeTimezone' => 'huoxin-money-with-history.storeTimezone',
        ];

        foreach ($settingsMap as $oldKey => $newKey) {
            $existing = $connection->table('settings')->where('key', $oldKey)->first();

            if ($existing === null) {
                continue;
            }

            // Only copy if the new key doesn't already exist
            $newExists = $connection->table('settings')->where('key', $newKey)->exists();

            if (! $newExists) {
                $connection->table('settings')->insert([
                    'key' => $newKey,
                    'value' => $existing->value,
                ]);
            }
        }
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
