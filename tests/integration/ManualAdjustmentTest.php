<?php

namespace Huoxin\MoneyWithHistory\Tests\integration;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\Event\Saving;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

class ManualAdjustmentTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-flags');
        $this->extension('flarum-approval');
        $this->extension('flarum-tags');
        $this->extension('huoxin-money-with-history');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'superadmin', 'email' => 'superadmin@example.com', 'is_email_confirmed' => 1],
            ],
            // Admin user will have permissions automatically in Flarum tests if we log them in,
            // but we'll use a mock permission check to be perfectly clean.
        ]);

        $this->app();
    }

    /** @test */
    public function manual_admin_adjustment_saves_history()
    {
        $user = User::query()->findOrFail(2);

        // Ensure initial money is 0
        $this->assertEquals(0.0, (float) $user->money);

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // We simulate the admin editing the user's money to 500
        $eventData = [
            'attributes' => [
                'money' => 500.0
            ]
        ];

        // We use an admin actor so `assertCan('edit_money')` passes
        $admin = User::query()->findOrFail(1); // ID 1 is usually admin in tests, but let's just make the actor an admin mock.
        $admin->is_admin = true;

        $subscriber->userWillBeSaved(new Saving($user, $admin, $eventData));
        $user->save();

        $this->assertEquals(500.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());

        $history = $this->connection()->table('user_money_history')->first();
        $this->assertEquals('MANUAL_ADJUSTMENT', $history->source);
        $this->assertEquals(500.0, $history->balance_delta);
    }

    /** @test */
    public function manual_admin_reduction_saves_history()
    {
        $user = User::query()->findOrFail(2);

        // Give the user 100 to start
        $user->money = 100.0;
        $user->save();

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        // We simulate the admin editing the user's money down to 40
        $eventData = [
            'attributes' => [
                'money' => 40.0
            ]
        ];

        $admin = User::query()->findOrFail(1);
        $admin->is_admin = true;

        $subscriber->userWillBeSaved(new Saving($user, $admin, $eventData));
        $user->save();

        $this->assertEquals(40.0, (float) $user->fresh()->money);
        $this->assertSame(1, $this->connection()->table('user_money_history')->count());

        $history = $this->connection()->table('user_money_history')->first();
        $this->assertEquals('MANUAL_ADJUSTMENT', $history->source);
        $this->assertEquals(-60.0, $history->balance_delta);
    }

    /** @test */
    public function manual_adjustment_without_permission_throws_exception()
    {
        $this->expectException(\Flarum\User\Exception\PermissionDeniedException::class);

        $user = User::query()->findOrFail(2);

        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $eventData = [
            'attributes' => [
                'money' => 500.0
            ]
        ];

        // A normal user tries to edit money
        $hacker = clone $user;

        $subscriber->userWillBeSaved(new Saving($user, $hacker, $eventData));
    }

    /** @test */
    public function manual_adjustment_without_money_attribute_does_nothing()
    {
        $user = User::query()->findOrFail(2);
        
        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $eventData = [
            'attributes' => [
                'username' => 'new_username' // no money attribute
            ]
        ];

        $admin = User::query()->findOrFail(1);
        $admin->is_admin = true;

        $subscriber->userWillBeSaved(new Saving($user, $admin, $eventData));
        $user->save();

        $this->assertEquals(0.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    /** @test */
    public function manual_adjustment_with_same_amount_does_nothing()
    {
        $user = User::query()->findOrFail(2);
        $user->money = 100.0;
        $user->save();
        
        $subscriber = $this->app()->getContainer()->make(\Huoxin\MoneyWithHistory\Listeners\MoneyBalanceSubscriber::class);

        $eventData = [
            'attributes' => [
                'money' => 100.0 // same as current
            ]
        ];

        $admin = User::query()->findOrFail(1);
        $admin->is_admin = true;

        $subscriber->userWillBeSaved(new Saving($user, $admin, $eventData));
        $user->save();

        $this->assertEquals(100.0, (float) $user->fresh()->money);
        $this->assertSame(0, $this->connection()->table('user_money_history')->count());
    }

    private function connection(): ConnectionInterface
    {
        return $this->app()->getContainer()->make(ConnectionInterface::class);
    }
}
