<?php

namespace Huoxin\MoneyWithHistory\Tests\integration;

use Exception;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Service\BalanceManager;
use Huoxin\MoneyWithHistory\Service\HistoryWriter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class AtomicityTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('huoxin-money-with-history');

        $this->prepareDatabase([
            User::class => [
                ['id' => 10, 'username' => 'testuser', 'email' => 'test@example.com', 'is_email_confirmed' => 1, 'money' => 50.0],
            ],
        ]);
        
        $this->app();
    }

    #[Test]
    public function adjust_balances_by_user_ids_rolls_back_if_history_fails()
    {
        $user = User::query()->find(10);
        
        $mockHistory = Mockery::mock(HistoryWriter::class);
        $mockHistory->shouldReceive('writeMany')->andThrow(new Exception('Simulated crash!'));
        $this->app()->getContainer()->instance(HistoryWriter::class, $mockHistory);

        /** @var BalanceManager $balances */
        $balances = $this->app()->getContainer()->make(BalanceManager::class);

        try {
            $balances->adjustBalancesByUserIds([10 => 100.0], 'TEST', 'test-key');
        } catch (Exception $e) {}

        $user->refresh();
        $this->assertEquals(50.0, (float) $user->money);
    }

    #[Test]
    public function single_adjust_balance_rolls_back_if_history_fails()
    {
        $user = User::query()->find(10);
        
        $mockHistory = Mockery::mock(HistoryWriter::class);
        $mockHistory->shouldReceive('writeMany')->andThrow(new Exception('Simulated crash!'));
        $this->app()->getContainer()->instance(HistoryWriter::class, $mockHistory);

        /** @var BalanceManager $balances */
        $balances = $this->app()->getContainer()->make(BalanceManager::class);

        try {
            $balances->adjustBalance(10, 100.0, 'TEST', 'test-key');
        } catch (Exception $e) {}

        $user->refresh();
        $this->assertEquals(50.0, (float) $user->money);
    }

    #[Test]
    public function transfer_balance_rolls_back_both_sender_and_receiver_if_history_fails()
    {
        // Give sender some money first without breaking
        User::query()->where('id', 1)->update(['money' => 1000.0]); // User 1 is the sender
        $sender = User::query()->find(1);
        $receiver = User::query()->find(10); // User 10 has 50.0
        
        $mockHistory = Mockery::mock(HistoryWriter::class);
        $mockHistory->shouldReceive('write')->andThrow(new Exception('Simulated crash!'));
        $mockHistory->shouldReceive('writeMany')->andThrow(new Exception('Simulated crash!'));
        $this->app()->getContainer()->instance(HistoryWriter::class, $mockHistory);

        /** @var BalanceManager $balances */
        $balances = $this->app()->getContainer()->make(BalanceManager::class);

        try {
            // Transfer 500 from User 1 to User 10
            $balances->transferBalance(1, 10, 500.0, 'TRANSFER', 'transfer-key');
        } catch (Exception $e) {}

        $sender->refresh();
        $receiver->refresh();

        // Must completely roll back for both users!
        $this->assertEquals(1000.0, (float) $sender->money, 'Sender was deducted but transfer rolled back!');
        $this->assertEquals(50.0, (float) $receiver->money, 'Receiver got money but transfer rolled back!');
    }
}
