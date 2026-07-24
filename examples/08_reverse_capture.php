<?php
/**
 * reverseCapture() — CCREVERSECAP
 *
 * VOIDS a capture rather than the original authorization. Reach for this when
 * you captured in error and the batch has not settled yet.
 *
 * After settlement, refund() is the correct operation instead.
 */
require_once __DIR__ . '/_harness.php';

$c = client();

$order = seedOrder($c, 'REVCAP', true);
show('captured order', $order->orderRef?->poId() ?? '-');

$r = $c->reverseCapture($order->orderRef);
show('status', $r->status);
show('amount', $r->amount?->toWire() ?? '-');
show('when to use', 'capture made in error, before batch settlement');
