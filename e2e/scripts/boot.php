<?php
// Boots Paymenter (Laravel) for CLI test scripts and provides a tiny assertion helper.
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$GLOBALS['failures'] = 0;
function ok(string $label, bool $condition): void
{
    echo ($condition ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m ") . $label . PHP_EOL;
    if (!$condition) {
        $GLOBALS['failures']++;
    }
}
function section(string $title): void
{
    echo PHP_EOL . "\033[1m" . $title . "\033[0m" . PHP_EOL;
}
