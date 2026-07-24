#!/usr/bin/env python3
"""Generate src/Enums/Generated.php from ../spec/spec-enums.json (decision D1)."""
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
SPEC = HERE.parent.parent / "spec" / "spec-enums.json"
OUT = HERE.parent / "src" / "Enums" / "Generated.php"
spec = json.loads(SPEC.read_text())
A, ver = spec["appendices"], spec["apiVersion"]


def php(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "\\'") + "'"


def b(v):
    return "true" if v else "false"


L = ["<?php", "", "declare(strict_types=1);", "", "namespace Inovio\\Gateway\\Enums;", "",
     "/**", " * GENERATED FILE — DO NOT EDIT.",
     " *", f" * Source: Inovio Gateway Payments Service API v{ver} (api-sdk/spec/spec-enums.json)",
     " * Regenerate: python3 scripts/generate_enums.py", " *",
     " * Classifiers (retryable/terminal/stopRecurring, AVS/CVV classification and",
     " * the API-code -> exception mapping) are DERIVED by the SDK project, not",
     " * stated in the spec. See api-sdk/spec/README.md.", " */", "final class Generated", "{",
     f"    public const SPEC_API_VERSION = {php(ver)};", ""]

# Transaction statuses
L.append("    /** Appendix B — the master transaction lifecycle (5 states). */")
L.append("    public const TRANSACTION_STATUSES = [")
for e in A["B_transactionStatus"]:
    L.append(f"        {php(e['code'])} => {php(e['description'])},")
L += ["    ];", ""]

# Request actions
L.append("    /** Appendix A — every REQUEST_ACTION the gateway accepts. */")
L.append("    public const REQUEST_ACTIONS = [")
for e in A["A_serviceRequestTypes"]:
    L.append(f"        {php(e['code'])} => {php(e['description'])},")
L += ["    ];", ""]

# Service response codes
L.append("    /** Appendix D — service response codes + decline taxonomy. */")
L.append("    public const SERVICE_RESPONSE_CODES = [")
for e in A["D_serviceResponseCodes"]:
    L.append(f"        {e['code']} => ['code' => {e['code']}, 'description' => {php(e['description'])}, "
             f"'retryable' => {b(e['retryable'])}, 'stopRecurring' => {b(e['stopRecurring'])}, "
             f"'approval' => {b(e['approval'])}, 'terminal' => {b(e['terminal'])}],")
L += ["    ];", ""]

# API response codes
L.append("    /** Appendix C — gateway request-validation codes. */")
L.append("    public const API_RESPONSE_CODES = [")
for e in A["C_apiResponseCodes"]:
    L.append(f"        {e['code']} => ['code' => {e['code']}, 'description' => {php(e['description'])}, "
             f"'recommendation' => {php(e['recommendation'])}, "
             f"'mapsToException' => {php(e['mapsToException'])}, "
             f"'carriesRefField' => {b(e['carriesRefField'])}],")
L += ["    ];", ""]

# AVS
L.append("    /**")
L.append("     * Appendix E — AVS codes. 'classification' is DERIVED, not from the spec:")
L.append("     * positive | partial | negative | neutral. 'partial' means some elements")
L.append("     * matched and some did not — whether that is acceptable is a merchant")
L.append("     * risk-policy decision, not a spec fact.")
L.append("     */")
L.append("    public const AVS_CODES = [")
for e in A["E_avsCodes"]:
    L.append(f"        {php(e['code'])} => ['code' => {php(e['code'])}, 'description' => {php(e['description'])}, "
             f"'cardNetwork' => {php(e['cardNetwork'])}, 'classification' => {php(e['classification'])}],")
L += ["    ];", ""]

# CVV
L.append("    /** Appendix F — CVV codes. 'classification' is DERIVED. */")
L.append("    public const CVV_CODES = [")
for e in A["F_cvvCodes"]:
    L.append(f"        {php(e['code'])} => ['code' => {php(e['code'])}, 'description' => {php(e['description'])}, "
             f"'classification' => {php(e['classification'])}],")
L += ["    ];", "}", ""]

OUT.parent.mkdir(parents=True, exist_ok=True)
OUT.write_text("\n".join(L))
n = sum(len(v) for v in A.values())
print(f"generated {OUT} ({n} enum values from spec v{ver})")
