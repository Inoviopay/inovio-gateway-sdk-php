<?php
/**
 * Timeout recovery — the pattern that prevents double charges.
 *
 * A timeout does NOT mean the transaction failed. It means the state is
 * UNKNOWN: the gateway may have approved it and lost the response. Retrying
 * blindly can charge the customer twice.
 *
 * Two mechanisms work together:
 *
 *  1. IDEMPOTENCY. Setting an order id defaults to RETURN_ORIGINAL, so a repeat
 *     returns the original result instead of creating a second charge.
 *  2. status(). GatewayTimeoutException carries your order id, so you can ask
 *     the gateway what actually happened.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Errors\GatewayTimeoutException;
use Inovio\Gateway\Transport\HttpClient;
use Inovio\Gateway\Transport\HttpResponse;
use Inovio\Gateway\Transport\TimeoutSignal;

/** A transport that always times out, so the example is deterministic. */
final class AlwaysTimesOut implements HttpClient
{
    public function post(string $url, string $body, array $headers, int $timeoutMs): HttpResponse
    {
        throw new TimeoutSignal('simulated');
    }
}

try {
    client(null, new AlwaysTimesOut(), 50)->sale(buildRequest('TIMEOUT'));
} catch (GatewayTimeoutException $e) {
    show('caught', 'GatewayTimeoutException');
    show('order id', $e->xtlOrderId() ?? '(none — cannot resolve)');
    show('guidance', $e->recoveryHint());

    // Resolve the true state instead of guessing:
    //   $actual = client()->status(Refs::xtlOrder($e->xtlOrderId()));
    show('do NOT', 'retry blindly — that risks a double charge');
}
