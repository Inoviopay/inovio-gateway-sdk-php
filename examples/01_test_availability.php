<?php
/**
 * testAvailability() — TESTGW
 *
 * Health check for the gateway itself. No credentials are validated and no
 * transaction is created, so it is safe to poll.
 */
require_once __DIR__ . '/_harness.php';

$h = client()->testAvailability();
show('ok', $h->ok);
show('service code', sprintf('%s "%s"', $h->outcome->service->code, $h->outcome->service->advice ?? ''));
