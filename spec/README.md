# Vendored spec artifacts

These files are **vendored copies**, not the editable source. This repo is
designed to stand alone: cloning it must be enough to build, test and
regenerate — no sibling checkout, submodule or network fetch required.

| File | What it is |
|------|-----------|
| `spec-enums.json` | 196 enum values extracted from the Inovio Gateway Payments Service API v4.14 PDF (Appendices A–F) |
| `conformance-fixtures.json` | The cross-language conformance corpus every Inovio SDK must pass identically |

## Upstream

Both files are produced in the `api-sdk/spec/` working area of the internal
`inoviov2` workspace, where the extraction pipeline (`extract.sh`) and its
validator live. **Edit them there, not here.**

To refresh this copy after an upstream change:

```bash
./scripts/sync-spec.sh /path/to/inoviov2/api-sdk/spec
```

Then regenerate the enums and re-run the suite — a spec change that alters an
enum or a fixture is expected to change generated code and must be committed
together with it.

## ⚠️ Derived, not from the spec

The `classifiers` block in `spec-enums.json` — `retryable` / `terminal` /
`stopRecurring`, the AVS/CVV `classification`, and the API-code → exception
mapping — is **our interpretation**, not stated in the v4.14 document. It drives
partner dunning and risk logic.

In particular AVS `partial` (street matches but postal does not, and similar)
is a *merchant risk-policy* question. The SDK reports the classification and
deliberately does not decide pass/fail for you.

## Cross-repo consistency

The four SDKs (node, python, java, php) each vendor these same two files. A
change to either is a **coordinated change across all four repos** — otherwise
the SDKs silently stop agreeing, which is exactly what the shared corpus exists
to prevent.
