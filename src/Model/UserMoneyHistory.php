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

    // Override Eloquent's default JSON serializer to prevent Unicode escaping.
    protected function asJson($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function actor()
    {
        return $this->hasOne(User::class, 'id', 'actor_id');
    }
}
