<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('user_money_history')) {
            return;
        }

        if (! $schema->hasColumn('user_money_history', 'balance_delta') || ! $schema->hasColumn('user_money_history', 'type')) {
            return;
        }

        $connection = $schema->getConnection();
        $table = $connection->getQueryGrammar()->wrapTable('user_money_history');

        $minId = $connection->table('user_money_history')->min('id');
        $maxId = $connection->table('user_money_history')->max('id');

        if ($minId !== null) {
            $minId = (int) $minId;
            $maxId = (int) $maxId;
            $batchSize = 50000;

            // Normalize: ensure C rows are positive, D rows are negative
            for ($start = $minId; $start <= $maxId; $start += $batchSize) {
                $end = $start + $batchSize - 1;

                $connection->statement(
                    "UPDATE {$table} SET balance_delta = ABS(balance_delta) "
                    .'WHERE type = ? AND id BETWEEN ? AND ?',
                    ['C', $start, $end]
                );

                $connection->statement(
                    "UPDATE {$table} SET balance_delta = -ABS(balance_delta) "
                    .'WHERE type = ? AND id BETWEEN ? AND ?',
                    ['D', $start, $end]
                );
            }
        }

        $schema->table('user_money_history', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
