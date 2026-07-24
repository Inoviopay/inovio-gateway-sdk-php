<?php
/**
 * forceCredit() — CCCREDIT + FORCE_CREDIT
 *
 * Pushes money to a card with NO original transaction to reference. Use it for
 * goodwill payments, or to refund an order taken outside the gateway.
 *
 * Because nothing constrains the amount, merchant accounts must have this
 * enabled explicitly. If it is NOT enabled the gateway rejects at the API tier
 * with 104 "Invalid service action" — an AuthenticationException, not a
 * decline. Observed on live T1 with a standard test account.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Errors\AuthenticationException;

try {
    $r = client()->forceCredit(buildRequest('FORCE'));
    show('status', $r->status);
    show('amount', $r->amount?->toWire() ?? '-');
    if ($r->status === 'DECLINED') {
        show('service code', sprintf('%s "%s"', $r->outcome->service->code, $r->outcome->service->advice ?? ''));
    }
} catch (AuthenticationException $e) {
    show('rejected', $e->getMessage());
    show('cause', 'FORCE_CREDIT is not enabled on this merchant account');
    show('fix', 'ask Inovio support to enable it for the MID');
}
