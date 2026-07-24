<?php
/**
 * tokenize() — token_service.cfm
 *
 * Exchanges a PAN for a single-use TOKEN_GUID that replaces PMT_NUMB on a later
 * transaction. A new token is required per transaction.
 *
 * Needs a SITE KEY: a per-site HMAC secret from Inovio support, NOT your
 * gateway password. Without it the service answers error 121.
 *
 * ⚠️ This is a SERVER-SIDE call — the PAN passes through your infrastructure,
 * the card number passes through your server. The browser Hosted Fields client keeps it in the cardholder's browser.
 */
require_once __DIR__ . '/_harness.php';

use Inovio\Gateway\Model\PaymentMethods;

// Tokenize on the site that holds the HMAC key...
$t = tokenClient()->tokenize(PaymentMethods::card(demoPan(), demoExpiry(), demoCvv()));

show('token', $t->token->guid());
show('token req id', $t->tokenReqId ?? '-');
// BIN metadata is best-effort — blank when the BIN is not in the lookup table.
$bits = array_filter([$t->card->brand, $t->card->type, $t->card->bank]);
show('card', $bits ? implode(' / ', $bits) : '(BIN not found)');

// The token replaces the PAN ONLY: expiry (and CVV) still travel with it, which
// tokenize() carries forward for you.
$sale = client()->sale(buildRequest('TOK', '10.00', null, $t->token));
show('sale with token', $sale->status . ' order=' . ($sale->orderRef?->poId() ?? '-'));
