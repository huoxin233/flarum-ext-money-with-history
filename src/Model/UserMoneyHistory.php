<?php

namespace Huoxin\MoneyWithHistory\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

class UserMoneyHistory extends AbstractModel
{
    protected $table = 'user_money_history';

    protected $casts = [
        'source_params' => 'array',
    ];

    // Override Eloquent's default JSON serializer to prevent Unicode escaping (like \uXXXX).
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
