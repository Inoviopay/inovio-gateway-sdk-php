# Inovio Gateway SDK — PHP

Port of the Node/TS reference (**W2** of the internal SDK plan). Structurally
identical to the other SDKs; only ergonomics differ.

> **Status: alpha, local only.** Not published to Packagist.

This is the **long-tail e-commerce SDK** — the one a
WooCommerce or Magento plugin would embed — so ergonomics and host-friendliness
matter more here than anywhere else.

## Requirements

PHP **8.0+**, with `ext-json`, `ext-bcmath` and `ext-curl`. No Composer
dependencies.

`bcmath` is required, not optional: `Money` does decimal arithmetic through it
so amounts never touch a binary float.

## Test

```bash
php tests/conformance.php     # 67 assertions, no Composer needed
python3 scripts/generate_enums.py   # regenerate enums from spec/spec-enums.json
```

The suite is plain PHP rather than PHPUnit so it runs on a bare interpreter.

## Quick start

```php
use Inovio\Gateway\{Credentials, InovioClient};
use Inovio\Gateway\Model\{LineItem, Money, PaymentMethods};
use Inovio\Gateway\Request\TransactionRequest;

$client = new InovioClient(new Credentials($user, $password, '123'), 'SANDBOX');

$req = (new TransactionRequest(
    PaymentMethods::card('4111111111111111', '122030', '123'),
    [new LineItem('SKU-1', 1, Money::of('10.00', 'USD'))]
))->withIdempotency('ORDER-555');   // retry-safe by default

$result = $client->sale($req);

match ($result->status) {
    'APPROVED' => /* fulfil */,
    'DECLINED' => /* $result->outcome->service, $result->serviceClassification */,
    'PENDING'  => /* $result->nextAction — 3DS challenge, redirect, voucher */,
    default    => /* RUNNING | FAILED */,
};
```

## Injecting your own HTTP client

Host platforms usually want their own transport (WordPress `wp_remote_post`,
Magento's PSR-18 client, an instrumented client). Implement `HttpClient` and
pass it in — the SDK never assumes it owns the socket:

```php
$client = new InovioClient($creds, 'PRODUCTION', null, new MyPsr18Adapter());
```

Throw `Inovio\Gateway\Transport\TimeoutSignal` from your adapter on timeout so
the SDK can convert it into `GatewayTimeoutException` with the idempotency key
attached.

## Five things that will surprise you

Identical semantics to the Node reference — see the Node reference SDK's README for the full rationale:

1. **A decline is not an exception.** `sale()` returns `status === 'DECLINED'`.
   Exceptions mean you never got a payment answer.
2. **No `approved`/`declined` flags.** Only `$result->status` — so `PENDING`
   cannot be silently treated as failure.
3. **`settled` is almost always `false` at response time** (batch flips it
   later); `conversion` is non-null only on real FX.
4. **`status()` is the reconciliation primitive**, not just timeout recovery.
5. **`Money::of(1.25, 'USD')` throws.** Pass `'1.25'` — floats cannot represent
   decimal amounts exactly.

## Enums are generated

`src/Enums/Generated.php` comes from `spec/spec-enums.json` (decision **D1**).
Do not edit. The `retryable`/`terminal`/`stopRecurring` and AVS/CVV
classifications are **derived, not from the spec** — see
[`spec/README.md`](spec/README.md).

## Vendored spec artifacts

This repo **stands alone**: `spec/spec-enums.json` and
`spec/conformance-fixtures.json` are committed copies, so a fresh clone builds,
tests and regenerates with no sibling checkout, submodule or network fetch.

They are not the editable source — they are produced upstream in the internal
`inoviov2` workspace (`api-sdk/spec/`), where the extraction pipeline and its
validator live. To pull an upstream change in:

```bash
./scripts/sync-spec.sh /path/to/inoviov2/api-sdk/spec
```

Then regenerate the enums, run the suite, and commit the spec change together
with the generated code it produces.

**This is a coordinated change.** The other Inovio SDK repos vendor the same two
files; if they are not synced in step, the SDKs silently stop agreeing — which
is exactly what the shared conformance corpus exists to prevent.

## Conformance

`tests/conformance.php` runs the shared corpus in
`spec/conformance-fixtures.json` — the same fixtures every language SDK must
pass identically.
