<?php
/**
 * capture() — CCCAPTURE
 *
 * Takes funds against a prior authorize(). Pass an amount to capture less than
 * was authorized; omit it to capture the full amount.
 *
 * Captures are separate transactions sharing the order, so an order may have
 * several. Use status() for the net position.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Model\Money;

$c = client();

$auth = $c->authorize(buildRequest('CAP'));
show('authorized', $auth->status . ' order=' . ($auth->orderRef?->poId() ?? '-'));

$cap = $c->capture($auth->orderRef, Money::of('10.00', 'USD'));
show('captured', $cap->status);
show('settled', var_export($cap->settled, true) . '  (batch flips this later — not a failure)');

// Or capture the full authorized amount:  $c->capture($auth->orderRef);
