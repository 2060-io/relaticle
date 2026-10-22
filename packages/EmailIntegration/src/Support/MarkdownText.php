<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

final class MarkdownText
{
    public static function escape(string $text): string
    {
        return (string) preg_replace('/[[:punct:]]/', '\\\\$0', $text);
    }
}
