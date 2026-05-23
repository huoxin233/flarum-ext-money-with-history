<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('user_money_history') || ! $schema->hasColumn('user_money_history', 'source_key')) {
            return;
        }

        $connection = $schema->getConnection();

        // Migrate source_key translation prefixes from old extensions to the new unified prefix
        $prefixMap = [
            'antoinefr-money.forum.history.' => 'huoxin-money-with-history.forum.money-history.',
            'mattoid-money-history.forum.history.' => 'huoxin-money-with-history.forum.money-history.',
        ];

        foreach ($prefixMap as $oldPrefix => $newPrefix) {
            $connection->table('user_money_history')
                ->where('source_key', 'LIKE', $oldPrefix.'%')
                ->update([
                    'source_key' => $connection->raw(
                        "CONCAT('".addslashes($newPrefix)."', SUBSTRING(source_key, ".(strlen($oldPrefix) + 1).'))'
                    ),
                ]);
        }

        // Migrate exact source_key values from deprecated mattoid-money-history-auto extension
        $exactMap = [
            'mattoid-money-history-auto.forum.post-was-posted' => 'huoxin-money-with-history.forum.money-history.post-reward',
            'mattoid-money-history-auto.forum.post-was-liked' => 'huoxin-money-with-history.forum.money-history.post-liked',
            'mattoid-money-history-auto.forum.post-was-unliked' => 'huoxin-money-with-history.forum.money-history.post-unliked',
            'mattoid-money-history-auto.forum.post-was-deleted' => 'huoxin-money-with-history.forum.money-history.post-deleted',
            'mattoid-money-history-auto.forum.discussion-was-started' => 'huoxin-money-with-history.forum.money-history.discussion-reward',
            'mattoid-money-history-auto.forum.discussion-was-deleted' => 'huoxin-money-with-history.forum.money-history.discussion-deleted',
            'mattoid-money-history-auto.forum.checkin-saved' => 'ziven-checkin.forum.money-history.checkin-reward',
            'mattoid-money-history-auto.forum.system-rewards' => 'huoxin-money-with-history.forum.money-history.manual-adjustment',
        ];

        foreach ($exactMap as $oldKey => $newKey) {
            $connection->table('user_money_history')
                ->where('source_key', $oldKey)
                ->update(['source_key' => $newKey]);
        }
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
