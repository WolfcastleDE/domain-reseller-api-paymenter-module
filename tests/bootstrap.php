<?php

require __DIR__ . '/../vendor/autoload.php';

if (!function_exists('config')) {
    /**
     * Minimal stand-in for Laravel's config() helper.
     */
    function config($key = null, $default = null)
    {
        $config = [
            'app.countries' => ['' => 'Select a country', 'DE' => 'Germany', 'AT' => 'Austria', 'CH' => 'Switzerland', 'US' => 'United States'],
        ];

        return $config[$key] ?? $default;
    }
}

if (!function_exists('report')) {
    function report($exception): void
    {
        $GLOBALS['reported'][] = $exception;
    }
}
