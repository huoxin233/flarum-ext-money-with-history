<?php

namespace Huoxin\MoneyWithHistory\Api;

use Flarum\Api\Serializer\UserSerializer;
use Flarum\User\User;

class AddUserAttributes
{
    public function __invoke(UserSerializer $serializer, User $user): array
    {
        $actor = $serializer->getActor();

        return [
            'money' => $user->money,
            'canEditMoney' => $actor->can('edit_money', $user),
            'canQueryOthersMoneyHistory' => $actor->can('money-history.queryOthersMoneyHistory'),
        ];
    }
}
