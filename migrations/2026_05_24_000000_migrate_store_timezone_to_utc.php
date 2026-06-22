<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();
        $table = $connection->getQueryGrammar()->wrapTable('user_money_history');

        $timezone = $connection->table('settings')
            ->where('key', 'huoxin-money-with-history.storeTimezone')
            ->value('value') ?: 'Asia/Shanghai';

        if ($timezone !== 'UTC' && $schema->hasTable('user_money_history')) {
            $minId = $connection->table('user_money_history')->whereNotNull('created_at')->min('id');
            $maxId = $connection->table('user_money_history')->whereNotNull('created_at')->max('id');

            if ($minId !== null) {
                $minId = (int) $minId;
                $maxId = (int) $maxId;

                $isMySQL = $connection->getDriverName() === 'mysql';
                $useConvertTz = false;

                if ($isMySQL) {
                    // Determine whether MySQL CONVERT_TZ is available
                    $test = $connection->selectOne(
                        "SELECT CONVERT_TZ('2026-01-01 12:00:00', ?, 'UTC') AS result",
                        [$timezone]
                    );
                    $useConvertTz = ($test !== null && $test->result !== null);
                }
                $offsetSeconds = 0;

                if (! $useConvertTz) {
                    try {
                        /** @var \Psr\Log\LoggerInterface $log */
                        $log = resolve(\Psr\Log\LoggerInterface::class);
                        $log->warning(
                            '[money-with-history] MySQL timezone tables not loaded. '
                            .'Using PHP-computed offset for migration.'
                        );
                    } catch (\Exception $e) {
                        // Logger may not be available during install
                    }

                    $offsetSeconds = (int) (new \DateTime(
                        'now',
                        new \DateTimeZone($timezone)
                    ))->getOffset();
                }

                // Batch updates to avoid long table locks on large datasets
                $batchSize = 50000;

                for ($start = $minId; $start <= $maxId; $start += $batchSize) {
                    $end = $start + $batchSize - 1;
                    $driver = $connection->getDriverName();

                    if ($useConvertTz && $driver === 'mysql') {
                        $connection->statement(
                            "UPDATE {$table} "
                            ."SET created_at = COALESCE(CONVERT_TZ(created_at, ?, 'UTC'), created_at) "
                            .'WHERE id BETWEEN ? AND ? AND created_at IS NOT NULL',
                            [$timezone, $start, $end]
                        );
                    } else {
                        if ($driver === 'pgsql') {
                            $sql = "UPDATE {$table} "
                                ."SET created_at = created_at - (INTERVAL '1 second' * CAST(? AS integer)) "
                                ."WHERE id BETWEEN ? AND ? AND created_at IS NOT NULL";
                        } elseif ($driver === 'sqlite') {
                            $sql = "UPDATE {$table} "
                                ."SET created_at = datetime(created_at, '-' || ? || ' seconds') "
                                ."WHERE id BETWEEN ? AND ? AND created_at IS NOT NULL";
                        } else {
                            $sql = "UPDATE {$table} "
                                ."SET created_at = DATE_SUB(created_at, INTERVAL ? SECOND) "
                                ."WHERE id BETWEEN ? AND ? AND created_at IS NOT NULL";
                        }

                        $connection->statement($sql, [$offsetSeconds, $start, $end]);
                    }
                }
            }
        }

        $connection->table('settings')
            ->where('key', 'huoxin-money-with-history.storeTimezone')
            ->delete();
    },

    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
