<?php
/**
 * reverse() — CCREVERSE
 *
 * VOIDS an authorization, releasing the hold. This is not a refund: nothing was
 * captured, so nothing is returned. Use it when an order is cancelled before
 * shipping.
 *
 * To void a CAPTURE instead, use reverseCapture().
 */
require_once __DIR__ . '/_harness.php';

$c = client();

$auth = $c->authorize(buildRequest('REV'));
show('authorized', $auth->status . ' order=' . ($auth->orderRef?->poId() ?? '-'));

$voided = $c->reverse($auth->orderRef);
show('reversed', $voided->status);
// Void legs come back with a negative amount.
show('amount', $voided->amount?->toWire() ?? '-');
show('effect', 'authorization released — order nets to zero');
