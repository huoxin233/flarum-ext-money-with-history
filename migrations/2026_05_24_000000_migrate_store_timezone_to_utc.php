<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();

        $timezone = $connection->table('settings')
            ->where('key', 'huoxin-money-with-history.storeTimezone')
            ->value('value') ?: 'Asia/Shanghai';

        if ($timezone !== 'UTC') {
            // Pre-flight check: Ensure MySQL has timezone tables loaded
            $test = $connection->selectOne("SELECT CONVERT_TZ('2026-01-01 12:00:00', ?, 'UTC') as result", [$timezone]);
            if ($test === null || $test->result === null) {
                throw new \RuntimeException("MySQL timezone tables are missing! CONVERT_TZ returned NULL. Please run 'mysql_tzinfo_to_sql' on your database server to populate timezones before running this migration.");
            }

            $connection->statement("
                UPDATE user_money_history
                SET created_at = COALESCE(CONVERT_TZ(created_at, ?, 'UTC'), created_at)
                WHERE created_at IS NOT NULL
            ", [$timezone]);
        }

        $connection->table('settings')
            ->where('key', 'huoxin-money-with-history.storeTimezone')
            ->delete();
    },

    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    }
];
