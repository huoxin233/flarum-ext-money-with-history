<?php

namespace Huoxin\MoneyWithHistory\Model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserMoneyHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => 0,
            'balance_delta' => 0,
            'source' => $this->faker->word,
            'source_key' => null,
            'source_params' => null,
            'balance_before' => 0,
            'balance_after' => 0,
            'actor_id' => 0,
            'created_at' => Carbon::now(),
        ];
    }
}
