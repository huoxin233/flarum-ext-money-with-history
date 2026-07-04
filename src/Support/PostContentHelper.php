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
        $pattern = '/@"[^"]+"#(p|d|g)?\d+/is';

        return trim((string) preg_replace($pattern, '', $content));
    }
}
