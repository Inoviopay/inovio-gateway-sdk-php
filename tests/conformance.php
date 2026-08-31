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

        // Fixtures carry either a flat field map or a CCSTATUS COLUMNS/DATA
        // table; both are replayed verbatim as the gateway would send them.
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
// CCSTATUS answers with a COLUMNS/DATA table (one row per leg), NOT flat
// indexed fields — verified against the live T1 gateway. Credit and void legs
// arrive with a NEGATIVE TRANS_VALUE.
$t = 'status/net-position-multi-leg';
$http = new MockHttp([
    'COLUMNS' => ['REQUEST_ACTION','TRANS_STATUS_NAME','TRANS_VALUE','TRANS_ID','PO_ID','XTL_ORDER_ID','CURR_CODE_ALPHA'],
    'DATA' => [
        ['CCAUTHORIZE','APPROVED',100.00,'T-1','PO-2000','ORD-2000','USD'],
        ['CCCAPTURE','APPROVED',60.00,'T-2','PO-2000','','USD'],
        ['CCCREDIT','APPROVED',-10.00,'T-3','PO-2000','','USD'],
    ],
]);
$s = client($http)->status(Refs::order('PO-2000'));
check($t, 'transactions.length', count($s->transactions), 3);
check($t, 'authorized', $s->authorized->toWire(), '100');
check($t, 'captured', $s->captured->toWire(), '60');
check($t, 'refunded', $s->refunded->toWire(), '10');
check($t, 'net', $s->net->toWire(), '50');
check($t, 'outstanding', $s->outstanding->toWire(), '40');

// A CCREVERSE is a VOID, not a refund: it releases the authorization rather
// than returning captured funds, so it must reduce `authorized`.
$t = 'status/voided-auth-nets-to-zero';
$http = new MockHttp([
    'COLUMNS' => ['REQUEST_ACTION','TRANS_STATUS_NAME','TRANS_VALUE','TRANS_ID','PO_ID','CURR_CODE_ALPHA'],
    'DATA' => [
        ['CCAUTHORIZE','APPROVED',2.00,'T-10','PO-3000','USD'],
        ['CCREVERSE','APPROVED',-2.00,'T-11','PO-3000','USD'],
    ],
]);
$s = client($http)->status(Refs::order('PO-3000'));
check($t, 'authorized released', $s->authorized->toWire(), '0');
check($t, 'net', $s->net->toWire(), '0');
check($t, 'outstanding', $s->outstanding->toWire(), '0');

// CCAUTHCAP authorizes AND captures in one leg — it must count as both.
$t = 'status/sale-counts-as-authorized-and-captured';
$http = new MockHttp([
    'COLUMNS' => ['REQUEST_ACTION','TRANS_STATUS_NAME','TRANS_VALUE','TRANS_ID','PO_ID','CURR_CODE_ALPHA'],
    'DATA' => [['CCAUTHCAP','APPROVED',1.00,'T-20','PO-4000','USD']],
]);
$s = client($http)->status(Refs::order('PO-4000'));
check($t, 'authorized', $s->authorized->toWire(), '1');
check($t, 'captured', $s->captured->toWire(), '1');
check($t, 'net', $s->net->toWire(), '1');
check($t, 'outstanding', $s->outstanding->toWire(), '0');

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

// ------------------------------------- empty-string fields (live-gateway bug)
$t = 'empty-string-fields/treated-as-absent';
// The gateway returns inapplicable fields as EMPTY STRINGS rather than omitting
// them (verified against live T1 on TESTGW/TESTAUTH). isset() treats those as
// present and hands "" to a reference constructor, which throws.
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '1.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-EMPTY',
    'TRANS_ID' => '', 'CUST_ID' => '', 'XTL_CUST_ID' => '', 'PMT_ID' => '',
    'BATCH_ID' => '', 'MERCH_ACCT_ID' => '', 'CARD_BRAND_NAME' => '',
    'PMT_L4' => '', 'AVS_RESPONSE' => '',
    'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
]);
$threw = null;
try { $r = client($http)->sale(basicRequest()); } catch (Throwable $e) { $threw = $e; }
check($t, 'must not throw on empty fields', $threw === null, true);
if ($threw === null) {
    check($t, 'status', $r->status, 'APPROVED');
    check($t, 'orderRef still parsed', $r->orderRef->poId(), 'PO-EMPTY');
    check($t, 'empty TRANS_ID -> null', $r->transactionId, null);
    check($t, 'empty CUST_ID -> null', $r->customerRef, null);
    check($t, 'empty PMT_ID -> null', $r->savedCardRef, null);
    check($t, 'empty BATCH_ID -> null', $r->batchId, null);
    check($t, 'all-empty card -> null', $r->card, null);
}

// ------------------------------------------------------------------ 3DS
// Wire contract verified against the deployed 3dsrequest.cfm + THREEDS_PKG
// source and the Inoviopay/3ds-tool-demo reference implementation.

$t = '3ds/prepare';
$http = new MockHttp([
    'REQ_ID' => 'R-3001', 'JWT' => 'jwt-abc',
    'DDC_URL' => 'https://centinelapistag.cardinalcommerce.com/V2/Cruise/Collect',
    'DDC_REFERENCEID' => 'R-3001-148', 'PMT_BIN' => '400000', 'MERCH_ACCT_ID' => '148',
]);
$ddc = client($http)->threeDSecure()->prepare(
    \Inovio\Gateway\ThreeDSPrepare::card(
        PaymentMethods::card('4000000000002503', '122028', '123'),
        'USD',
        'US'
    )
);
check($t, 'jwt', $ddc->jwt, 'jwt-abc');
check($t, 'ddcUrl', $ddc->ddcUrl, 'https://centinelapistag.cardinalcommerce.com/V2/Cruise/Collect');
check($t, 'ddcReferenceId', $ddc->ddcReferenceId, 'R-3001-148');
check($t, 'pmtBin', $ddc->pmtBin, '400000');
check($t, 'merchAcctId', $ddc->merchAcctId, '148');
check($t, 'wire: PAN sent', $http->lastParams['PMT_NUMB'], '4000000000002503');
check($t, 'wire: currency', $http->lastParams['REQUEST_CURRENCY'], 'USD');
check($t, 'wire: country', $http->lastParams['BILL_ADDR_COUNTRY'], 'US');
// The CFM derives 3DSINVOKEDDC from the auth path; sending an action would be wrong.
check($t, 'wire: no REQUEST_ACTION', array_key_exists('REQUEST_ACTION', $http->lastParams), false);

$t = '3ds/prepare-error';
$http = new MockHttp(['REQ_ID' => 'R-3002', 'API_RESPONSE' => '113', 'API_ADVICE' => 'Invalid PMT_ID']);
$threw = null;
try {
    client($http)->threeDSecure()->prepare(\Inovio\Gateway\ThreeDSPrepare::bin('400000', 'USD', 'US'));
} catch (Throwable $e) {
    $threw = $e;
}
check($t, 'API 113 -> ValidationException', $threw instanceof ValidationException, true);

$t = '3ds/enrollment-challenge';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'PENDING',
    'REQ_ID' => 'R-3003', 'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '820',
    'PROC_REDIRECT_URL' => 'https://centinelapistag.cardinalcommerce.com/V2/Cruise/StepUp',
    'P3DS_JWT' => 'challenge-jwt', 'P3DS_PROCTRANSID' => '3DS-77',
]);
$req = basicRequest();
$req->browser = new \Inovio\Gateway\Model\BrowserData(
    'en-US',
    'Mozilla/5.0',
    'text/html,application/xhtml+xml',
    javaEnabled: false,
    colorDepth: 24,
    screenHeight: 1080,
    screenWidth: 1920,
    timeZoneOffsetMinutes: 480
);
$req->threeDS = new \Inovio\Gateway\Model\ThreeDS('R-3001-148', 'https://merchant.example/3ds-return');
$r = client($http)->sale($req);
check($t, 'status', $r->status, 'PENDING');
check($t, 'nextAction kind', $r->nextAction?->kind, 'threeDSChallenge');
check($t, 'nextAction redirectUrl', $r->nextAction?->redirectUrl, 'https://centinelapistag.cardinalcommerce.com/V2/Cruise/StepUp');
check($t, 'nextAction jwt', $r->nextAction?->jwt, 'challenge-jwt');
check($t, 'nextAction procTransId', $r->nextAction?->procTransId, '3DS-77');
check($t, 'wire: REQUEST_ENROLLMENT', $http->lastParams['REQUEST_ENROLLMENT'], '1');
check($t, 'wire: DDC_REFERENCEID', $http->lastParams['DDC_REFERENCEID'], 'R-3001-148');
check($t, 'wire: P3DS_RETURN_URL', $http->lastParams['P3DS_RETURN_URL'], 'https://merchant.example/3ds-return');
check($t, 'wire: P3DS_VERSION', $http->lastParams['P3DS_VERSION'], '2');
check($t, 'wire: java TRUE/FALSE', $http->lastParams['P3DS_JAVA_ENABLED'], 'FALSE');
check($t, 'wire: color depth', $http->lastParams['P3DS_BROWSER_COLOR_DEPTH'], '24');
check($t, 'wire: tz positive', $http->lastParams['P3DS_BROWSER_TIME_ZONE'], '480');

$t = '3ds/enrollment-requires-browser';
$req = basicRequest();
$req->threeDS = new \Inovio\Gateway\Model\ThreeDS('R-1-1', 'https://merchant.example/r');
$threw = null;
try {
    client(new MockHttp([]))->sale($req);
} catch (Throwable $e) {
    $threw = $e;
}
check($t, 'missing browser -> ValidationException', $threw instanceof ValidationException, true);

$t = '3ds/complete';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '10.00', 'CURR_CODE_ALPHA' => 'USD', 'TRANS_ID' => 'T-3004',
    'PO_ID' => 'PO-3004', 'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
    'P3DS_ECI' => '05', 'P3DS_CAVV' => 'AAABBJkZUQAAAABjRWWZEEFgFz8=',
    'P3DS_TRANSID' => 'e21-67801d7ab7e1', 'P3DS_RESPONSE' => 'Frictionless Authentication',
]);
$req = basicRequest();
$req->browser = new \Inovio\Gateway\Model\BrowserData('en-US', 'Mozilla/5.0', 'text/html');
$req->threeDS = new \Inovio\Gateway\Model\ThreeDS('R-3001-148', 'https://merchant.example/3ds-return');
// The ACS RESPONSE may be empty; it must still be SENT as REQUEST_PARES=''.
$r = client($http)->threeDSecure()->completeSale(
    $req,
    new \Inovio\Gateway\Model\ThreeDSChallengeResult('3DS-77', '')
);
check($t, 'status', $r->status, 'APPROVED');
check($t, 'wire: P3DS_PROCTRANSID', $http->lastParams['P3DS_PROCTRANSID'], '3DS-77');
check($t, 'wire: empty REQUEST_PARES still sent', array_key_exists('REQUEST_PARES', $http->lastParams), true);
check($t, 'wire: P3DS_VERSION on completion', $http->lastParams['P3DS_VERSION'] ?? null, '2');
check($t, 'wire: enrollment cleared', array_key_exists('REQUEST_ENROLLMENT', $http->lastParams), false);
check($t, 'threeDS.eci', $r->threeDS?->eci, '05');
check($t, 'threeDS.cavv', $r->threeDS?->cavv, 'AAABBJkZUQAAAABjRWWZEEFgFz8=');
check($t, 'threeDS.response', $r->threeDS?->response, 'Frictionless Authentication');

$t = '3ds/external-provider';
$http = new MockHttp([
    'REQUEST_ACTION' => 'CCAUTHCAP', 'TRANS_STATUS_NAME' => 'APPROVED',
    'TRANS_VALUE' => '10.00', 'CURR_CODE_ALPHA' => 'USD', 'PO_ID' => 'PO-3005',
    'API_RESPONSE' => '0', 'SERVICE_RESPONSE' => '100',
]);
$req = basicRequest();
$req->threeDSResult = new \Inovio\Gateway\Model\ThreeDSResult('cavv-x', '05', 'ds-trans-1', '2', 'xid-1');
$r = client($http)->sale($req);
check($t, 'status', $r->status, 'APPROVED');
check($t, 'wire: P3DS_CAVV', $http->lastParams['P3DS_CAVV'], 'cavv-x');
check($t, 'wire: P3DS_ECI', $http->lastParams['P3DS_ECI'], '05');
check($t, 'wire: P3DS_TRANSID', $http->lastParams['P3DS_TRANSID'], 'ds-trans-1');
check($t, 'wire: P3DS_XID', $http->lastParams['P3DS_XID'], 'xid-1');

$t = '3ds/mutually-exclusive-blocks';
$req = basicRequest();
$req->browser = new \Inovio\Gateway\Model\BrowserData('en-US', 'UA', 'hdr');
$req->threeDS = new \Inovio\Gateway\Model\ThreeDS('R-1-1', 'https://merchant.example/r');
$req->threeDSResult = new \Inovio\Gateway\Model\ThreeDSResult('c', '05', 't');
$threw = null;
try {
    client(new MockHttp([]))->sale($req);
} catch (Throwable $e) {
    $threw = $e;
}
check($t, 'two blocks -> ValidationException', $threw instanceof ValidationException, true);

// ------------------------------------------------------------------ report
echo "\n";
foreach ($failures as $f) {
    echo "FAIL  {$f}\n";
}
echo sprintf("\n%d assertions passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
