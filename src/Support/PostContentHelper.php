<?php

namespace Huoxin\MoneyWithHistory\Support;

class PostContentHelper
{
    /**
     * Strips Flarum post mentions from the given content string.
     *
     * @param string $content
     * @return string
     */
    public static function stripMentions(string $content): string
    {
        $pattern = '/@.*?(#\d+|#p\d+)/';

        return trim((string) preg_replace($pattern, '', $content));
    }
}
