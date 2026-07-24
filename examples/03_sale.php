<?php
/**
 * sale() — CCAUTHCAP
 *
 * Authorize and capture in one step. The common case for immediate fulfilment.
 * Use authorize() + capture() instead when you ship later.
 *
 * A DECLINE IS NOT AN ERROR — it returns normally with status DECLINED.
 */
require_once __DIR__ . '/_harness.php';

$r = client()->sale(buildRequest('SALE'));

show('status', $r->status);
show('order', $r->orderRef?->poId() ?? '-');
show('amount', $r->amount ? $r->amount->toWire() . ' ' . $r->amount->currency() : '-');
show('card', sprintf('%s ****%s', $r->card->brand ?? '?', $r->card->last4 ?? '?'));

switch ($r->status) {
    case 'APPROVED':
        show('next', 'fulfil the order');
        break;
    case 'DECLINED':
        // The service tier carries the decline taxonomy your dunning logic needs.
        show('next', $r->serviceClassification?->retryable ? 'retry later' : 'do not retry');
        break;
    case 'PENDING':
        show('next', 'complete ' . ($r->nextAction->kind ?? '?'));
        break;
    default:
        show('next', 'inspect $r->outcome');
}
