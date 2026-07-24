<?php

declare(strict_types=1);

/**
 * Shared harness for the runnable examples.
 *
 * Every example is real, executed code — not a markdown snippet — so it cannot
 * silently drift from the API. `php examples/run_all.php` runs them all.
 *
 * By default they run against a MOCK transport: no credentials, no network, no
 * money moves, safe in CI. Set INOVIO_LIVE=1 (plus credentials) to run the same
 * code against the real gateway.
 */

require_once __DIR__ . '/../tests/autoload.php';

use Inovio\Gateway\Credentials;
use Inovio\Gateway\InovioClient;
use Inovio\Gateway\Model\Address;
use Inovio\Gateway\Model\Customer;
use Inovio\Gateway\Model\Idempotency;
use Inovio\Gateway\Model\LineItem;
use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Model\PaymentMethods;
use Inovio\Gateway\Request\TransactionRequest;
use Inovio\Gateway\Transport\HttpClient;
use Inovio\Gateway\Transport\HttpResponse;

const LIVE = false;

function isLive(): bool
{
    return getenv('INOVIO_LIVE') === '1';
}

function envOr(string $key, string $fallback): string
{
    $v = getenv($key);

    return ($v === false || $v === '') ? $fallback : $v;
}

/** Canned responses shaped like the real gateway. */
final class MockHttp implements HttpClient
{
    public function post(string $url, string $body, array $headers, int $timeoutMs): HttpResponse
    {
        if (str_contains($url, 'token_service')) {
            return new HttpResponse(200, json_encode([
                'TOKEN_GUID' => 'F76E1864D6E018BA5D98080167CDF86AD432FEBD',
                'TOKEN_IP' => '10.13.100.134', 'TOKEN_REQID' => '4283012',
                'CARD_BRAND_NAME' => 'Visa', 'CARD_TYPE' => 'VISA TRADITIONAL',
                'CARD_BANK' => 'CHASE BANK USA', 'CARD_COUNTRY' => 'USA',
                'CARD_ACCOUNT_FUND_SOURCE' => 'Credit', 'CARD_CLASS' => 'CONSUMER',
            ], JSON_THROW_ON_ERROR));
        }
        parse_str($body, $p);
        $action = $p['REQUEST_ACTION'] ?? '';

        if ($action === 'CCSTATUS') {
            // CCSTATUS answers with a COLUMNS/DATA table, not flat fields.
            return new HttpResponse(200, json_encode([
                'COLUMNS' => ['REQUEST_ACTION','TRANS_STATUS_NAME','TRANS_VALUE','TRANS_ID','PO_ID','CURR_CODE_ALPHA'],
                'DATA' => [
                    ['CCAUTHORIZE','APPROVED',100.00,'T-1','18800001','USD'],
                    ['CCCAPTURE','APPROVED',60.00,'T-2','18800001','USD'],
                    ['CCCREDIT','APPROVED',-10.00,'T-3','18800001','USD'],
                ],
            ], JSON_THROW_ON_ERROR));
        }

        $negative = str_contains($action, 'REVERSE') || str_contains($action, 'CREDIT');

        return new HttpResponse(200, json_encode([
            'REQUEST_ACTION' => $action, 'TRANS_STATUS_NAME' => 'APPROVED',
            'TRANS_VALUE' => $negative ? '-10.00' : '10.00', 'CURR_CODE_ALPHA' => 'USD',
            'PO_ID' => '18800001', 'TRANS_ID' => '2000000001',
            'PO_LI_ID_1' => '9000001', 'PO_LI_ID_2' => '9000002',
            'API_RESPONSE' => '0',
            'SERVICE_RESPONSE' => $action === 'TESTGW' ? '101' : '100',
            'SERVICE_ADVICE' => 'OK',
            'CARD_BRAND_NAME' => 'Visa', 'PMT_L4' => '0647',
            'AVS_RESPONSE' => 'Y', 'CVV_RESPONSE' => 'M',
        ], JSON_THROW_ON_ERROR));
    }
}

function client(?string $siteIdOverride = null, ?HttpClient $httpOverride = null, int $timeoutMs = 60000): InovioClient
{
    $siteId = $siteIdOverride ?? (isLive() ? (getenv('INOVIO_SITE_ID') ?: '') : '100103');
    $creds = isLive()
        ? new Credentials((string) getenv('INOVIO_USER'), (string) getenv('INOVIO_PASS'),
            $siteId, getenv('INOVIO_MERCH_ACCT_ID') ?: null)
        : new Credentials('demo@example.invalid', 'demo', $siteId);

    return new InovioClient(
        $creds,
        'SANDBOX',
        envOr('INOVIO_ENDPOINT', 'https://t1api.inoviopay.com/payment/pmt_service.cfm'),
        $httpOverride ?? (isLive() ? null : new MockHttp()),
        null,
        $timeoutMs,
        null,
        envOr('INOVIO_SITE_KEY', 'demo-site-key')
    );
}

/**
 * The token service authenticates per SITE with an HMAC key, independent of the
 * gateway's username/password. Normally the same site — but on a shared test rig
 * they can differ.
 */
function tokenClient(): InovioClient
{
    $s = getenv('INOVIO_TOKEN_SITE_ID');

    return client($s === false || $s === '' ? null : $s);
}

function demoPan(): string
{
    return envOr('INOVIO_TEST_PAN', '4622943123100647');
}

function demoExpiry(): string
{
    return envOr('INOVIO_TEST_EXPIRY', '122026');
}

function demoCvv(): string
{
    return envOr('INOVIO_TEST_CVV', '242');
}

function demoProductId(): string
{
    return envOr('INOVIO_TEST_PRODUCT_ID', '111205');
}

function orderId(string $tag): string
{
    return 'EXAMPLE-' . $tag . '-' . round(microtime(true) * 1000);
}

/** @param LineItem[]|null $lineItems */
function buildRequest(string $tag, string $amount = '10.00', ?array $lineItems = null, $paymentMethod = null): TransactionRequest
{
    $r = new TransactionRequest(
        $paymentMethod ?? PaymentMethods::card(demoPan(), demoExpiry(), demoCvv()),
        $lineItems ?? [new LineItem(demoProductId(), 1, Money::of($amount, 'USD'))]
    );
    $r->customer = new Customer();
    $r->customer->firstName = 'Ada';
    $r->customer->lastName = 'Lovelace';
    $r->customer->email = 'ada@example.invalid';
    // The processor rejects a missing IP with 'remote_ip is missing'.
    $r->customer->ip = '203.0.113.10';
    $r->billingAddress = new Address();
    $r->billingAddress->line1 = '123 Main St';
    $r->billingAddress->city = 'Austin';
    $r->billingAddress->state = 'TX';
    $r->billingAddress->zip = '78701';
    // Country is processor-required despite not being marked so in the spec.
    $r->billingAddress->country = 'US';
    $r->idempotency = new Idempotency(orderId($tag));

    return $r;
}

/**
 * Create a real order to operate on. Follow-up operations need an order that
 * actually exists, so examples build their own rather than hardcoding an id
 * that resolves only against a mock.
 */
function seedOrder(InovioClient $c, string $tag, bool $capture = false, string $amount = '10.00')
{
    $auth = $c->authorize(buildRequest($tag, $amount));
    if ($capture && $auth->orderRef !== null) {
        $c->capture($auth->orderRef, Money::of($amount, 'USD'));
    }

    return $auth;
}

function show(string $label, $value): void
{
    printf("  %-22s %s\n", $label, is_bool($value) ? var_export($value, true) : (string) $value);
}
