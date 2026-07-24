# Examples

One runnable file per SDK operation. These are **real, executed code** — not
markdown snippets — so they cannot silently drift from the API.

```bash
php examples/run_all.php     # all 14, against a mock transport
php examples/03_sale.php
```

## Live mode

The same files run against the real gateway:

```bash
INOVIO_LIVE=1 \
INOVIO_USER=... INOVIO_PASS=... INOVIO_SITE_ID=... INOVIO_MERCH_ACCT_ID=... \
INOVIO_SITE_KEY=...            # token service HMAC key
INOVIO_TOKEN_SITE_ID=...       # only if tokenizing on a different site
```

⚠️ Live mode creates **real transactions**. Point it at a test environment.

Running live is worth doing before you trust an integration: mocks only replay
responses you already believed in, so they cannot catch request-side errors.
Every example that operates on an existing order builds its own first, rather
than hardcoding an id that resolves only against a mock.

## The files

| File | Operation | Notes |
|------|-----------|-------|
| `01` test-availability | `testAvailability()` | TESTGW — safe to poll |
| `02` test-auth | `testAuth()` | verify credentials, no transaction |
| `03` sale | `sale()` | authorize + capture in one step |
| `04` authorize | `authorize()` | hold funds; keep the order ref |
| `05` capture | `capture()` | full or partial |
| `06` capture-line-item | `captureLineItem()` | needs order + item + amount |
| `07` reverse | `reverse()` | void an authorization |
| `08` reverse-capture | `reverseCapture()` | void a capture, pre-settlement |
| `09` refund | `refund()` | return captured funds |
| `10` force-credit | `forceCredit()` | needs MID provisioning |
| `11` status | `status()` | reconciliation + net position |
| `12` update-order | `updateOrder()` | receipts for compliance |
| `13` tokenize | `tokenize()` | PAN -> single-use token |
| `14` timeout-recovery | — | prevents double charges |

## Two that need provisioning

- **`forceCredit`** fails with API 104 "Invalid service action" unless the MID
  has FORCE_CREDIT enabled. That is an authentication-tier error, not a decline.
- **`tokenize`** needs a per-site HMAC key from Inovio support. It is not your
  gateway password, and may live on a different site than your gateway creds.
