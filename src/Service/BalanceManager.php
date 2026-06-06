<?php

namespace Huoxin\MoneyWithHistory\Service;

use Flarum\User\User;
use Huoxin\MoneyWithHistory\Event\MoneyUpdated;
use Illuminate\Contracts\Events\Dispatcher;

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
        ?User $user,
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
            $lockedUser = $user->newQuery()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedUser === null) {
                return false;
            }

            $balanceBefore = (float) $lockedUser->money;

            if ($preventOverdraft && ($balanceBefore + $balanceDelta) < 0) {
                return false;
            }

            $lockedUser->money = $balanceBefore + $balanceDelta;
            $lockedUser->save();

            $balanceAfter = (float) $lockedUser->money;
            $user->money = $balanceAfter;

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
            $this->events->dispatch($balanceUpdatedEvent);
        }

        return $updated;
    }

    /**
     * Adjust multiple users' balances in a single transaction with row-level locking.
     *
     * Preferred for system rewards, bulk grants, and other many-user operations.
     *
     * BEST PRACTICE: If processing thousands of users, callers MUST chunk the input array
     * (e.g. 500 users per call) to prevent PHP memory exhaustion and MySQL InnoDB
     * lock exhaustion, as this method locks every row in the array simultaneously.
     */
    public function adjustBalances(
        array $users,
        float $balanceDelta,
        string $source = '',
        string $sourceKey = '',
        array $sourceParams = [],
        ?User $actor = null,
        bool $preventOverdraft = false
    ): int {
        if ($balanceDelta === 0.0) {
            return 0;
        }

        $userIds = [];
        $usersById = [];

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $userIds[(int) $user->id] = (int) $user->id;
            $usersById[(int) $user->id] = $user;
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

                $lockedUser->money = $balanceBefore + $balanceDelta;

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
                if ($balanceDelta > 0) {
                    User::query()->whereIn('id', $updatedUserIds)->increment('money', $balanceDelta);
                } else {
                    User::query()->whereIn('id', $updatedUserIds)->decrement('money', abs($balanceDelta));
                }
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

        foreach ($balanceUpdatedEvents as $balanceUpdatedEvent) {
            $this->events->dispatch($balanceUpdatedEvent);
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
        ?User $fromUser,
        ?User $toUser,
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
            $userIds = [(int) $toUser->id];

            if ($fromUser !== null) {
                $userIds[] = (int) $fromUser->id;
            }

            $lockedUsers = User::query()
                ->whereIn('id', array_values(array_unique($userIds)))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedFromUser = $fromUser ? $lockedUsers->get((int) $fromUser->id) : null;
            $lockedToUser = $lockedUsers->get((int) $toUser->id);

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
                $lockedFromUser->money = $fromBalanceBefore - $amount;
                $lockedFromUser->save();

                $fromBalanceAfter = (float) $lockedFromUser->money;
                $fromUser->money = $fromBalanceAfter;

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
            $lockedToUser->money = $toBalanceBefore + $amount;
            $lockedToUser->save();

            $toBalanceAfter = (float) $lockedToUser->money;
            $toUser->money = $toBalanceAfter;

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
            foreach ($balanceUpdatedEvents as $balanceUpdatedEvent) {
                $this->events->dispatch($balanceUpdatedEvent);
            }
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

        $user->money = $balanceBefore + $amount;
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
}
