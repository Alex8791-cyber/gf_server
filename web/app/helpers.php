<?php

declare(strict_types=1);

if (!function_exists('e')) {
    /** HTML-escape a value for safe output in a template. */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
