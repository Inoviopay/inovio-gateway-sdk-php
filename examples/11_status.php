<?php
/**
 * status() — CCSTATUS
 *
 * Two distinct jobs:
 *
 *  1. RECONCILIATION. Partial captures, refunds and voids are separate
 *     transactions sharing one order — so the net position is an order-level
 *     question. One TransactionResult cannot answer "what did this order
 *     actually settle for". This can.
 *
 *  2. TIMEOUT RECOVERY. After a timeout the state is unknown; status() resolves
 *     it. See 14_timeout_recovery.php.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Model\Money;

$c = client();

// Build a multi-leg order: authorize 100, capture 60, refund 10.
$order = seedOrder($c, 'STATUS', false, '100.00');
$c->capture($order->orderRef, Money::of('60.00', 'USD'));
$c->refund($order->orderRef, Money::of('10.00', 'USD'));

$s = $c->status($order->orderRef);

show('legs', count($s->transactions));
show('authorized', $s->authorized->toWire());
show('captured', $s->captured->toWire());
show('refunded', $s->refunded->toWire());
show('net', $s->net->toWire() . '   (captured - refunded)');
show('outstanding', $s->outstanding->toWire() . '   (authorized - captured)');

echo "\n  legs:\n";
foreach ($s->transactions as $leg) {
    printf("    %-14s %-9s %s\n", $leg->action, $leg->status, $leg->amount?->toWire() ?? '-');
}

// You can also look an order up by YOUR id:  $c->status(Refs::xtlOrder('ORDER-555'));
