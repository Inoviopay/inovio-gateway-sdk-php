<?php
/**
 * testAuth() — TESTAUTH
 *
 * Verifies your credentials without creating a transaction. Bad credentials
 * throw AuthenticationException (API tier 101), not a decline.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Errors\AuthenticationException;

try {
    $h = client()->testAuth();
    show('ok', $h->ok);
    show('service code', sprintf('%s "%s"', $h->outcome->service->code, $h->outcome->service->advice ?? ''));
} catch (AuthenticationException $e) {
    show('rejected', $e->getMessage());
}
