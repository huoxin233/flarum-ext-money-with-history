<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('user_money_history') || ! $schema->hasColumn('user_money_history', 'source_key')) {
            return;
        }

        $connection = $schema->getConnection();
        $table = $connection->getQueryGrammar()->wrapTable('user_money_history');

        // Migrate source_key translation prefixes from old extensions to the new unified prefix
        $prefixMap = [
            'antoinefr-money.forum.history.' => 'huoxin-money-with-history.forum.money-history.',
            'mattoid-money-history.forum.history.' => 'huoxin-money-with-history.forum.money-history.',
        ];

        $minId = $connection->table('user_money_history')->min('id');
        $maxId = $connection->table('user_money_history')->max('id');

        if ($minId !== null) {
            $minId = (int) $minId;
            $maxId = (int) $maxId;
            $batchSize = 50000;

            for ($start = $minId; $start <= $maxId; $start += $batchSize) {
                $end = $start + $batchSize - 1;

                foreach ($prefixMap as $oldPrefix => $newPrefix) {
                    $driver = $connection->getDriverName();
                    
                    if ($driver === 'sqlite') {
                        $sql = "UPDATE {$table} "
                            ."SET source_key = ? || SUBSTR(source_key, ?) "
                            ."WHERE source_key LIKE ? AND id BETWEEN ? AND ?";
                    } elseif ($driver === 'pgsql') {
                        $sql = "UPDATE {$table} "
                            ."SET source_key = CONCAT(CAST(? AS text), SUBSTRING(source_key, CAST(? AS integer))) "
                            ."WHERE source_key LIKE ? AND id BETWEEN ? AND ?";
                    } else {
                        $sql = "UPDATE {$table} "
                            ."SET source_key = CONCAT(?, SUBSTRING(source_key, ?)) "
                            ."WHERE source_key LIKE ? AND id BETWEEN ? AND ?";
                    }

                    $connection->statement(
                        $sql,
                        [$newPrefix, strlen($oldPrefix) + 1, $oldPrefix.'%', $start, $end]
                    );
                }
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

            for ($start = $minId; $start <= $maxId; $start += $batchSize) {
                $end = $start + $batchSize - 1;

                foreach ($exactMap as $oldKey => $newKey) {
                    $connection->statement(
                        "UPDATE {$table} SET source_key = ? "
                        .'WHERE source_key = ? AND id BETWEEN ? AND ?',
                        [$newKey, $oldKey, $start, $end]
                    );
                }
            }
        }
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
