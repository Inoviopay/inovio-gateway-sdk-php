<?php

declare(strict_types=1);

namespace Inovio\Gateway\Result;

use Inovio\Gateway\Enums\Generated;
use Inovio\Gateway\Model\Money;
use Inovio\Gateway\Refs\Refs;

/**
 * Wire response -> typed result.
 *
 * All the "is this approved" judgment lives here and in the generated spec
 * enums, so every language SDK classifies identically.
 */
final class ResultMapper
{
    private function __construct()
    {
    }

    /** @param array<string,string> $r */
    public static function toTransactionResult(array $r): TransactionResult
    {
        $status = self::parseStatus($r['TRANS_STATUS_NAME'] ?? null);
        $svcCode = self::num($r['SERVICE_RESPONSE'] ?? null);
        $svc = $svcCode !== null ? (Generated::SERVICE_RESPONSE_CODES[$svcCode] ?? null) : null;

        $liRefs = [];
        $liKeys = [];
        foreach (array_keys($r) as $k) {
            if (preg_match('/^PO_LI_ID_(\d+)$/', (string) $k, $m) === 1) {
                $liKeys[(int) $m[1]] = (string) $k;
            }
        }
        ksort($liKeys);
        foreach ($liKeys as $k) {
            $liRefs[] = Refs::lineItem($r[$k]);
        }

        $amount = null;
        if (self::val($r, 'TRANS_VALUE') !== null && self::val($r, 'CURR_CODE_ALPHA') !== null) {
            $amount = Money::of(self::val($r, 'TRANS_VALUE'), self::val($r, 'CURR_CODE_ALPHA'));
        }

        // Conversion is reported ONLY on real FX — otherwise the "settled"
        // fields are just the auth amount echoed back and would mean nothing.
        $conversion = null;
        $rate = self::val($r, 'TRANS_EXCH_RATE');
        if (
            $rate !== null && $rate !== '' && (float) $rate != 0.0
            && self::val($r, 'TRANS_VALUE_SETTLED') !== null && self::val($r, 'CURR_CODE_ALPHA_SETTLED') !== null
        ) {
            $conversion = new Conversion(
                Money::of(self::val($r, 'TRANS_VALUE_SETTLED'), self::val($r, 'CURR_CODE_ALPHA_SETTLED')),
                $rate
            );
        }

        $avsRaw = $r['AVS_RESPONSE'] ?? null;
        $avsInfo = $avsRaw !== null ? (Generated::AVS_CODES[strtoupper($avsRaw)] ?? null) : null;
        $cvvRaw = $r['CVV_RESPONSE'] ?? null;
        $cvvInfo = $cvvRaw !== null ? (Generated::CVV_CODES[strtoupper($cvvRaw)] ?? null) : null;

        return new TransactionResult(
            status: $status,
            settling: $status === 'PENDING' || $status === 'RUNNING',
            action: $r['REQUEST_ACTION'] ?? '',
            outcome: new Outcome(
                new Tier(self::num($r['API_RESPONSE'] ?? null), $r['API_ADVICE'] ?? null, $r['REF_FIELD'] ?? null),
                new Tier(self::num($r['SERVICE_RESPONSE'] ?? null), $r['SERVICE_ADVICE'] ?? null),
                new Tier(self::num($r['PROCESSOR_RESPONSE'] ?? null), $r['PROCESSOR_ADVICE'] ?? null),
                new Tier(self::num($r['INDUSTRY_RESPONSE'] ?? null), $r['INDUSTRY_ADVICE'] ?? null)
            ),
            settled: self::flag($r['TRANS_SETTLED'] ?? null),
            raw: $r,
            lineItemRefs: $liRefs,
            orderRef: self::val($r, 'PO_ID') !== null ? Refs::order(self::val($r, 'PO_ID')) : null,
            xtlOrderRef: self::val($r, 'XTL_ORDER_ID') !== null ? Refs::xtlOrder(self::val($r, 'XTL_ORDER_ID')) : null,
            transactionId: self::val($r, 'TRANS_ID') !== null ? Refs::transaction(self::val($r, 'TRANS_ID')) : null,
            requestId: self::val($r, 'REQ_ID') !== null ? Refs::req(self::val($r, 'REQ_ID')) : null,
            batchId: self::val($r, 'BATCH_ID') !== null ? Refs::batch(self::val($r, 'BATCH_ID')) : null,
            customerRef: (self::val($r, 'CUST_ID') !== null || self::val($r, 'XTL_CUST_ID') !== null)
                ? Refs::customer($r['CUST_ID'] ?? null, $r['XTL_CUST_ID'] ?? null) : null,
            savedCardRef: (self::val($r, 'PMT_ID') !== null || self::val($r, 'PMT_ID_XTL') !== null)
                ? Refs::savedCard($r['PMT_ID'] ?? null, $r['PMT_ID_XTL'] ?? null) : null,
            membershipRef: (self::val($r, 'MBSHP_ID') !== null || self::val($r, 'MBSHP_ID_XTL') !== null)
                ? Refs::membership($r['MBSHP_ID'] ?? null, $r['MBSHP_ID_XTL'] ?? null) : null,
            amount: $amount,
            conversion: $conversion,
            serviceClassification: $svc !== null ? new ServiceClassification(
                $svc['retryable'],
                $svc['stopRecurring'],
                $svc['terminal'],
                $svc['approval']
            ) : null,
            avs: $avsInfo !== null ? new AvsResult(
                $avsInfo['code'],
                $avsInfo['description'],
                $avsInfo['cardNetwork'],
                $avsInfo['classification'],
                (string) $avsRaw
            ) : null,
            cvv: $cvvInfo !== null ? new CvvResult(
                $cvvInfo['code'],
                $cvvInfo['description'],
                $cvvInfo['classification'],
                (string) $cvvRaw
            ) : null,
            card: self::card($r),
            nextAction: self::nextAction($r, $status)
        );
    }

    /** @param array<string,string> $r @param TransactionResult[] $legs */
    public static function toOrderStatus(array $r, array $legs): OrderStatus
    {
        $currency = null;
        foreach ($legs as $l) {
            if ($l->amount !== null) {
                $currency = $l->amount->currency();
                break;
            }
        }
        $currency ??= $r['CURR_CODE_ALPHA'] ?? 'USD';

        $auth = '0';
        $cap = '0';
        $ref = '0';
        $settled = $legs !== [];
        foreach ($legs as $l) {
            $a = strtoupper($l->action);
            $isAuth = str_contains($a, 'AUTHORIZE') || str_contains($a, 'AUTHCAP');
            $isCapture = str_contains($a, 'CAPTURE') && !str_contains($a, 'REVERSECAP');
            $isRefund = str_contains($a, 'CREDIT') || str_contains($a, 'REVERSE');
            if ($l->amount !== null && $l->status === 'APPROVED') {
                if ($isAuth) {
                    $auth = bcadd($auth, $l->amount->amount(), 8);
                } elseif ($isCapture) {
                    $cap = bcadd($cap, $l->amount->amount(), 8);
                } elseif ($isRefund) {
                    $ref = bcadd($ref, $l->amount->amount(), 8);
                }
            }
            if ($isAuth && !$l->settled) {
                $settled = false;
            }
        }

        $fmt = static fn (string $v): string => rtrim(rtrim($v, '0'), '.') ?: '0';

        return new OrderStatus(
            ref: Refs::order($r['PO_ID'] ?? 'unknown'),
            transactions: $legs,
            settled: $settled,
            raw: $r,
            xtlRef: self::val($r, 'XTL_ORDER_ID') !== null ? Refs::xtlOrder(self::val($r, 'XTL_ORDER_ID')) : null,
            authorized: Money::of($fmt($auth), $currency),
            captured: Money::of($fmt($cap), $currency),
            refunded: Money::of($fmt($ref), $currency),
            net: Money::of($fmt(bcsub($cap, $ref, 8)), $currency),
            outstanding: Money::of($fmt(bcsub($auth, $cap, 8)), $currency)
        );
    }

    private static function parseStatus(?string $raw): string
    {
        $s = strtoupper(trim((string) $raw));
        // An unrecognized status must not silently read as approved.
        return isset(Generated::TRANSACTION_STATUSES[$s]) ? $s : 'FAILED';
    }

    /**
     * A field counts as present only when it is non-null AND non-empty.
     *
     * The gateway returns inapplicable fields as EMPTY STRINGS rather than
     * omitting them — a TESTGW response, for example, carries TRANS_ID="".
     * isset() treats those as present and hands "" to a reference constructor,
     * which rejects it. Verified against the live T1 gateway; the mocked
     * fixtures never exercised it because they omit the keys entirely.
     *
     * @param array<string,string> $r
     */
    private static function val(array $r, string $key): ?string
    {
        $v = $r[$key] ?? null;

        return ($v === null || $v === '') ? null : $v;
    }

    private static function num(?string $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private static function flag(?string $v): bool
    {
        return $v === '1' || strtoupper((string) $v) === 'Y' || strtoupper((string) $v) === 'TRUE';
    }

    /** @param array<string,string> $r */
    private static function card(array $r): ?CardInfo
    {
        $has = self::val($r, 'CARD_BRAND_NAME') !== null || self::val($r, 'PMT_L4') !== null
            || self::val($r, 'CARD_TYPE') !== null || self::val($r, 'CARD_BANK') !== null
            || self::val($r, 'CARD_COUNTRY') !== null;
        if (!$has) {
            return null;
        }
        $c = new CardInfo();
        $c->brand = $r['CARD_BRAND_NAME'] ?? null;
        $c->detail = $r['CARD_DETAIL'] ?? null;
        $c->type = $r['CARD_TYPE'] ?? null;
        $c->cardClass = $r['CARD_CLASS'] ?? null;
        $c->country = $r['CARD_COUNTRY'] ?? null;
        $c->bank = $r['CARD_BANK'] ?? null;
        $c->prepaid = ($r['CARD_PREPAID'] ?? null) === '1';
        $c->balance = $r['CARD_BALANCE'] ?? null;
        $c->last4 = $r['PMT_L4'] ?? null;
        $c->networkTokenUsed = self::num($r['TRANS_NTOKEN_USED'] ?? null);
        if (self::val($r, 'PMT_AAU_UPDATE_DESC') !== null || self::val($r, 'PMT_AAU_UPDATE_DATE') !== null) {
            $c->accountUpdater = new AccountUpdater(
                $r['PMT_AAU_UPDATE_DESC'] ?? null,
                $r['PMT_AAU_UPDATE_DATE'] ?? null,
                $r['PMT_AAU_UPDATE_EXPIRY'] ?? null,
                $r['PMT_AAU_UPDATE_L4'] ?? null
            );
        }

        return $c;
    }

    /** @param array<string,string> $r */
    private static function nextAction(array $r, string $status): ?NextAction
    {
        if ($status !== 'PENDING') {
            return null;
        }
        if (self::val($r, 'P3DS_PROCTRANSID') !== null || self::val($r, 'PAREQ') !== null || self::val($r, 'P3DS_JWT') !== null) {
            $n = new NextAction('threeDSChallenge');
            $n->redirectUrl = $r['PROC_REDIRECT_URL'] ?? null;
            $n->jwt = $r['P3DS_JWT'] ?? null;
            $n->procTransId = $r['P3DS_PROCTRANSID'] ?? null;
            $n->pareq = $r['PAREQ'] ?? null;

            return $n;
        }
        if (self::val($r, 'PROC_BARCODE') !== null) {
            $n = new NextAction('displayVoucher');
            $n->url = $r['PROC_REDIRECT_URL'] ?? null;
            $n->barcode = $r['PROC_BARCODE'];

            return $n;
        }
        if (self::val($r, 'PIX_TOKEN') !== null) {
            $n = new NextAction('displayQr');
            $n->url = $r['PROC_REDIRECT_URL'] ?? null;
            $n->token = $r['PIX_TOKEN'];

            return $n;
        }
        if (self::val($r, 'PROC_REDIRECT_URL') !== null) {
            $n = new NextAction('redirect');
            $n->url = $r['PROC_REDIRECT_URL'];

            return $n;
        }

        return new NextAction('awaitSettlement');
    }
}
