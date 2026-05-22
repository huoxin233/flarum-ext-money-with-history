<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('user_money_history')) {
            return;
        }

        if ($schema->hasColumn('user_money_history', 'source_key')) {
            return;
        }

        $schema->table('user_money_history', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('source_key', 255)->nullable();
        });
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
