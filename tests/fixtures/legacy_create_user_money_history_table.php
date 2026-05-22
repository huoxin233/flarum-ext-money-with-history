<?php

/**
 * Fixture: creates the user_money_history table using the ORIGINAL legacy schema
 * (before any normalization migrations). Used only in MigrationCompatibilityTest
 * to verify the upgrade path from old mattoid/flarum-ext-money-history installations.
 */

use Illuminate\Database\Schema\Blueprint;
use Flarum\Database\Migration;

return Migration::createTable(
    'user_money_history',
    function (Blueprint $table) {
        $table->increments('id');
        $table->integer('user_id')->index();
        $table->char('type', 1);
        $table->double('money')->default(0);
        $table->string('source')->index();
        $table->string('source_desc');
        $table->double('balance_money');
        $table->double('last_money');
        $table->integer('create_user_id')->index();
        $table->dateTime('change_time')->index();
    }
);
