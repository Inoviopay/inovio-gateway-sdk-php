<?php
/**
 * Runs every example in order.
 *
 * Mock transport by default. Set INOVIO_LIVE=1 with credentials to run the same
 * code against the real gateway.
 */
require_once __DIR__ . '/_harness.php';

$files = glob(__DIR__ . '/[0-9][0-9]_*.php');
sort($files);

printf("Running %d examples against %s\n\n", count($files),
    isLive() ? 'the LIVE gateway' : 'a mock transport');

$failed = 0;
foreach ($files as $f) {
    $title = str_replace('_', ' ', preg_replace('/^\d\d_|\.php$/', '', basename($f)));
    echo "── {$title}\n";
    try {
        (static fn () => require $f)();
    } catch (Throwable $e) {
        $failed++;
        printf("  ✗ %s: %s\n", get_class($e), $e->getMessage());
    }
    echo "\n";
}

echo $failed === 0
    ? "✅ all " . count($files) . " examples ran\n"
    : "❌ {$failed} of " . count($files) . " failed\n";
exit($failed === 0 ? 0 : 1);
