<?php
/**
 * updateOrder() — CCTRANSUPDATE
 *
 * Attaches data to an order after the fact. The main use is receipts, which
 * Appendix G/J compliance requires for negative-option and trial billing.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Model\Metadata;
use Inovio\Gateway\Request\OrderUpdate;

$c = client();

$order = seedOrder($c, 'UPDATE');
show('order', $order->orderRef?->poId() ?? '-');

$u = new OrderUpdate('https://merchant.example.invalid/receipts/' . $order->orderRef->poId());
$u->metadata = new Metadata();
$u->metadata->udf = ['01' => 'fulfilled-2026-07-23', '02' => 'warehouse-B'];

$r = $c->updateOrder($order->orderRef, $u);
show('status', $r->status);
show('use', 'receipts for MCC 5968 / Visa trial compliance');
