<?php

declare(strict_types=1);

/**
 * Cross-language conformance suite.
 *
 * Runs the shared fixtures in ../../spec/conformance-fixtures.json against a
 * mocked transport. Every SDK (Node, PHP, Python, Java) runs this same corpus
 * and must produce the same typed result — the mechanism keeping the
 * implementations honest (PLAN.md §5).
 *
 * Plain PHP rather than PHPUnit so the suite runs with no Composer install.
 */

require_once __DIR__ . '/autoload.php';

use Inovio\Gateway\Credentials;
use Inovio\Gateway\Errors\AuthenticationException;
use Inovio\Gateway\Errors\GatewayTimeoutException;
use Inovio\Gateway\Errors\ValidationException;
use Inovio\Gateway\InovioClient;
use Inovio\Gateway\Model\LineItem;
use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Model\PartialAuth;
use Inovio\Gateway\Model\PaymentMethods;
use Inovio\Gateway\Refs\Refs;
use Inovio\Gateway\Request\TransactionRequest;
use Inovio\Gateway\Transport\HttpClient;
use Inovio\Gateway\Transport\HttpResponse;
use Inovio\Gateway\Transport\TimeoutSignal;

/** Captures outgoing params and replays a canned response. */
final class MockHttp implements HttpClient
{
    /** @var array<string,string> */
    public array $lastParams = [];

    /** @param array<string,string> $response */
    public function __construct(private array $response = [], private bool $timeout = false)
    {
    }

    public function post(string $url, string $body, array $headers, int $timeoutMs): HttpResponse
    {
        parse_str($body, $parsed);
        $this->lastParams = array_map('strval', $parsed);
        if ($this->timeout) {
            throw new TimeoutSignal('simulated timeout');
        }

        return new HttpResponse(200, json_encode($this->response, JSON_THROW_ON_ERROR));
    }
}

$passed = 0;
$failed = 0;
$failures = [];

function check(string $test, string $what, $got, $want): void
{
    global $passed, $failed, $failures;
    $ok = $got === $want;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
        $failures[] = sprintf(
            '%s :: %s — expected %s, got %s',
            $test,
            $what,
            var_export($want, true),
            var_export($got, true)
        );
    }
}

function client(MockHttp $http): InovioClient
{
    return new InovioClient(
        new Credentials('u', 'p', '1'),
        'SANDBOX',
        'https://gateway.invalid/payment/pmt_service.cfm',
        $http,
        null,
        50
    );
}

function basicRequest(): TransactionRequest
{
    return new TransactionRequest(
        PaymentMethods::card('4111111111111111', '122030', '123'),
        [new LineItem('SKU-1', 1, Money::of('10.00', 'USD'))]
    );
}

// ---------------------------------------------------------------- approve
$t = 'approve/basic-sale';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '10.00', 'CURR_CODE_ALPHA' => 'USD', 'TRANS_ID' => 'T-1001',
    'PO_ID' => 'PO-1001', 'REQ_ID' => 'R-1001', 'API_RESPONSE' => '0',
    'SERVICE_RESPONSE' => '100', 'PROCESSOR_RESPONSE' => '00',
    'TRANS_SETTLED' => '0', 'CARD_BRAND_NAME' => 'VISA', 'PMT_L4' => '1111',
]);
$r = client($http)->sale(basicRequest());
check($t, 'status', $r->status, 'APPROVED');
check($t, 'settling', $r->settling, false);
check($t, 'settled', $r->settled, false);
check($t, 'orderRef.poId', $r->orderRef->poId(), 'PO-1001');
check($t, 'transactionId', $r->transactionId->value(), 'T-1001');
check($t, 'amount', $r->amount->toWire(), '10.00');
check($t, 'currency', $r->amount->currency(), 'USD');
check($t, 'outcome.service.code', $r->outcome->service->code, 100);
check($t, 'serviceClassification.approval', $r->serviceClassification->approval, true);
check($t, 'serviceClassification.terminal', $r->serviceClassification->terminal, false);
check($t, 'card.brand', $r->card->brand, 'VISA');
check($t, 'card.last4', $r->card->last4, '1111');
check($t, 'conversion absent', $r->conversion, null);
check($t, 'nextAction absent', $r->nextAction, null);

// ---------------------------------------------------------------- decline
$t = 'decline/service-tier-not-exception';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'DECLINED',
    'TRANS_VALUE' => '25.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1002',
    'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '600',
    'PROCESSOR_RESPONSE' => '05', 'PROCESSOR_ADVICE' => 'Do not honor',
]);
$threw = false;
try {
    $r = client($http)->sale(basicRequest());
} catch (Throwable $e) {
    $threw = true;
}
check($t, 'decline must NOT throw', $threw, false);
check($t, 'status', $r->status, 'DECLINED');
check($t, 'outcome.service.code', $r->outcome->service->code, 600);
check($t, 'processor.advice', $r->outcome->processor->advice, 'Do not honor');
check($t, 'terminal', $r->serviceClassification->terminal, true);
check($t, 'retryable', $r->serviceClassification->retryable, false);

$t = 'decline/retryable-640';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'DECLINED',
    'TRANS_VALUE' => '5.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1003',
    'SERVICE_RESPONSE' => '640', 'API_RESPONSE' => '0',
]);
$r = client($http)->sale(basicRequest());
check($t, 'retryable', $r->serviceClassification->retryable, true);
check($t, 'terminal', $r->serviceClassification->terminal, false);

$t = 'decline/stop-recurring-219';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'DECLINED',
    'TRANS_VALUE' => '9.99', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1004',
    'SERVICE_RESPONSE' => '219', 'API_RESPONSE' => '0',
]);
$r = client($http)->sale(new TransactionRequest(
    PaymentMethods::savedCard('PM-9'),
    [new LineItem('SKU-1', 1, Money::of('9.99', 'USD'))]
));
check($t, 'stopRecurring', $r->serviceClassification->stopRecurring, true);

// -------------------------------------------------------------- AVS / CVV
$t = 'avs/partial-street-match';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '1.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1005',
    'SERVICE_RESPONSE' => '100', 'API_RESPONSE' => '0',
    'AVS_RESPONSE' => 'A', 'CVV_RESPONSE' => 'M',
]);
$r = client($http)->sale(basicRequest());
check($t, 'avs.code', $r->avs->code, 'A');
check($t, 'avs.classification', $r->avs->classification, 'partial');
check($t, 'cvv.code', $r->cvv->code, 'M');
check($t, 'cvv.classification', $r->cvv->classification, 'match');

$t = 'avs/negative-no-match';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '1.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1006',
    'SERVICE_RESPONSE' => '100', 'API_RESPONSE' => '0',
    'AVS_RESPONSE' => 'N', 'CVV_RESPONSE' => 'N',
]);
$r = client($http)->sale(basicRequest());
check($t, 'avs.classification', $r->avs->classification, 'negative');
check($t, 'cvv.classification', $r->cvv->classification, 'no_match');

// ------------------------------------------------------------------- 3DS
$t = '3ds/challenge-pending';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'PENDING',
    'TRANS_VALUE' => '50.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1007',
    'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
    'PROC_REDIRECT_URL' => 'https://acs.example.invalid/challenge',
    'P3DS_PROCTRANSID' => '3DS-77', 'PAREQ' => 'eJxVUk1v',
]);
$r = client($http)->sale(basicRequest());
check($t, 'status', $r->status, 'PENDING');
check($t, 'settling', $r->settling, true);
check($t, 'nextAction.kind', $r->nextAction->kind, 'threeDSChallenge');
check($t, 'nextAction.procTransId', $r->nextAction->procTransId, '3DS-77');
check($t, 'nextAction.redirectUrl', $r->nextAction->redirectUrl, 'https://acs.example.invalid/challenge');

$t = '3ds/frictionless-approved';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '50.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1008',
    'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
]);
$r = client($http)->sale(basicRequest());
check($t, 'status', $r->status, 'APPROVED');
check($t, 'settling', $r->settling, false);
check($t, 'nextAction absent', $r->nextAction, null);

// ---------------------------------------------------------- multicurrency
$t = 'multicurrency/conversion-present';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '100.00', 'CURR_CODE_ALPHA' => 'EUR',
    'TRANS_VALUE_SETTLED' => '108.50', 'CURR_CODE_ALPHA_SETTLED' => 'USD',
    'TRANS_EXCH_RATE' => '1.085', 'PO_ID' => 'PO-1009',
    'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
]);
$r = client($http)->sale(new TransactionRequest(
    PaymentMethods::card('4111111111111111', '122030'),
    [new LineItem('SKU-1', 1, Money::of('100.00', 'EUR'))]
));
check($t, 'conversion.amount', $r->conversion->amount->toWire(), '108.50');
check($t, 'conversion.currency', $r->conversion->amount->currency(), 'USD');
check($t, 'conversion.exchangeRate', $r->conversion->exchangeRate, '1.085');

$t = 'multicurrency/no-conversion-domestic';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '20.00', 'CURR_CODE_ALPHA' => 'USD',
    'TRANS_VALUE_SETTLED' => '20.00', 'CURR_CODE_ALPHA_SETTLED' => 'USD',
    'PO_ID' => 'PO-1010', 'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
]);
$r = client($http)->sale(basicRequest());
check($t, 'conversion absent on domestic', $r->conversion, null);

// ------------------------------------------------- partial / idempotency
$t = 'partial-auth/approved-lesser-amount';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHORIZE', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '40.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1011',
    'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
]);
$req = new TransactionRequest(
    PaymentMethods::card('4111111111111111', '122030'),
    [new LineItem('SKU-1', 1, Money::of('100.00', 'USD'))]
);
$req->partialAuth = new PartialAuth(true);
$req->partialAuth->minimumAmount = Money::of('25.00', 'USD');
$r = client($http)->authorize($req);
check($t, 'PARTIAL_AUTH param', $http->lastParams['PARTIAL_AUTH'] ?? null, '1');
check($t, 'PARTIAL_AUTH_MIN param', $http->lastParams['PARTIAL_AUTH_MIN'] ?? null, '25.00');
check($t, 'amount', $r->amount->toWire(), '40.00');

$t = 'idempotency/defaults-to-return-original';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '7.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-1012',
    'XTL_ORDER_ID' => 'ORD-555', 'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
]);
$r = client($http)->sale(basicRequest()->withIdempotency('ORD-555'));
check($t, 'XTL_ORDER_ID param', $http->lastParams['XTL_ORDER_ID'] ?? null, 'ORD-555');
check($t, 'UNIQUE_XTL_ORDER_ID param', $http->lastParams['UNIQUE_XTL_ORDER_ID'] ?? null, '2');
check($t, 'xtlOrderRef', $r->xtlOrderRef->value(), 'ORD-555');

// ------------------------------------------------------------- api errors
$t = 'api-error/authentication-throws';
$http = new MockHttp(['API_RESPONSE' => '101', 'API_ADVICE' => 'Invalid login information']);
$caught = null;
try {
    client($http)->sale(basicRequest());
} catch (Throwable $e) {
    $caught = $e;
}
check($t, 'throws AuthenticationException', $caught instanceof AuthenticationException, true);

$t = 'api-error/validation-carries-reffield';
$http = new MockHttp([
    'API_RESPONSE' => '110', 'API_ADVICE' => 'Required field', 'REF_FIELD' => 'CUST_EMAIL',
]);
$caught = null;
try {
    client($http)->sale(basicRequest());
} catch (Throwable $e) {
    $caught = $e;
}
check($t, 'throws ValidationException', $caught instanceof ValidationException, true);
check($t, 'error.refField', $caught instanceof ValidationException ? $caught->refField() : null, 'CUST_EMAIL');

$t = 'timeout/unknown-state-carries-key';
$http = new MockHttp([], true);
$caught = null;
try {
    client($http)->sale(basicRequest()->withIdempotency('ORD-TIMEOUT-1'));
} catch (Throwable $e) {
    $caught = $e;
}
check($t, 'throws GatewayTimeoutException', $caught instanceof GatewayTimeoutException, true);
check($t, 'error.xtlOrderId', $caught instanceof GatewayTimeoutException ? $caught->xtlOrderId() : null, 'ORD-TIMEOUT-1');

// ------------------------------------------------------------------ status
$t = 'status/net-position-multi-leg';
$http = new MockHttp([
    'PO_ID' => 'PO-2000', 'CURR_CODE_ALPHA' => 'USD', 'API_RESPONSE' => '0',
    'REQUEST_ACTION_1' => 'CCAUTHORIZE', 'TRANS_STATUS_NAME_1' => 'APPROVED',
    'TRANS_VALUE_1' => '100.00', 'TRANS_ID_1' => 'T-1',
    'REQUEST_ACTION_2' => 'CCCAPTURE', 'TRANS_STATUS_NAME_2' => 'APPROVED',
    'TRANS_VALUE_2' => '60.00', 'TRANS_ID_2' => 'T-2',
    'REQUEST_ACTION_3' => 'CCCREDIT', 'TRANS_STATUS_NAME_3' => 'APPROVED',
    'TRANS_VALUE_3' => '10.00', 'TRANS_ID_3' => 'T-3',
]);
$s = client($http)->status(Refs::order('PO-2000'));
check($t, 'transactions.length', count($s->transactions), 3);
check($t, 'authorized', $s->authorized->toWire(), '100');
check($t, 'captured', $s->captured->toWire(), '60');
check($t, 'refunded', $s->refunded->toWire(), '10');
check($t, 'net', $s->net->toWire(), '50');
check($t, 'outstanding', $s->outstanding->toWire(), '40');

// ------------------------------------------------------------------- misc
$t = 'money/rejects-float';
$caught = null;
try {
    Money::of(1.25, 'USD');
} catch (Throwable $e) {
    $caught = $e;
}
check($t, 'throws on float', $caught instanceof InvalidArgumentException, true);

$t = 'unknown-status/does-not-read-as-approved';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'WAT',
    'PO_ID' => 'PO-1013', 'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
]);
$r = client($http)->sale(basicRequest());
check($t, 'not APPROVED', $r->status !== 'APPROVED', true);

// ------------------------------------------- generated enums cross-language
$t = 'generated-enums';
check($t, 'spec version', \Inovio\Gateway\Enums\Generated::SPEC_API_VERSION, '4.14');
check($t, 'status count', count(\Inovio\Gateway\Enums\Generated::TRANSACTION_STATUSES), 5);
check($t, '640 retryable', \Inovio\Gateway\Enums\Generated::SERVICE_RESPONSE_CODES[640]['retryable'], true);
check($t, '219 stopRecurring', \Inovio\Gateway\Enums\Generated::SERVICE_RESPONSE_CODES[219]['stopRecurring'], true);
check($t, 'AVS A partial', \Inovio\Gateway\Enums\Generated::AVS_CODES['A']['classification'], 'partial');
check($t, 'AVS N negative', \Inovio\Gateway\Enums\Generated::AVS_CODES['N']['classification'], 'negative');
check($t, 'AVS X positive', \Inovio\Gateway\Enums\Generated::AVS_CODES['X']['classification'], 'positive');

// ------------------------------------------------------------------ report
echo "\n";
foreach ($failures as $f) {
    echo "FAIL  {$f}\n";
}
echo sprintf("\n%d assertions passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
