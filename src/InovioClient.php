<?php

declare(strict_types=1);

namespace Inovio\Gateway;

use Inovio\Gateway\Enums\Generated;
use Inovio\Gateway\Errors\AuthenticationException;
use Inovio\Gateway\Errors\ConfigurationException;
use Inovio\Gateway\Errors\RateLimitException;
use Inovio\Gateway\Errors\ValidationException;
use Inovio\Gateway\Model\Card;
use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Model\PaymentMethods;
use Inovio\Gateway\Model\Token;
use Inovio\Gateway\Refs\LineItemRef;
use Inovio\Gateway\Refs\OrderRef;
use Inovio\Gateway\Refs\XtlOrderId;
use Inovio\Gateway\Request\OrderUpdate;
use Inovio\Gateway\Request\RequestBuilder;
use Inovio\Gateway\Request\TransactionRequest;
use Inovio\Gateway\Result\HealthResult;
use Inovio\Gateway\Result\OrderStatus;
use Inovio\Gateway\Result\ResultMapper;
use Inovio\Gateway\Result\TransactionResult;
use Inovio\Gateway\Transport\CurlHttpClient;
use Inovio\Gateway\Transport\HttpClient;
use Inovio\Gateway\Transport\Transport;

/** Gateway credentials. */
final class Credentials
{
    public function __construct(
        public string $reqUsername,
        public string $reqPassword,
        public string $siteId,
        public ?string $merchAcctId = null
    ) {
        if ($reqUsername === '' || $reqPassword === '' || $siteId === '') {
            throw new ValidationException(
                'credentials require reqUsername, reqPassword and siteId'
            );
        }
    }
}

/**
 * The v1 card-core surface (object model §3.1).
 *
 * Partners call $client->sale(), never REQUEST_ACTION=CCAUTHCAP.
 */
final class InovioClient
{
    private const DEFAULT_TIMEOUT_MS = 120000;

    private string $endpoint;
    private string $tokenEndpoint;
    private string $apiVersion;
    private int $timeoutMs;
    private HttpClient $http;

    public function __construct(
        private Credentials $credentials,
        string $environment = 'SANDBOX',
        ?string $endpoint = null,
        ?HttpClient $httpClient = null,
        ?string $apiVersion = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?string $tokenEndpoint = null
    ) {
        $this->endpoint = $endpoint
            ?? ($environment === 'PRODUCTION'
                ? Transport::PRODUCTION_ENDPOINT
                : Transport::SANDBOX_ENDPOINT);
        $this->tokenEndpoint = $tokenEndpoint
            ?? (string) preg_replace('/pmt_service\.cfm$/', 'token_service.cfm', $this->endpoint);
        $this->apiVersion = $apiVersion ?? Generated::SPEC_API_VERSION;
        $this->timeoutMs = $timeoutMs;
        $this->http = $httpClient ?? new CurlHttpClient();
    }

    /** CCAUTHCAP — authorize and capture in one step. */
    public function sale(TransactionRequest $req): TransactionResult
    {
        return $this->transact('CCAUTHCAP', $req);
    }

    /** CCAUTHORIZE — authorization only; capture later. */
    public function authorize(TransactionRequest $req): TransactionResult
    {
        return $this->transact('CCAUTHORIZE', $req);
    }

    /** CCCAPTURE — capture a previous authorization. Partial-capable. */
    public function capture(OrderRef $order, ?Money $amount = null): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCCAPTURE', $this->refParams('REQUEST_REF_PO_ID', $order->poId(), $amount))
        );
    }

    /** CCCAPTURE against a single line item. */
    public function captureLineItem(LineItemRef $item, ?Money $amount = null): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCCAPTURE', $this->refParams('REQUEST_REF_PO_LI_ID', $item->poLiId(), $amount))
        );
    }

    /** CCREVERSE — void the original authorization. */
    public function reverse(OrderRef $order): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCREVERSE', ['REQUEST_REF_PO_ID' => $order->poId()])
        );
    }

    /** CCREVERSECAP — void a CCCAPTURE (not the original auth). */
    public function reverseCapture(OrderRef $order): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCREVERSECAP', ['REQUEST_REF_PO_ID' => $order->poId()])
        );
    }

    /** CCCREDIT — refund against an existing order. Partial-capable. */
    public function refund(OrderRef $order, ?Money $amount = null): TransactionResult
    {
        return ResultMapper::toTransactionResult(
            $this->call('CCCREDIT', $this->refParams('REQUEST_REF_PO_ID', $order->poId(), $amount))
        );
    }

    /** CCCREDIT + FORCE_CREDIT — a credit with no referenced original. */
    public function forceCredit(TransactionRequest $req): TransactionResult
    {
        $req->forceCredit = true;

        return $this->transact('CCCREDIT', $req);
    }

    /**
     * CCSTATUS — the reconciliation primitive AND the unknown-state recovery path.
     *
     * Returns order-level net position derived from every leg sharing the PO_ID.
     * For any order with more than one leg this is the only correct source of
     * net figures.
     */
    public function status(OrderRef|XtlOrderId $ref): OrderStatus
    {
        $p = $ref instanceof OrderRef
            ? ['REQUEST_REF_PO_ID' => $ref->poId()]
            : ['REQUEST_REF_PO_ID_XTL' => $ref->value()];
        $raw = $this->call('CCSTATUS', $p);
        $legMaps = self::extractLegs($raw);
        $legs = [];
        foreach ($legMaps as $leg) {
            $legs[] = ResultMapper::toTransactionResult($leg);
        }
        if ($legs === []) {
            $legs[] = ResultMapper::toTransactionResult($raw);
        }

        return ResultMapper::toOrderStatus($raw, $legs);
    }

    /** CCTRANSUPDATE — attach receipts to an existing order (Appendix G/J). */
    public function updateOrder(OrderRef $order, OrderUpdate $update): TransactionResult
    {
        $p = ['REQUEST_REF_PO_ID' => $order->poId()];
        if ($update->receipt !== null) {
            $p['RECEIPT'] = $update->receipt;
        }
        foreach ($update->metadata->udf ?? [] as $k => $v) {
            $p['XTL_UDF' . str_pad((string) $k, 2, '0', STR_PAD_LEFT)] = $v;
        }

        return ResultMapper::toTransactionResult($this->call('CCTRANSUPDATE', $p));
    }

    /**
     * Ephemeral tokenization (spec §4.8).
     *
     * NOTE: this server-side call still touches the PAN and therefore keeps the
     * caller in PCI scope. The lower-scope path is the browser Hosted Fields
     * client, which tokenizes without the PAN reaching your server.
     */
    public function tokenize(Card $card): Token
    {
        $p = $this->authParams('TOKENIZE');
        $p['PMT_NUMB'] = $card->number();
        $p['PMT_EXPIRY'] = $card->expiry();
        if ($card->cvv() !== null) {
            $p['PMT_KEY'] = $card->cvv();
        }
        $raw = Transport::send($this->tokenEndpoint, $this->http, $this->timeoutMs, $p);
        $this->raiseIfApiError($raw);
        $guid = $raw['TOKEN_GUID'] ?? $raw['TOKEN'] ?? $raw['TOKEN_ID'] ?? null;
        if ($guid === null) {
            throw new ConfigurationException('token service did not return a TOKEN_GUID', null, $raw);
        }

        return PaymentMethods::token($guid);
    }

    /** TESTAUTH — verify credentials. */
    public function testAuth(): HealthResult
    {
        return $this->toHealth($this->call('TESTAUTH', []));
    }

    /** TESTGW — verify gateway availability. */
    public function testAvailability(): HealthResult
    {
        return $this->toHealth($this->call('TESTGW', []));
    }

    // ------------------------------------------------------------------

    /** @return array<string,string> */
    private function authParams(string $action): array
    {
        $p = [
            'REQ_USERNAME' => $this->credentials->reqUsername,
            'REQ_PASSWORD' => $this->credentials->reqPassword,
            'SITE_ID' => $this->credentials->siteId,
            'REQUEST_ACTION' => $action,
            'REQUEST_API_VERSION' => $this->apiVersion,
            'REQUEST_RESPONSE_FORMAT' => 'JSON',
        ];
        if ($this->credentials->merchAcctId !== null) {
            $p['MERCH_ACCT_ID'] = $this->credentials->merchAcctId;
        }

        return $p;
    }

    /** @return array<string,string> */
    private function refParams(string $key, string $value, ?Money $amount): array
    {
        $p = [$key => $value];
        if ($amount !== null) {
            $p['LI_VALUE_1'] = $amount->toWire();
            $p['LI_COUNT_1'] = '1';
            $p['REQUEST_CURRENCY'] = $amount->currency();
        }

        return $p;
    }

    /**
     * Raise for API-tier failures only.
     *
     * A DECLINE IS NOT AN ERROR — it returns normally as a TransactionResult
     * with status DECLINED (Q1).
     *
     * @param array<string,string> $r
     */
    private function raiseIfApiError(array $r): void
    {
        $code = $r['API_RESPONSE'] ?? null;
        if ($code === null || $code === '' || !is_numeric($code)) {
            return;
        }
        $c = (int) $code;
        if ($c === 0) {
            return;
        }
        $info = Generated::API_RESPONSE_CODES[$c] ?? null;
        if ($info === null) {
            return;
        }
        $msg = $info['description']
            . ($info['recommendation'] !== '' ? ' — ' . $info['recommendation'] : '');
        switch ($info['mapsToException']) {
            case 'RateLimitException':
                throw new RateLimitException($msg, $r);
            case 'AuthenticationException':
                throw new AuthenticationException($msg, $c, $r);
            case 'ValidationException':
                throw new ValidationException($msg, $c, $r['REF_FIELD'] ?? null, $r);
            case 'ConfigurationException':
                throw new ConfigurationException($msg, $c, $r);
        }
    }

    /**
     * @param array<string,string> $params
     * @return array<string,string>
     */
    private function call(string $action, array $params, ?string $idempotencyKey = null): array
    {
        $merged = array_merge($this->authParams($action), $params);
        $raw = Transport::send($this->endpoint, $this->http, $this->timeoutMs, $merged, $idempotencyKey);
        $this->raiseIfApiError($raw);

        return $raw;
    }

    private function transact(string $action, TransactionRequest $req): TransactionResult
    {
        $params = RequestBuilder::build($req);

        return ResultMapper::toTransactionResult(
            $this->call($action, $params, $req->idempotency?->xtlOrderId)
        );
    }

    /** @param array<string,string> $raw */
    private function toHealth(array $raw): HealthResult
    {
        $res = ResultMapper::toTransactionResult($raw);
        $svc = $raw['SERVICE_RESPONSE'] ?? null;

        return new HealthResult(
            $res->status === 'APPROVED' || $svc === '100' || $svc === '101',
            $raw['REQUEST_ACTION'] ?? '',
            $res->outcome,
            $res->raw
        );
    }

    /**
     * CCSTATUS returns multiple transactions flattened with indexed keys; split
     * them back into per-leg field maps.
     *
     * @param array<string,string> $raw
     * @return array<int,array<string,string>>
     */
    private static function extractLegs(array $raw): array
    {
        $indexed = [];
        foreach ($raw as $k => $v) {
            if (preg_match('/^(.*?)_(\d+)$/', (string) $k, $m) !== 1) {
                continue;
            }
            [$_, $base, $idx] = $m;
            if (preg_match('/^(TRANS_|REQUEST_ACTION|SERVICE_|PROCESSOR_|API_|AVS_|CVV_|PO_ID)/', $base) !== 1) {
                continue;
            }
            $indexed[(int) $idx][$base] = $v;
        }
        ksort($indexed);

        // Order-level fields apply to every leg — notably CURR_CODE_ALPHA,
        // without which a leg has no currency and no amount can be built.
        $inherited = [];
        foreach (['PO_ID', 'XTL_ORDER_ID', 'CURR_CODE_ALPHA', 'MERCH_ACCT_ID'] as $k) {
            if (isset($raw[$k])) {
                $inherited[$k] = $raw[$k];
            }
        }

        $out = [];
        foreach ($indexed as $leg) {
            $out[] = array_merge($inherited, $leg);
        }

        return $out;
    }
}
