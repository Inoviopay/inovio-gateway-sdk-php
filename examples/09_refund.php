<?php
/**
 * refund() — CCCREDIT
 *
 * Returns captured funds to the cardholder. Pass an amount for a partial
 * refund; omit it to refund the full order.
 *
 * Refund legs arrive with a NEGATIVE amount. status() reports `refunded` as a
 * positive magnitude, so you rarely have to think about the sign.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Model\Money;

$c = client();

// You can only refund what was captured.
$order = seedOrder($c, 'REFUND', true);
show('captured order', $order->orderRef?->poId() ?? '-');

$r = $c->refund($order->orderRef, Money::of('10.00', 'USD'));
show('status', $r->status);
show('amount', ($r->amount?->toWire() ?? '-') . '   (negative on the wire)');

// Full refund instead:  $c->refund($order->orderRef);
