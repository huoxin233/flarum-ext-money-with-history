<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();
        $timezone = $connection->table('settings')->where('key', 'huoxin-money-with-history.storeTimezone')->value('value') ?: 'Asia/Shanghai';

        if ($timezone !== 'UTC') {
            $connection->table('user_money_history')->orderBy('id')->chunk(500, function ($rows) use ($connection, $timezone) {
                foreach ($rows as $row) {
                    if ($row->created_at) {
                        try {
                            $date = Carbon::createFromFormat('Y-m-d H:i:s', $row->created_at, $timezone);
                            $connection->table('user_money_history')->where('id', $row->id)->update([
                                'created_at' => $date->setTimezone('UTC')->format('Y-m-d H:i:s')
                            ]);
                        } catch (\Exception $e) {
                        }
                    }
                }
            });
        }

        $connection->table('settings')->where('key', 'huoxin-money-with-history.storeTimezone')->delete();
    },
    'down' => function (Builder $schema) {
        // Not doing anything but `down` has to be defined
    }
];
