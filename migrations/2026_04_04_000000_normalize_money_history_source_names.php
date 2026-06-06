<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('user_money_history') || ! $schema->hasColumn('user_money_history', 'source')) {
            return;
        }

        $connection = $schema->getConnection();
        $prefix = $connection->getTablePrefix();
        $table = $prefix . 'user_money_history';

        $sourceMap = [
            'POSTWASPOSTED' => 'POST_POSTED',
            'POSTWASRESTORED' => 'POST_RESTORED',
            'POSTWASHIDDEN' => 'POST_HIDDEN',
            'POSTWASDELETED' => 'POST_DELETED',
            'DISCUSSIONWASSTARTED' => 'DISCUSSION_STARTED',
            'DISCUSSIONWASRESTORED' => 'DISCUSSION_RESTORED',
            'DISCUSSIONWASHIDDEN' => 'DISCUSSION_HIDDEN',
            'DISCUSSIONWASDELETED' => 'DISCUSSION_DELETED',
            'USERWILLBESAVED' => 'MANUAL_ADJUSTMENT',
            'POSTWASLIKED' => 'POST_LIKED',
            'POSTWASUNLIKED' => 'POST_UNLIKED',
            'MONEYREWARDS' => 'POST_TIP_REWARD',
            'MONEYTOALL' => 'MONEY_TO_ALL',
            'CHECKINSAVED' => 'DAILY_CHECKIN_REWARD',
            'STOREBUYGOODS' => 'STORE_BUY_GOODS',
            'STOREBUYGOODSFAIL' => 'STORE_BUY_GOODS_FAIL',
            'AUTODEDUCTION' => 'STORE_AUTO_DEDUCTION',
            'CONFIRMINVITE' => 'STORE_CONFIRM_INVITE',
        ];

        $minId = $connection->table('user_money_history')->min('id');
        $maxId = $connection->table('user_money_history')->max('id');

        if ($minId !== null) {
            $minId = (int) $minId;
            $maxId = (int) $maxId;
            $batchSize = 50000;

            for ($start = $minId; $start <= $maxId; $start += $batchSize) {
                $end = $start + $batchSize - 1;

                foreach ($sourceMap as $legacySource => $normalizedSource) {
                    $connection->statement(
                        "UPDATE `{$table}` SET source = ? "
                        . 'WHERE source = ? AND id BETWEEN ? AND ?',
                        [$normalizedSource, $legacySource, $start, $end]
                    );
                }
            }
        }
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    },
];
