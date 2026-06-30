<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'money')) {
            return;
        }

        $schema->table('users', function (Blueprint $table) {
            $table->integer('money')->default(0);
        });
    },
    'down' => function (Builder $schema) {
        // Not dropping — column may be in use by other extensions
    },
];
