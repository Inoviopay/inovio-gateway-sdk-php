<?php
/**
 * authorize() — CCAUTHORIZE
 *
 * Places a hold without taking funds. Pair with capture() when you ship, or
 * reverse() to release the hold.
 *
 * Keep $result->orderRef — every follow-up operation consumes it.
 */
require_once __DIR__ . '/_harness.php';

$auth = client()->authorize(buildRequest('AUTH'));

show('status', $auth->status);
show('order', $auth->orderRef?->poId() ?? '-');
show('line items', implode(', ', array_map(fn ($l) => $l->poLiId(), $auth->lineItemRefs)) ?: '-');
show('avs', $auth->avs ? "{$auth->avs->code} ({$auth->avs->classification})" : '-');
show('cvv', $auth->cvv ? "{$auth->cvv->code} ({$auth->cvv->classification})" : '-');

// AVS 'partial' means some elements matched and some did not. Whether that is
// acceptable is YOUR risk policy — the SDK reports, it does not decide.
if ($auth->avs?->classification === 'partial') {
    show('note', 'partial AVS match — apply your risk policy');
}
