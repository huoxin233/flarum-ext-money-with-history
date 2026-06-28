<?php

namespace Huoxin\MoneyWithHistory\Job;

use Flarum\Discussion\Discussion;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Huoxin\MoneyWithHistory\Service\BalanceManager;

class CascadeDiscussionMoney extends AbstractJob
{
    public function __construct(
        private int $discussionId,
        private int $multiply,
        private string $source,
        private string $sourceKey,
        private ?int $actorId = null
    ) {
    }

    public function handle(SettingsRepositoryInterface $settings, BalanceManager $balances): void
    {
        $discussion = Discussion::find($this->discussionId);
        
        if (! $discussion) {
            return;
        }

        $actor = $this->actorId ? User::find($this->actorId) : null;
        
        $postRewardAmount = (float) $settings->get('huoxin-money-with-history.post_reward_amount', 0);
        $minPostLength = (int) $settings->get('huoxin-money-with-history.min_post_length', 0);
        $excludeMentionsSetting = (bool) $settings->get('huoxin-money-with-history.exclude_mentions_from_length', false);

        $userDeltas = [];
        $tags = $discussion->tags ?? [];

        $discussion->posts()
            ->with(['user', 'user.groups'])
            ->where('type', 'comment')
            ->chunk(200, function ($posts) use (&$userDeltas, $tags, $postRewardAmount, $minPostLength, $excludeMentionsSetting) {
                foreach ($posts as $post) {
                    $user = $post->user;
                    if ($user === null) {
                        continue;
                    }

                    $content = $this->excludeMentionsFromLength($post->content, $excludeMentionsSetting);
                    if (
                        mb_strlen($content) >= $minPostLength
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
                                $userDeltas[$user->id] = 0.0;
                            }
                            $userDeltas[$user->id] += ($this->multiply * $postRewardAmount);
                        }
                    }
                }
            });

        // Process users in safe memory chunks
        $userIds = array_keys($userDeltas);

        foreach (array_chunk($userIds, 500) as $chunkedIds) {
            $usersById = User::whereIn('id', $chunkedIds)->get()->keyBy('id');
            $usersByDelta = [];

            foreach ($chunkedIds as $id) {
                if (! isset($usersById[$id])) {
                    continue;
                }

                $delta = $userDeltas[$id];
                $deltaString = (string) $delta;
                
                if (! isset($usersByDelta[$deltaString])) {
                    $usersByDelta[$deltaString] = [
                        'delta' => $delta,
                        'users' => []
                    ];
                }
                $usersByDelta[$deltaString]['users'][] = $usersById[$id];
            }

            foreach ($usersByDelta as $group) {
                $balances->adjustBalances($group['users'], $group['delta'], $this->source, $this->sourceKey, [], $actor);
            }
        }
    }
    
    private function excludeMentionsFromLength(string $content, bool $shouldExclude): string
    {
        if (! $shouldExclude) {
            return $content;
        }

        $pattern = '/@.*?(#\d+|#p\d+)/';

        return trim(str_replace(["\r", "\n"], '', preg_replace($pattern, '', $content)));
    }
}
