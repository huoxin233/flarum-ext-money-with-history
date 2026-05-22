<?php

namespace Huoxin\MoneyWithHistory\Tests\integration;

use Flarum\Testing\integration\TestCase;
use Illuminate\Database\ConnectionInterface;

class SourceKeyPrefixMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } catch (\Exception $e) {
            // DDL auto-commits the MySQL transaction,
            // so parent's rollBack() may throw here.
        }
    }

    /** @test */
    public function it_migrates_antoinefr_source_key_prefixes_to_new_prefix(): void
    {
        $connection = $this->app()->getContainer()->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();

        // Ensure table exists with source_key column
        if (! $schema->hasTable('user_money_history')) {
            $create = require __DIR__.'/../../migrations/2023_11_08_000000_create_user_money_history_table.php';
            $create['up']($schema);
        }

        // Insert rows with old antoinefr-money prefix
        $connection->table('user_money_history')->insert([
            [
                'user_id' => 1,
                'balance_delta' => 5.0,
                'source' => 'POST_POSTED',
                'source_key' => 'antoinefr-money.forum.history.post-reward',
                'source_params' => null,
                'balance_before' => 0,
                'balance_after' => 5.0,
                'actor_id' => 1,
                'created_at' => '2026-05-01 10:00:00',
            ],
            [
                'user_id' => 1,
                'balance_delta' => -3.0,
                'source' => 'POST_HIDDEN',
                'source_key' => 'antoinefr-money.forum.history.post-hidden',
                'source_params' => null,
                'balance_before' => 5.0,
                'balance_after' => 2.0,
                'actor_id' => 2,
                'created_at' => '2026-05-01 11:00:00',
            ],
        ]);

        // Run the migration
        $migration = require __DIR__.'/../../migrations/2026_05_21_000001_normalize_source_key_prefixes.php';
        $migration['up']($schema);

        $rows = $connection->table('user_money_history')
            ->where('user_id', 1)
            ->orderBy('id')
            ->get();

        $this->assertSame('huoxin-money-with-history.forum.history.post-reward', $rows[0]->source_key);
        $this->assertSame('huoxin-money-with-history.forum.history.post-hidden', $rows[1]->source_key);
    }

    /** @test */
    public function it_migrates_mattoid_money_history_source_key_prefixes(): void
    {
        $connection = $this->app()->getContainer()->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('user_money_history')) {
            $create = require __DIR__.'/../../migrations/2023_11_08_000000_create_user_money_history_table.php';
            $create['up']($schema);
        }

        $connection->table('user_money_history')->insert([
            'user_id' => 2,
            'balance_delta' => 10.0,
            'source' => 'DISCUSSION_STARTED',
            'source_key' => 'mattoid-money-history.forum.history.discussion-reward',
            'source_params' => null,
            'balance_before' => 0,
            'balance_after' => 10.0,
            'actor_id' => 2,
            'created_at' => '2026-05-01 12:00:00',
        ]);

        $migration = require __DIR__.'/../../migrations/2026_05_21_000001_normalize_source_key_prefixes.php';
        $migration['up']($schema);

        $row = $connection->table('user_money_history')->where('user_id', 2)->first();

        $this->assertSame('huoxin-money-with-history.forum.history.discussion-reward', $row->source_key);
    }

    /** @test */
    public function it_migrates_deprecated_money_auto_exact_source_keys(): void
    {
        $connection = $this->app()->getContainer()->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('user_money_history')) {
            $create = require __DIR__.'/../../migrations/2023_11_08_000000_create_user_money_history_table.php';
            $create['up']($schema);
        }

        $connection->table('user_money_history')->insert([
            [
                'user_id' => 3,
                'balance_delta' => 5.0,
                'source' => 'POST_POSTED',
                'source_key' => 'mattoid-money-history-auto.forum.post-was-posted',
                'source_params' => null,
                'balance_before' => 0,
                'balance_after' => 5.0,
                'actor_id' => 3,
                'created_at' => '2026-05-01 13:00:00',
            ],
            [
                'user_id' => 3,
                'balance_delta' => 2.0,
                'source' => 'POST_LIKED',
                'source_key' => 'mattoid-money-history-auto.forum.post-was-liked',
                'source_params' => null,
                'balance_before' => 5.0,
                'balance_after' => 7.0,
                'actor_id' => 4,
                'created_at' => '2026-05-01 14:00:00',
            ],
            [
                'user_id' => 3,
                'balance_delta' => 10.0,
                'source' => 'DAILY_CHECKIN_REWARD',
                'source_key' => 'mattoid-money-history-auto.forum.checkin-saved',
                'source_params' => null,
                'balance_before' => 7.0,
                'balance_after' => 17.0,
                'actor_id' => 3,
                'created_at' => '2026-05-01 15:00:00',
            ],
            [
                'user_id' => 3,
                'balance_delta' => 50.0,
                'source' => 'MANUAL_ADJUSTMENT',
                'source_key' => 'mattoid-money-history-auto.forum.system-rewards',
                'source_params' => null,
                'balance_before' => 17.0,
                'balance_after' => 67.0,
                'actor_id' => 1,
                'created_at' => '2026-05-01 16:00:00',
            ],
        ]);

        $migration = require __DIR__.'/../../migrations/2026_05_21_000001_normalize_source_key_prefixes.php';
        $migration['up']($schema);

        $rows = $connection->table('user_money_history')
            ->where('user_id', 3)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $rows);
        $this->assertSame('huoxin-money-with-history.forum.history.post-reward', $rows[0]->source_key);
        $this->assertSame('huoxin-money-with-history.forum.history.post-liked', $rows[1]->source_key);
        $this->assertSame('ziven-checkin.forum.history.checkin-reward', $rows[2]->source_key);
        $this->assertSame('huoxin-money-with-history.forum.history.manual-adjustment', $rows[3]->source_key);
    }

    /** @test */
    public function it_does_not_modify_already_migrated_or_unrelated_source_keys(): void
    {
        $connection = $this->app()->getContainer()->make(ConnectionInterface::class);
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('user_money_history')) {
            $create = require __DIR__.'/../../migrations/2023_11_08_000000_create_user_money_history_table.php';
            $create['up']($schema);
        }

        $connection->table('user_money_history')->insert([
            [
                'user_id' => 4,
                'balance_delta' => 5.0,
                'source' => 'POST_POSTED',
                'source_key' => 'huoxin-money-with-history.forum.history.post-reward',
                'source_params' => null,
                'balance_before' => 0,
                'balance_after' => 5.0,
                'actor_id' => 4,
                'created_at' => '2026-05-01 17:00:00',
            ],
            [
                'user_id' => 4,
                'balance_delta' => -10.0,
                'source' => 'STORE_BUY_GOODS',
                'source_key' => 'mattoid-store.forum.history.purchase',
                'source_params' => null,
                'balance_before' => 5.0,
                'balance_after' => -5.0,
                'actor_id' => 4,
                'created_at' => '2026-05-01 18:00:00',
            ],
        ]);

        $migration = require __DIR__.'/../../migrations/2026_05_21_000001_normalize_source_key_prefixes.php';
        $migration['up']($schema);

        $rows = $connection->table('user_money_history')
            ->where('user_id', 4)
            ->orderBy('id')
            ->get();

        // Already-migrated key should be untouched
        $this->assertSame('huoxin-money-with-history.forum.history.post-reward', $rows[0]->source_key);
        // Unrelated extension key should be untouched
        $this->assertSame('mattoid-store.forum.history.purchase', $rows[1]->source_key);
    }
}
