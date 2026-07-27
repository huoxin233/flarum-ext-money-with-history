<?php

namespace Huoxin\MoneyWithHistory\Service;

use Flarum\User\User;
use Huoxin\MoneyWithHistory\Event\MoneyUpdated;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;

class BalanceManager
{
    public function __construct(
        private Dispatcher $events,
        private HistoryWriter $historyWriter
    ) {
    }

    /**
     * Adjust a single user's balance within a dedicated lock-for-update transaction.
     *
     * Use this for standalone balance changes where the caller does NOT already
     * hold a database transaction. For piggy-backing onto an existing save,
     * use applyBalanceChange() instead.
     */
    public function adjustBalance(
        User|int|null $user,
        float $balanceDelta,
        string $source = '',
        string $sourceKey = '',
        array $sourceParams = [],
        ?User $actor = null,
        bool $preventOverdraft = false
    ): bool {
        if ($user === null || $balanceDelta === 0.0) {
            return false;
        }

        $balanceUpdatedEvent = null;
        $updated = (bool) User::resolveConnection()->transaction(function () use ($user, $balanceDelta, $source, $sourceKey, $actor, $sourceParams, $preventOverdraft, &$balanceUpdatedEvent) {
            $userId = $user instanceof User ? $user->id : $user;

            $lockedUser = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->first();

            if ($lockedUser === null) {
                return false;
            }

            $balanceBefore = (float) $lockedUser->money;

            if ($preventOverdraft && ($balanceBefore + $balanceDelta) < 0) {
                return false;
            }

            $lockedUser->money = $this->calculateNewBalance($balanceBefore, $balanceDelta);
            $lockedUser->save();

            $balanceAfter = (float) $lockedUser->money;

            if ($user instanceof User) {
                $user->money = $balanceAfter;
            }

            $this->historyWriter->write(
                $lockedUser,
                $balanceDelta,
                $source,
                $sourceKey,
                $sourceParams,
                $actor,
                $balanceBefore,
                $balanceAfter
            );

            $balanceUpdatedEvent = $this->newBalanceUpdatedEvent(
                $lockedUser,
                $balanceDelta,
                $source,
                $sourceKey,
                $sourceParams,
                $actor,
                $balanceBefore,
                $balanceAfter
            );

            return true;
        });

        if ($updated && $balanceUpdatedEvent instanceof MoneyUpdated) {
            $this->executeAfterCommit(function () use ($balanceUpdatedEvent) {
                $this->events->dispatch($balanceUpdatedEvent);
            });
        }

        return $updated;
    }

    /**
     * Adjust multiple users' balances in a single transaction with row-level locking.
     *
     * Unlike `adjustBalance`, this method uses raw `increment`/`decrement`
     * queries to persist changes instead of `$user->save()`. This intentionally bypasses
     * Eloquent model events (saving, saved) to maximize throughput and avoid the massive
     * performance overhead of firing thousands of model observers during bulk cascades.
     *
     * Preferred for system rewards, bulk grants, and other many-user operations.
     *
     * BEST PRACTICE: If processing thousands of users, callers MUST chunk the input array
     * (e.g. 500 users per call) to prevent PHP memory exhaustion and MySQL InnoDB
     * lock exhaustion, as this method locks every row in the array simultaneously.
     *
     * @param array|null &$deferredEvents @internal Used to defer event dispatching when nested inside larger transactions.
     */
    public function adjustBalances(
        array $users,
        float $balanceDelta,
        string $source = '',
        string $sourceKey = '',
        array $sourceParams = [],
        ?User $actor = null,
        bool $preventOverdraft = false,
        ?array &$deferredEvents = null
    ): int {
        if ($balanceDelta === 0.0) {
            return 0;
        }

        $userIds = [];
        $usersById = [];

        foreach ($users as $user) {
            if (is_numeric($user)) {
                $userIds[(int) $user] = (int) $user;
            } elseif ($user instanceof User) {
                $userIds[(int) $user->id] = (int) $user->id;
                $usersById[(int) $user->id] = $user;
            }
        }

        if ($userIds === []) {
            return 0;
        }

        sort($userIds);

        $balanceUpdatedEvents = [];
        $updatedCount = (int) User::resolveConnection()->transaction(function () use ($userIds, $usersById, $balanceDelta, $source, $sourceKey, $sourceParams, $actor, $preventOverdraft, &$balanceUpdatedEvents) {
            $lockedUsers = User::query()
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedUsers->isEmpty()) {
                return 0;
            }

            $updatedUsers = [];
            $updatedUserIds = [];

            foreach ($lockedUsers as $lockedUser) {
                $balanceBefore = (float) $lockedUser->money;

                if ($preventOverdraft && ($balanceBefore + $balanceDelta) < 0) {
                    continue;
                }

                $lockedUser->money = $this->calculateNewBalance($balanceBefore, $balanceDelta);

                $balanceAfter = (float) $lockedUser->money;
                $updatedUsers[] = $lockedUser;
                $updatedUserIds[] = $lockedUser->id;

                if (isset($usersById[(int) $lockedUser->id])) {
                    $usersById[(int) $lockedUser->id]->money = $balanceAfter;
                }

                $balanceUpdatedEvents[] = $this->newBalanceUpdatedEvent(
                    $lockedUser,
                    $balanceDelta,
                    $source,
                    $sourceKey,
                    $sourceParams,
                    $actor,
                    $balanceBefore,
                    $balanceAfter
                );
            }

            if ($updatedUserIds !== []) {
                User::query()->whereIn('id', $updatedUserIds)->update([
                    'money' => $this->getBulkUpdateExpression($balanceDelta)
                ]);
            }

            if ($updatedUsers !== []) {
                $this->historyWriter->writeMany(
                    $updatedUsers,
                    $balanceDelta,
                    $source,
                    $sourceKey,
                    $sourceParams,
                    $actor
                );
            }

            return count($updatedUsers);
        });

        if ($deferredEvents !== null) {
            $deferredEvents = array_merge($deferredEvents, $balanceUpdatedEvents);
        } else {
            $this->executeAfterCommit(function () use ($balanceUpdatedEvents) {
                foreach ($balanceUpdatedEvents as $balanceUpdatedEvent) {
                    $this->events->dispatch($balanceUpdatedEvent);
                }
            });
        }

        return $updatedCount;
    }

    /**
     * Transfer balance from one user to another in a single atomic transaction.
     *
     * Both sides are locked, debited/credited, and recorded consistently.
     * Returns false if the sender has insufficient balance.
     */
    public function transferBalance(
        User|int|null $fromUser,
        User|int|null $toUser,
        float $amount,
        string $source = '',
        string $fromSourceKey = '',
        string $toSourceKey = '',
        array $sourceParams = [],
        ?User $actor = null,
        ?callable $withinTransaction = null
    ): bool {
        if ($toUser === null || $amount === 0.0) {
            return false;
        }

        $balanceUpdatedEvents = [];

        $updated = (bool) User::resolveConnection()->transaction(function () use ($fromUser, $toUser, $amount, $source, $fromSourceKey, $toSourceKey, $sourceParams, $actor, $withinTransaction, &$balanceUpdatedEvents) {
            $toUserId = $toUser instanceof User ? (int) $toUser->id : (int) $toUser;
            $userIds = [$toUserId];

            if ($fromUser !== null) {
                $fromUserId = $fromUser instanceof User ? (int) $fromUser->id : (int) $fromUser;
                $userIds[] = $fromUserId;
            }

            $lockedUsers = User::query()
                ->whereIn('id', array_values(array_unique($userIds)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedFromUser = $fromUser !== null ? $lockedUsers->get($fromUserId) : null;
            $lockedToUser = $lockedUsers->get($toUserId);

            if ($lockedToUser === null) {
                return false;
            }

            if ($fromUser !== null) {
                if ($lockedFromUser === null) {
                    return false;
                }

                if ((float) $lockedFromUser->money < $amount) {
                    return false;
                }

                $fromBalanceBefore = (float) $lockedFromUser->money;
                $lockedFromUser->money = $this->calculateNewBalance($fromBalanceBefore, -$amount);
                $lockedFromUser->save();

                $fromBalanceAfter = (float) $lockedFromUser->money;

                if ($fromUser instanceof User) {
                    $fromUser->money = $fromBalanceAfter;
                }

                $this->historyWriter->write(
                    $lockedFromUser,
                    -$amount,
                    $source,
                    $fromSourceKey,
                    $sourceParams,
                    $actor,
                    $fromBalanceBefore,
                    $fromBalanceAfter
                );

                $balanceUpdatedEvents[] = $this->newBalanceUpdatedEvent(
                    $lockedFromUser,
                    -$amount,
                    $source,
                    $fromSourceKey,
                    $sourceParams,
                    $actor,
                    $fromBalanceBefore,
                    $fromBalanceAfter
                );
            }

            $toBalanceBefore = (float) $lockedToUser->money;
            $lockedToUser->money = $this->calculateNewBalance($toBalanceBefore, $amount);
            $lockedToUser->save();

            $toBalanceAfter = (float) $lockedToUser->money;

            if ($toUser instanceof User) {
                $toUser->money = $toBalanceAfter;
            }

            $this->historyWriter->write(
                $lockedToUser,
                $amount,
                $source,
                $toSourceKey,
                $sourceParams,
                $actor,
                $toBalanceBefore,
                $toBalanceAfter
            );

            $balanceUpdatedEvents[] = $this->newBalanceUpdatedEvent(
                $lockedToUser,
                $amount,
                $source,
                $toSourceKey,
                $sourceParams,
                $actor,
                $toBalanceBefore,
                $toBalanceAfter
            );

            if ($withinTransaction !== null) {
                $withinTransaction($lockedFromUser, $lockedToUser);
            }

            return true;
        });

        if ($updated) {
            $this->executeAfterCommit(function () use ($balanceUpdatedEvents) {
                foreach ($balanceUpdatedEvents as $balanceUpdatedEvent) {
                    $this->events->dispatch($balanceUpdatedEvent);
                }
            });
        }

        return $updated;
    }

    /**
     * Apply a balance change to a user model that is already locked or about to
     * be saved within an existing transaction.
     *
     * The actual mutation is deferred to the model's afterSave hook, so it
     * piggy-backs on the caller's `$user->save()` call. History is written
     * and events dispatched only after the save succeeds.
     */
    public function applyBalanceChange(
        User $user,
        float $amount,
        string $source = '',
        string $sourceKey = '',
        array $sourceParams = [],
        ?User $actor = null,
        bool $preventOverdraft = false
    ): bool {
        if ($amount === 0.0) {
            return false;
        }

        $balanceBefore = (float) $user->money;

        if ($preventOverdraft && ($balanceBefore + $amount) < 0) {
            return false;
        }

        $user->money = $this->calculateNewBalance($balanceBefore, $amount);
        $balanceAfter = (float) $user->money;

        $user->afterSave(function () use ($user, $amount, $source, $sourceKey, $sourceParams, $actor, $balanceBefore, $balanceAfter) {
            $this->historyWriter->write(
                $user,
                $amount,
                $source,
                $sourceKey,
                $sourceParams,
                $actor,
                $balanceBefore,
                $balanceAfter
            );

            $this->events->dispatch($this->newBalanceUpdatedEvent(
                $user,
                $amount,
                $source,
                $sourceKey,
                $sourceParams,
                $actor,
                $balanceBefore,
                $balanceAfter
            ));
        });

        return true;
    }

    private function newBalanceUpdatedEvent(
        ?User $user,
        float $balanceDelta,
        string $source = '',
        string $sourceKey = '',
        array $sourceParams = [],
        ?User $actor = null,
        ?float $balanceBefore = null,
        ?float $balanceAfter = null
    ): MoneyUpdated {
        return new MoneyUpdated(
            $user,
            $balanceDelta,
            $source,
            $sourceKey,
            $sourceParams,
            $actor,
            $balanceBefore,
            $balanceAfter
        );
    }

    /**
     * Bulk update for multiple users when each user needs a *different* delta amount.
     * Executes entirely within a single atomic transaction.
     *
     * @param array<int, float> $userDeltas
     */
    public function adjustBalancesByUserIds(
        array $userDeltas,
        string $source = '',
        string $sourceKey = '',
        array $sourceParams = [],
        ?User $actor = null,
        bool $preventOverdraft = false
    ): void {
        if (empty($userDeltas)) {
            return;
        }

        $usersByDelta = [];

        foreach ($userDeltas as $id => $delta) {
            $deltaString = (string) $delta;

            if (! isset($usersByDelta[$deltaString])) {
                $usersByDelta[$deltaString] = [
                    'delta' => $delta,
                    'users' => []
                ];
            }
            $usersByDelta[$deltaString]['users'][] = $id;
        }

        $deferredEvents = [];

        User::resolveConnection()->transaction(function () use ($usersByDelta, $source, $sourceKey, $sourceParams, $actor, $preventOverdraft, &$deferredEvents) {
            foreach ($usersByDelta as $group) {
                $this->adjustBalances($group['users'], $group['delta'], $source, $sourceKey, $sourceParams, $actor, $preventOverdraft, $deferredEvents);
            }
        });

        $this->executeAfterCommit(function () use ($deferredEvents) {
            foreach ($deferredEvents as $event) {
                $this->events->dispatch($event);
            }
        });
    }

    /**
     * Executes the given callback after the current database transaction commits.
     * Falls back to synchronous execution if no transaction is active or in tests.
     */
    private function executeAfterCommit(callable $callback): void
    {
        try {
            $connection = User::resolveConnection();
            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($callback);

                return;
            }
        } catch (RuntimeException $e) {
            // Ignore transaction manager exceptions in test environments
        }

        $callback();
    }

    /**
     * Safely calculate a new monetary balance, neutralizing float precision drift.
     * Centralizes the rounding logic for the entire service.
     */
    public function calculateNewBalance(float $current, float $delta): float
    {
        return round($current + $delta, 6);
    }

    /**
     * Get a raw DB expression for bulk updating balances while enforcing rounding.
     * Includes PostgreSQL compatibility casting for the ROUND function.
     */
    public function getBulkUpdateExpression(float $delta)
    {
        $connection = User::resolveConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL requires explicit casting to NUMERIC for ROUND() with precision
            return $connection->raw('ROUND(CAST(money + '.$delta.' AS NUMERIC), 6)');
        }

        // MySQL and SQLite natively support ROUND() on floats/doubles
        return $connection->raw('ROUND(money + '.$delta.', 6)');
    }
}
