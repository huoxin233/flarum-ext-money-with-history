# Money With History

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/huoxin/money-with-history.svg)](https://packagist.org/packages/huoxin/money-with-history) [![Total Downloads](https://img.shields.io/packagist/dt/huoxin/money-with-history.svg)](https://packagist.org/packages/huoxin/money-with-history) [![Review](https://floxum.com/extension/huoxin/money-with-history/badge/review)](https://floxum.com/extension/huoxin/money-with-history) [![Review Score](https://floxum.com/extension/huoxin/money-with-history/badge/review-score)](https://floxum.com/extension/huoxin/money-with-history)

A [Flarum](https://flarum.org) extension that adds a virtual currency system with full transaction history tracking.

As discussed [here](https://github.com/AntoineFr/flarum-ext-money/pull/52), this extension would be a merge of [`antoinefr/flarum-ext-money`](https://github.com/AntoineFr/flarum-ext-money) and [`mattoid/flarum-ext-money-history`](https://github.com/mattoid/flarum-ext-money-history) into a single, standalone package so no cross-extension dependency management is required. With this extension, I hope that we can eliminate the need of [`mattoid/flarum-ext-money-history-auto`](https://github.com/Mattoids/flarum-ext-money-history-auto) which relies on middleware to intercept API requests and record balance changes. However, achieving full compatibility will require support and integration from other extension developers.

## Features

- Award money for posts, discussions, and likes
- Configurable auto-removal when content is hidden or deleted
- Cascade removal for posts when a discussion is deleted
- Minimum post length requirement
- Manual balance editing by moderators
- **Full balance history**
- Per-tag money disable permission

## Installation

```sh
composer require huoxin/money-with-history
php flarum migrate
php flarum cache:clear
```

## Updating

```sh
composer update huoxin/money-with-history
php flarum migrate
php flarum cache:clear
```

> [!WARNING]
> **Breaking Change for Developers & Integrators:** In `1.2.0`, all database setting keys and translation strings have been standardized to `snake_case` semantic formats (e.g., `moneyname` is now `money_name`). If your custom theme or third-party extension directly queries these keys from the Flarum settings repository, you must update your code to match the new `snake_case` format. Normal forum administrators are unaffected, as the `php flarum migrate` command seamlessly updates existing configurations.

## Migrating From Legacy Extensions

Do note that some of the more complex ones are **not covered**, you will have to manually migrate it yourself if you want a 100% clean money history.

> [!IMPORTANT]
> Enabling this extension for the first time will run migration tasks automatically.
> If you previously had a large money history database, the process may take some time to complete.
> It is recommended to enable the extension via CLI:
>
> ```bash
> php flarum extension:enable huoxin-money-with-history
> ```

> [!WARNING]
> **Timezone DST Inaccuracy Fallback:** If your Flarum database relies on SQLite, PostgreSQL, or a MySQL instance _without_ global timezone tables loaded, the historical timezone migration will fall back to a PHP-computed offset. For timezones that observe Daylight Saving Time (e.g., `America/New_York`), records created during the opposite DST phase of when the migration is run will be shifted inaccurately by ±1 hour.

If you were previously using `antoinefr/flarum-ext-money` and/or `mattoid/flarum-ext-money-history`:

1. **Backup your database**.
2. Install this extension alongside the old ones.
3. Run `php flarum migrate` — idempotent migrations will:
   - Add the `money` column and `user_money_history` table if missing
   - Rename legacy columns (`type` → `source`, `money` → `balance_delta`, etc.)
   - Normalize `source` values (e.g. `POSTWASPOSTED` → `POST_POSTED`)
   - Migrate `source_key` translation prefixes to `huoxin-money-with-history.forum.money-history.*`
   - Copy settings keys from `antoinefr-money.*` and `money-history.*` to `huoxin-money-with-history.*`
4. Disable and uninstall the old extensions.

Legacy data from the deprecated `mattoid-money-history-auto` extension is also migrated.

## For Other Extension Authors

This extension is the main balance-changing entry point. Other extensions should inject:

```php
use Huoxin\MoneyWithHistory\Service\BalanceManager;
```

### Available Methods

#### Method comparison

| Method                 | Transaction     | Row lock         | Saves user                   | Best for                                      |
| ---------------------- | --------------- | ---------------- | ---------------------------- | --------------------------------------------- |
| `adjustBalance()`      | Opens its own   | Locks internally | Yes, internally              | Standalone one-user changes                   |
| `adjustBalances()`     | Opens its own   | Locks all rows   | Yes, internally              | Batch rewards / bulk grants                   |
| `transferBalance()`    | Opens its own   | Locks both users | Yes, internally              | User-to-user transfers                        |
| `applyBalanceChange()` | **You provide** | **You lock**     | **You call** `$user->save()` | Saving money alongside your own domain fields |

#### `adjustBalance()`

Single user balance change. Opens a transaction, locks the user row, updates the balance, writes history, and dispatches events — all self-contained. Accepts a `User` object or a raw integer user ID.

```php
$this->balances->adjustBalance(
    $user,
    -12.5,
    'MYEXTENSION_PURCHASE',
    'vendor-my-extension.forum.money-history.purchase',
    ['itemTitle' => 'VIP Badge'],
    $actor,
    preventOverdraft: true
);
```

Returns `false` if the user has insufficient balance (when `preventOverdraft` is enabled).

#### `adjustBalances()`

Batch update for multiple users in a single transaction. Preferred for system rewards and bulk grants. Accepts an array of `User` objects or raw integer user IDs.

**Best Practice:** If processing thousands of users simultaneously, chunk your input array (e.g., 500 users per call). This prevents PHP memory exhaustion and prevents MySQL lock exhaustion (since all rows are locked simultaneously during the transaction).

```php
$totalUpdated = 0;

// Fetch and process users using chunkById to preserve memory and prevent lock exhaustion
User::query()->where('is_vip', true)->chunkById(500, function ($users) use (&$totalUpdated, $actor) {
    $totalUpdated += $this->balances->adjustBalances(
        $users->all(), // Convert Eloquent Collection to array
        5.0,
        'DAILY_REWARD',
        'vendor-my-extension.forum.money-history.daily-reward',
        [],
        $actor
    );
});
```

Returns the count of users actually updated. Silently skips users who can't afford the debit when `preventOverdraft` is enabled.

#### `transferBalance()`

Atomic user-to-user transfer. Always prevents overdraft on the sender side. Both the sender and receiver parameters accept a `User` object or a raw integer user ID.

```php
$this->balances->transferBalance(
    $sender,
    $receiver,
    25.0,
    'MYEXTENSION_TRANSFER',
    'vendor-my-extension.forum.money-history.sent',
    'vendor-my-extension.forum.money-history.received',
    [
        'giverUsername' => $sender->username,
        'receiverUsername' => $receiver->username,
    ],
    $actor
);
```

#### `applyBalanceChange()`

Use when your extension already manages its own database transaction and needs to persist the balance change alongside other domain fields atomically.

Unlike `adjustBalance()` which opens its own transaction and calls `save()` internally, `applyBalanceChange()` only mutates `$user->money` on the model object. History recording and event dispatching are deferred to an Eloquent `afterSave` callback — they only execute after your `$user->save()` succeeds. If the save fails or the transaction rolls back, no orphaned history row is written.

**The caller is responsible for:**

1. Opening a database transaction
2. Locking the user row (`SELECT ... FOR UPDATE`)
3. Calling `$user->save()` after this method

```php
$this->connection->transaction(function () use ($user, $actor) {
    $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();

    // Your domain field
    $lockedUser->last_checkin_time = now();

    // Mutates $lockedUser->money on the model — does NOT save or write history yet
    $this->balances->applyBalanceChange(
        $lockedUser,
        5.0,
        'DAILY_CHECKIN_REWARD',
        'vendor-my-extension.forum.history.checkin-reward',
        ['streakDays' => 7],
        $actor
    );

    // One save persists both last_checkin_time AND money atomically.
    // The afterSave callback then writes the history row and dispatches MoneyUpdated.
    $lockedUser->save();
});
```

### Background Queue Jobs

#### `BatchAdjustBalances` (Job)

If you need to bulk-adjust balances for thousands of users simultaneously (e.g., a system-wide reward) without blocking the user's web request, you can dispatch this generic background job.

```php
use Huoxin\MoneyWithHistory\Job\BatchAdjustBalances;
use Illuminate\Contracts\Queue\Queue;

// Build an array map of [user_id => delta_amount]
$userDeltas = [
    1 => 50.0,
    2 => 50.0,
    3 => -15.0
];

resolve(Queue::class)->push(
    new BatchAdjustBalances(
        $userDeltas,
        'DAILY_LOGIN_BONUS', // The source string for the history log
        'vendor-ext.forum.money-history.daily-bonus', // The translation key
        null // Optional actor ID
    )
);
```

The job automatically processes the users in memory-safe chunks of 500 at a time to prevent MySQL lock exhaustion.

### `source`, `sourceKey`, `sourceParams`

| Field          | Purpose                                 | Example                                   |
| -------------- | --------------------------------------- | ----------------------------------------- |
| `source`       | Stable machine-readable identifier      | `STORE_BUY_GOODS`                         |
| `sourceKey`    | Frontend translation key                | `vendor-ext.forum.money-history.purchase` |
| `sourceParams` | Flat key-value data for the translation | `['itemTitle' => 'VIP Badge']`            |

**`sourceParams` conventions:**

- Plain values: `itemTitle`, `postNumber`, `username`
- Translated values: keys ending with `Key` (e.g. `purchaseTypeKey`)
- Link values: keys ending with `LinkHref` (e.g. `itemLinkHref`)

### Optional Integration (Soft Dependency)

If your extension wants to offer money features without requiring this extension:

```php
use Huoxin\MoneyWithHistory\Service\BalanceManager;

if ($this->extensions->isEnabled('huoxin-money-with-history')) {
    $balanceManager = $this->container->make(BalanceManager::class);
    $balanceManager->applyBalanceChange(...);
}
```

## Concurrency And Locking

`BalanceManager` locks affected user rows during write transactions to keep balance snapshots consistent.

- Prefer `adjustBalances()` and `transferBalance()` over hand-written loops
- Keep transaction work small and avoid slow side effects inside it

## Screenshots

<img height="700" alt="image" src="https://github.com/user-attachments/assets/f7713ae4-25a7-4598-80d5-b8a68ef281ce" />

<img height="700" alt="image" src="https://github.com/user-attachments/assets/d0aea471-f8e1-4cc5-a5b9-0a58fe6b7ef4" />

## Credits

A special thanks to the original creators and contributors who made this project possible:

- [AntoineFr](https://github.com/AntoineFr) for the `flarum-ext-money` extension.
- [Mattoid](https://github.com/Mattoids) for the `flarum-ext-money-history` and `flarum-ext-money-history-auto` extension.
- [Ernest Defoe](https://github.com/ernestdefoe) and the [FriendsOfFlarum Team](https://github.com/FriendsOfFlarum) for the automated Flarum Community release workflow.

## Links

- [Packagist](https://packagist.org/packages/huoxin/money-with-history)
- [GitHub](https://github.com/huoxin233/flarum-ext-money-with-history)
- [Discuss](https://discuss.flarum.org/d/39319)
