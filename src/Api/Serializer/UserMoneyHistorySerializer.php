<?php

namespace Huoxin\MoneyWithHistory\Api\Serializer;

use Carbon\Carbon;
use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Api\Serializer\BasicUserSerializer;

class UserMoneyHistorySerializer extends AbstractSerializer
{
    protected $type = 'userMoneyHistory';

    /**
     * @param \Huoxin\MoneyWithHistory\Model\UserMoneyHistory $data
     */
    protected function getDefaultAttributes($data)
    {
        return [
            'balance_delta' => $data->balance_delta,
            'user_id' => $data->user_id,
            'source' => $data->source,
            'source_key' => $data->source_key,
            'source_params' => $data->source_params,
            'balance_after' => $data->balance_after,
            'balance_before' => $data->balance_before,
            'created_at' => $this->formatDate($data->created_at ? Carbon::parse($data->created_at) : null),
        ];
    }

    protected function actor($moneyHistory)
    {
        return $this->hasOne($moneyHistory, BasicUserSerializer::class);
    }
}
