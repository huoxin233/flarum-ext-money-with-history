<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('user_money_history')) {
            return;
        }

        $schema->create('user_money_history', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->double('balance_delta')->default(0);
            $table->string('source')->index();
            $table->string('source_key', 255)->nullable();
            $table->text('source_params')->nullable();
            $table->double('balance_before');
            $table->double('balance_after');
            $table->integer('actor_id')->index();
            $table->dateTime('created_at')->index();
        });
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
