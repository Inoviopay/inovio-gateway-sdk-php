<?php
/**
 * captureLineItem() — CCCAPTURE against one line item
 *
 * For multi-item orders shipped separately: capture each line item as it goes
 * out, rather than capturing an amount against the whole order.
 *
 * The gateway requires the PARENT ORDER and an amount alongside the line-item
 * id (spec §5.5.6) — passing the line-item ref alone is rejected.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Model\LineItem;
use Inovio\Gateway\Model\Money;

$c = client();

$auth = $c->authorize(buildRequest('LI', '10.00', [
    new LineItem(demoProductId(), 1, Money::of('10.00', 'USD')),
    new LineItem(demoProductId(), 1, Money::of('5.00', 'USD')),
]));
show('authorized', $auth->status . ' lineItems=' . count($auth->lineItemRefs));

if ($auth->lineItemRefs === []) {
    show('note', 'gateway returned no line-item refs for this order');

    return;
}

$first = $auth->lineItemRefs[0];
// order + item + amount — all three are required.
$captured = $c->captureLineItem($auth->orderRef, $first, Money::of('10.00', 'USD'));
show('captured item', $first->poLiId() . ' -> ' . $captured->status);

$s = $c->status($auth->orderRef);
show('outstanding', $s->outstanding->toWire() . '   (the unshipped line item)');
