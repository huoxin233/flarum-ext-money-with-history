<?php

namespace Huoxin\MoneyWithHistory\Listeners;

use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Discussion\Event\Hidden as DiscussionHidden;
use Flarum\Discussion\Event\Restored as DiscussionRestored;
use Flarum\Discussion\Event\Started;
use Flarum\Post\Event\Deleted as PostDeleted;
use Flarum\Post\Event\Hidden as PostHidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored as PostRestored;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Saving;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Service\BalanceManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;

class MoneyBalanceSubscriber
{
    private const AUTOREMOVE_NEVER = 0;
    private const AUTOREMOVE_HIDDEN = 1;
    private const AUTOREMOVE_DELETED = 2;

    private const SOURCE_POST_WAS_POSTED = 'POST_POSTED';
    private const SOURCE_POST_WAS_RESTORED = 'POST_RESTORED';
    private const SOURCE_POST_WAS_HIDDEN = 'POST_HIDDEN';
    private const SOURCE_POST_WAS_DELETED = 'POST_DELETED';
    private const SOURCE_DISCUSSION_WAS_STARTED = 'DISCUSSION_STARTED';
    private const SOURCE_DISCUSSION_WAS_RESTORED = 'DISCUSSION_RESTORED';
    private const SOURCE_DISCUSSION_WAS_HIDDEN = 'DISCUSSION_HIDDEN';
    private const SOURCE_DISCUSSION_WAS_DELETED = 'DISCUSSION_DELETED';
    private const SOURCE_MANUAL_ADJUSTMENT = 'MANUAL_ADJUSTMENT';
    private const SOURCE_POST_WAS_LIKED = 'POST_LIKED';
    private const SOURCE_POST_WAS_UNLIKED = 'POST_UNLIKED';

    protected float $moneyforpost;
    protected int $postminimumlength;
    protected float $moneyfordiscussion;
    protected float $moneyforlike;
    protected int $autoremove;
    protected bool $cascaderemove;
    protected bool $ignoreNotifyingUsersSwitch;
    protected bool $rewardPrivateDiscussion;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected BalanceManager $balances
    ) {
        $this->moneyforpost = (float) $this->settings->get('huoxin-money-with-history.moneyforpost', 0);
        $this->postminimumlength = (int) $this->settings->get('huoxin-money-with-history.postminimumlength', 0);
        $this->moneyfordiscussion = (float) $this->settings->get('huoxin-money-with-history.moneyfordiscussion', 0);
        $this->moneyforlike = (float) $this->settings->get('huoxin-money-with-history.moneyforlike', 0);
        $this->autoremove = (int) $this->settings->get('huoxin-money-with-history.autoremove', 1);
        $this->cascaderemove = (bool) $this->settings->get('huoxin-money-with-history.cascaderemove', false);
        $this->ignoreNotifyingUsersSwitch = (bool) $this->settings->get('huoxin-money-with-history.ignorenotifyingusers', false);
        $this->rewardPrivateDiscussion = (bool) $this->settings->get('huoxin-money-with-history.rewardPrivateDiscussion', false);
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Posted::class, [$this, 'postWasPosted']);
        $events->listen(PostRestored::class, [$this, 'postWasRestored']);
        $events->listen(PostHidden::class, [$this, 'postWasHidden']);
        $events->listen(PostDeleted::class, [$this, 'postWasDeleted']);
        $events->listen(Started::class, [$this, 'discussionWasStarted']);
        $events->listen(DiscussionRestored::class, [$this, 'discussionWasRestored']);
        $events->listen(DiscussionHidden::class, [$this, 'discussionWasHidden']);
        $events->listen(DiscussionDeleted::class, [$this, 'discussionWasDeleted']);
        $events->listen(Saving::class, [$this, 'userWillBeSaved']);
    }

    public function adjustBalance(
        ?User $user,
        float $balanceDelta,
        string $source = '',
        string $sourceKey = '',
        array $sourceParams = [],
        ?User $actor = null
    ): bool {
        return $this->balances->adjustBalance($user, $balanceDelta, $source, $sourceKey, $sourceParams, $actor);
    }

    public function adjustPostAuthorBalance(
        ?User $user,
        float $balanceDelta,
        Post $post,
        string $source = '',
        string $sourceKey = '',
        ?User $actor = null,
        array $sourceParams = []
    ): void {
        if ($user === null) {
            return;
        }

        $permissions = true;
        foreach ($post->discussion->tags ?? [] as $tag) {
            if ($user->hasPermission("tag{$tag->id}.discussion.money.disable_money") && ! $user->isAdmin()) {
                $permissions = false;
            }
        }

        if ($permissions) {
            $this->adjustBalance($user, $balanceDelta, $source, $sourceKey, $sourceParams, $actor);
        }
    }

    private function sourceKey(string $name): string
    {
        return "huoxin-money-with-history.forum.money-history.{$name}";
    }

    public function ignoreNotifyingUsers(string $content): string
    {
        if (! $this->ignoreNotifyingUsersSwitch) {
            return $content;
        }

        $pattern = '/@.*?(#\d+|#p\d+)/';

        return trim(str_replace(["\r", "\n"], '', preg_replace($pattern, '', $content)));
    }

    public function postWasPosted(Posted $event): void
    {
        if (isset($event->post->is_approved) && ! $event->post->is_approved) {
            return;
        }

        if (! $this->rewardPrivateDiscussion && isset($event->post->discussion->is_private) && $event->post->discussion->is_private) {
            return;
        }

        $content = $this->ignoreNotifyingUsers($event->post->content);
        if (
            $event->post->number > 1
            && mb_strlen($content) >= $this->postminimumlength
        ) {
            $this->adjustPostAuthorBalance(
                $event->post->user,
                $this->moneyforpost,
                $event->post,
                self::SOURCE_POST_WAS_POSTED,
                $this->sourceKey('post-reward'),
                $event->actor
            );
        }
    }

    public function postWasRestored(PostRestored $event): void
    {
        if (isset($event->post->is_approved) && ! $event->post->is_approved) {
            return;
        }

        if (! $this->rewardPrivateDiscussion && isset($event->post->discussion->is_private) && $event->post->discussion->is_private) {
            return;
        }

        $content = $this->ignoreNotifyingUsers($event->post->content);
        if (
            $this->autoremove == self::AUTOREMOVE_HIDDEN
            && $event->post->type == 'comment'
            && mb_strlen($content) >= $this->postminimumlength
        ) {
            $this->adjustPostAuthorBalance(
                $event->post->user,
                $this->moneyforpost,
                $event->post,
                self::SOURCE_POST_WAS_RESTORED,
                $this->sourceKey('post-restored'),
                $event->actor
            );
        }
    }

    public function postWasHidden(PostHidden $event): void
    {
        // Flarum automatically sets is_approved to true when hiding an unapproved post.
        // If it was just changed, it means it was a rejection, so it never earned money.
        if ($event->post->wasChanged('is_approved')) {
            return;
        }

        if (isset($event->post->is_approved) && ! $event->post->is_approved) {
            return;
        }

        if (! $this->rewardPrivateDiscussion && isset($event->post->discussion->is_private) && $event->post->discussion->is_private) {
            return;
        }

        $content = $this->ignoreNotifyingUsers($event->post->content);
        if (
            $this->autoremove == self::AUTOREMOVE_HIDDEN
            && $event->post->type == 'comment'
            && mb_strlen($content) >= $this->postminimumlength
        ) {
            $this->adjustPostAuthorBalance(
                $event->post->user,
                -1 * $this->moneyforpost,
                $event->post,
                self::SOURCE_POST_WAS_HIDDEN,
                $this->sourceKey('post-hidden'),
                $event->actor
            );
        }
    }

    public function postWasDeleted(PostDeleted $event): void
    {
        if ($event->post->wasChanged('is_approved')) {
            return;
        }

        if (isset($event->post->is_approved) && ! $event->post->is_approved) {
            return;
        }

        if (! $this->rewardPrivateDiscussion && isset($event->post->discussion->is_private) && $event->post->discussion->is_private) {
            return;
        }

        $content = $this->ignoreNotifyingUsers($event->post->content);
        if (
            $this->autoremove == self::AUTOREMOVE_DELETED
            && $event->post->type == 'comment'
            && mb_strlen($content) >= $this->postminimumlength
        ) {
            $this->adjustPostAuthorBalance(
                $event->post->user,
                -1 * $this->moneyforpost,
                $event->post,
                self::SOURCE_POST_WAS_DELETED,
                $this->sourceKey('post-deleted'),
                $event->actor
            );
        }
    }

    public function discussionWasStarted(Started $event): void
    {
        if (isset($event->discussion->is_approved) && ! $event->discussion->is_approved) {
            return;
        }

        if (! $this->rewardPrivateDiscussion && isset($event->discussion->is_private) && $event->discussion->is_private) {
            return;
        }

        $this->adjustBalance(
            $event->discussion->user,
            $this->moneyfordiscussion,
            self::SOURCE_DISCUSSION_WAS_STARTED,
            $this->sourceKey('discussion-reward'),
            [],
            $event->actor
        );
    }

    public function discussionWasRestored(DiscussionRestored $event): void
    {
        if (isset($event->discussion->is_approved) && ! $event->discussion->is_approved) {
            return;
        }

        if (! $this->rewardPrivateDiscussion && isset($event->discussion->is_private) && $event->discussion->is_private) {
            return;
        }

        if ($this->autoremove == self::AUTOREMOVE_HIDDEN) {
            $this->adjustBalance(
                $event->discussion->user,
                $this->moneyfordiscussion,
                self::SOURCE_DISCUSSION_WAS_RESTORED,
                $this->sourceKey('discussion-restored'),
                [],
                $event->actor
            );

            $this->discussionCascadePosts(
                $event->discussion,
                1,
                self::SOURCE_POST_WAS_RESTORED,
                $this->sourceKey('post-restored'),
                $event->actor
            );
        }
    }

    public function discussionWasHidden(DiscussionHidden $event): void
    {
        // Flarum automatically sets is_approved to true when hiding an unapproved discussion.
        if ($event->discussion->wasChanged('is_approved')) {
            return;
        }

        if (isset($event->discussion->is_approved) && ! $event->discussion->is_approved) {
            return;
        }

        if (! $this->rewardPrivateDiscussion && isset($event->discussion->is_private) && $event->discussion->is_private) {
            return;
        }

        if ($this->autoremove == self::AUTOREMOVE_HIDDEN) {
            $this->adjustBalance(
                $event->discussion->user,
                -$this->moneyfordiscussion,
                self::SOURCE_DISCUSSION_WAS_HIDDEN,
                $this->sourceKey('discussion-hidden'),
                [],
                $event->actor
            );

            $this->discussionCascadePosts(
                $event->discussion,
                -1,
                self::SOURCE_POST_WAS_HIDDEN,
                $this->sourceKey('post-hidden'),
                $event->actor
            );
        }
    }

    public function discussionWasDeleted(DiscussionDeleted $event): void
    {
        if ($event->discussion->wasChanged('is_approved')) {
            return;
        }

        if (isset($event->discussion->is_approved) && ! $event->discussion->is_approved) {
            return;
        }

        if (! $this->rewardPrivateDiscussion && isset($event->discussion->is_private) && $event->discussion->is_private) {
            return;
        }

        if ($this->autoremove == self::AUTOREMOVE_DELETED) {
            $this->adjustBalance(
                $event->discussion->user,
                -$this->moneyfordiscussion,
                self::SOURCE_DISCUSSION_WAS_DELETED,
                $this->sourceKey('discussion-deleted'),
                [],
                $event->actor
            );

            $this->discussionCascadePosts(
                $event->discussion,
                -1,
                self::SOURCE_POST_WAS_DELETED,
                $this->sourceKey('post-deleted'),
                $event->actor
            );
        }
    }

    protected function discussionCascadePosts(
        Discussion $discussion,
        int $multiply,
        string $source,
        string $sourceKey,
        ?User $actor = null
    ): void {
        if (! $this->cascaderemove) {
            return;
        }

        $userDeltas = [];
        $tags = $discussion->tags ?? [];

        $discussion->posts()
            ->with('user')
            ->where('type', 'comment')
            ->chunk(200, function ($posts) use (&$userDeltas, $multiply, $tags) {
                foreach ($posts as $post) {
                    $user = $post->user;
                    if ($user === null) {
                        continue;
                    }

                    $content = $this->ignoreNotifyingUsers($post->content);
                    if (
                        mb_strlen($content) >= $this->postminimumlength
                        && $post->number > 1
                        && is_null($post->hidden_at)
                    ) {
                        $permissions = true;

                        foreach ($tags as $tag) {
                            if ($user->hasPermission("tag{$tag->id}.discussion.money.disable_money") && ! $user->isAdmin()) {
                                $permissions = false;
                                break;
                            }
                        }

                        if ($permissions) {
                            if (! isset($userDeltas[$user->id])) {
                                $userDeltas[$user->id] = [
                                    'user' => $user,
                                    'delta' => 0.0,
                                ];
                            }
                            $userDeltas[$user->id]['delta'] += ($multiply * $this->moneyforpost);
                        }
                    }
                }
            });

        // Group users by the amount they need to be adjusted
        $usersByDelta = [];
        foreach ($userDeltas as $data) {
            $deltaString = (string) $data['delta'];
            if (! isset($usersByDelta[$deltaString])) {
                $usersByDelta[$deltaString] = [
                    'delta' => $data['delta'],
                    'users' => []
                ];
            }
            $usersByDelta[$deltaString]['users'][] = $data['user'];
        }

        foreach ($usersByDelta as $group) {
            $this->balances->adjustBalances($group['users'], $group['delta'], $source, $sourceKey, [], $actor);
        }
    }

    public function userWillBeSaved(Saving $event): void
    {
        $attributes = Arr::get($event->data, 'attributes', []);

        if (! array_key_exists('money', $attributes)) {
            return;
        }

        $user = $event->user;
        $actor = $event->actor;
        $actor->assertCan('edit_money', $user);

        $balanceDelta = (float) $attributes['money'] - (float) $user->money;

        if ($balanceDelta !== 0.0) {
            $this->balances->applyBalanceChange(
                $user,
                $balanceDelta,
                self::SOURCE_MANUAL_ADJUSTMENT,
                $this->sourceKey('manual-adjustment'),
                [],
                $actor
            );
        }
    }

    public function postWasLiked($event): void
    {
        $this->adjustBalance(
            $event->post->user,
            $this->moneyforlike,
            self::SOURCE_POST_WAS_LIKED,
            $this->sourceKey('post-liked'),
            [],
            $event->user
        );
    }

    public function postWasUnliked($event): void
    {
        $this->adjustBalance(
            $event->post->user,
            -1 * $this->moneyforlike,
            self::SOURCE_POST_WAS_UNLIKED,
            $this->sourceKey('post-unliked'),
            [],
            $event->user
        );
    }

    public function postWasApproved($event): void
    {
        $post = $event->post;

        if (! $this->rewardPrivateDiscussion && isset($post->discussion->is_private) && $post->discussion->is_private) {
            return;
        }

        $content = $this->ignoreNotifyingUsers($post->content);
        if (
            $post->number > 1
            && mb_strlen($content) >= $this->postminimumlength
        ) {
            $this->adjustPostAuthorBalance(
                $post->user,
                $this->moneyforpost,
                $post,
                self::SOURCE_POST_WAS_POSTED,
                $this->sourceKey('post-reward'),
                $event->actor
            );
        }

        if ($post->number === 1 && $post->discussion) {
            $this->adjustBalance(
                $post->discussion->user,
                $this->moneyfordiscussion,
                self::SOURCE_DISCUSSION_WAS_STARTED,
                $this->sourceKey('discussion-reward'),
                [],
                $event->actor
            );
        }
    }
}
