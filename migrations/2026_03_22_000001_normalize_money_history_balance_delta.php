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

        // Normalize: ensure C rows are positive, D rows are negative
        $connection->table('user_money_history')
            ->where('type', 'C')
            ->update(['balance_delta' => $connection->raw('ABS(balance_delta)')]);

        $connection->table('user_money_history')
            ->where('type', 'D')
            ->update(['balance_delta' => $connection->raw('-ABS(balance_delta)')]);

        $schema->table('user_money_history', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
