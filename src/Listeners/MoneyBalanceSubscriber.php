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
    private const AUTO_REMOVE_NEVER = 0;
    private const AUTO_REMOVE_HIDDEN = 1;
    private const AUTO_REMOVE_DELETED = 2;

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

    protected float $postRewardAmount;
    protected int $minPostLength;
    protected float $discussionRewardAmount;
    protected float $likeRewardAmount;
    protected int $removeMoneyTrigger;
    protected bool $cascadeMoneyRemoval;
    protected bool $excludeMentionsFromLength;
    protected bool $rewardPrivateDiscussion;
    protected bool $rewardSelfLike;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected BalanceManager $balances
    ) {
        $this->postRewardAmount = (float) $this->settings->get('huoxin-money-with-history.post_reward_amount', 0);
        $this->minPostLength = (int) $this->settings->get('huoxin-money-with-history.min_post_length', 0);
        $this->discussionRewardAmount = (float) $this->settings->get('huoxin-money-with-history.discussion_reward_amount', 0);
        $this->likeRewardAmount = (float) $this->settings->get('huoxin-money-with-history.like_reward_amount', 0);
        $this->removeMoneyTrigger = (int) $this->settings->get('huoxin-money-with-history.remove_money_trigger', 1);
        $this->cascadeMoneyRemoval = (bool) $this->settings->get('huoxin-money-with-history.cascade_money_removal', false);
        $this->excludeMentionsFromLength = (bool) $this->settings->get('huoxin-money-with-history.exclude_mentions_from_length', false);
        $this->rewardPrivateDiscussion = (bool) $this->settings->get('huoxin-money-with-history.reward_private_discussion', false);
        $this->rewardSelfLike = (bool) $this->settings->get('huoxin-money-with-history.reward_self_like', false);
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

    public function adjustDiscussionAuthorBalance(
        ?User $user,
        float $balanceDelta,
        Discussion $discussion,
        string $source = '',
        string $sourceKey = '',
        ?User $actor = null,
        array $sourceParams = []
    ): void {
        if ($user === null) {
            return;
        }

        $permissions = true;
        foreach ($discussion->tags ?? [] as $tag) {
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

    public function excludeMentionsFromLength(string $content): string
    {
        if (! $this->excludeMentionsFromLength) {
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

        $content = $this->excludeMentionsFromLength($event->post->content);
        if (
            $event->post->number > 1
            && mb_strlen($content) >= $this->minPostLength
        ) {
            $this->adjustPostAuthorBalance(
                $event->post->user,
                $this->postRewardAmount,
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

        $content = $this->excludeMentionsFromLength($event->post->content);
        if (
            $this->removeMoneyTrigger == self::AUTO_REMOVE_HIDDEN
            && $event->post->type == 'comment'
            && mb_strlen($content) >= $this->minPostLength
        ) {
            $this->adjustPostAuthorBalance(
                $event->post->user,
                $this->postRewardAmount,
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

        $content = $this->excludeMentionsFromLength($event->post->content);
        if (
            $this->removeMoneyTrigger == self::AUTO_REMOVE_HIDDEN
            && $event->post->type == 'comment'
            && mb_strlen($content) >= $this->minPostLength
        ) {
            $this->adjustPostAuthorBalance(
                $event->post->user,
                -1 * $this->postRewardAmount,
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

        $content = $this->excludeMentionsFromLength($event->post->content);

        $shouldRemove = ($this->removeMoneyTrigger == self::AUTO_REMOVE_DELETED) ||
            ($this->removeMoneyTrigger == self::AUTO_REMOVE_HIDDEN && $event->post->hidden_at === null);

        if (
            $shouldRemove
            && $event->post->type == 'comment'
            && mb_strlen($content) >= $this->minPostLength
        ) {
            $this->adjustPostAuthorBalance(
                $event->post->user,
                -1 * $this->postRewardAmount,
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

        $this->adjustDiscussionAuthorBalance(
            $event->discussion->user,
            $this->discussionRewardAmount,
            $event->discussion,
            self::SOURCE_DISCUSSION_WAS_STARTED,
            $this->sourceKey('discussion-reward'),
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

        if ($this->removeMoneyTrigger == self::AUTO_REMOVE_HIDDEN) {
            $this->adjustDiscussionAuthorBalance(
                $event->discussion->user,
                $this->discussionRewardAmount,
                $event->discussion,
                self::SOURCE_DISCUSSION_WAS_RESTORED,
                $this->sourceKey('discussion-restored'),
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

        if ($this->removeMoneyTrigger == self::AUTO_REMOVE_HIDDEN) {
            $this->adjustDiscussionAuthorBalance(
                $event->discussion->user,
                -$this->discussionRewardAmount,
                $event->discussion,
                self::SOURCE_DISCUSSION_WAS_HIDDEN,
                $this->sourceKey('discussion-hidden'),
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

        $shouldRemove = ($this->removeMoneyTrigger == self::AUTO_REMOVE_DELETED) ||
            ($this->removeMoneyTrigger == self::AUTO_REMOVE_HIDDEN && $event->discussion->hidden_at === null);

        if ($shouldRemove) {
            $this->adjustDiscussionAuthorBalance(
                $event->discussion->user,
                -$this->discussionRewardAmount,
                $event->discussion,
                self::SOURCE_DISCUSSION_WAS_DELETED,
                $this->sourceKey('discussion-deleted'),
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
        if (! $this->cascadeMoneyRemoval) {
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

                    $content = $this->excludeMentionsFromLength($post->content);
                    if (
                        mb_strlen($content) >= $this->minPostLength
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
                            $userDeltas[$user->id]['delta'] += ($multiply * $this->postRewardAmount);
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
        if ($event->post->user === null) {
            return;
        }

        if (! $this->rewardSelfLike && $event->post->user->id === $event->user->id) {
            return;
        }

        $this->adjustBalance(
            $event->post->user,
            $this->likeRewardAmount,
            self::SOURCE_POST_WAS_LIKED,
            $this->sourceKey('post-liked'),
            [],
            $event->user
        );
    }

    public function postWasUnliked($event): void
    {
        if ($event->post->user === null) {
            return;
        }

        if (! $this->rewardSelfLike && $event->post->user->id === $event->user->id) {
            return;
        }

        $this->adjustBalance(
            $event->post->user,
            -1 * $this->likeRewardAmount,
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

        $content = $this->excludeMentionsFromLength($post->content);
        if (
            $post->number > 1
            && mb_strlen($content) >= $this->minPostLength
        ) {
            $this->adjustPostAuthorBalance(
                $post->user,
                $this->postRewardAmount,
                $post,
                self::SOURCE_POST_WAS_POSTED,
                $this->sourceKey('post-reward'),
                $event->actor
            );
        }

        if ($post->number === 1 && $post->discussion) {
            $this->adjustBalance(
                $post->discussion->user,
                $this->discussionRewardAmount,
                self::SOURCE_DISCUSSION_WAS_STARTED,
                $this->sourceKey('discussion-reward'),
                [],
                $event->actor
            );
        }
    }
}
