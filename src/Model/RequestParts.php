<?php

declare(strict_types=1);

namespace Inovio\Gateway\Model;

/** CUST_* + XTL_IP */
final class Customer
{
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $login = null;
    public ?string $password = null;
    /** MM-DD-YYYY per spec §4.2 */
    public ?string $birthday = null;
    public ?string $dln = null;
    public ?string $dlnState = null;
    public ?string $ssnLast4 = null;
    /** Brazil CPF/CNPJ — presence activates Credilink scrubbing. */
    public ?string $brCpfCnpj = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
}

/** BILL_ADDR_* / SHIP_ADDR_* */
final class Address
{
    public ?string $line1 = null;
    public ?string $line2 = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $zip = null;
    /** ISO-2 */
    public ?string $country = null;
    public ?string $district = null;
}

/** LI_*_n — the SDK owns the 1-based wire indexing. */
final class LineItem
{
    public ?string $xtlProductId = null;
    public ?string $type = null;

    public function __construct(
        public string $productId,
        public int $count,
        public Money $value
    ) {
    }
}

/** PMT_DESCRIPTOR* */
final class Descriptor
{
    public ?string $phone = null;
    public ?string $city = null;

    public function __construct(public string $name)
    {
    }
}

/** Spec §14.3 — opt-in, NOT defaulted on (Q6). Range 30..600 seconds. */
final class TimeoutVoid
{
    public function __construct(public int $seconds)
    {
    }
}

/** CHKAVS / CHKCVV / REQUEST_MAX_WAIT */
final class RiskOptions
{
    /** on | off | ignore | conditional */
    public ?string $avs = null;
    public ?string $avsMatchSet = null;
    public ?string $cvv = null;
    public ?string $cvvMatchSet = null;
    public ?TimeoutVoid $timeoutVoid = null;
}

/** PARTIAL_AUTH / PARTIAL_AUTH_MIN */
final class PartialAuth
{
    public ?Money $minimumAmount = null;

    public function __construct(public bool $enabled = false)
    {
    }
}

/**
 * Idempotency (Q6). mode maps to UNIQUE_XTL_ORDER_ID and defaults to
 * RETURN_ORIGINAL — a retry returns the original result instead of charging twice.
 */
final class Idempotency
{
    /** OFF | DECLINE_DUP | RETURN_ORIGINAL */
    public ?string $mode = null;

    public function __construct(public string $xtlOrderId)
    {
    }
}

/** Card-on-file / recurring compliance flags (Appendices G/J/K). */
final class Recurring
{
    /** CIT | MIT */
    public ?string $initiator = null;
    /** NONE | REBILL | START_SUBSCRIPTION */
    public ?string $rebill = null;
    /** NONE | TRIAL | INITIAL | REBILL */
    public ?string $rebillType = null;
    public ?bool $installment = null;
    public ?bool $cardOnFile = null;
    public ?bool $trialConsent = null;
    public ?string $membershipXtlId = null;
    public ?string $receipt = null;
}

final class Tax
{
    public ?bool $exempt = null;

    public function __construct(public Money $amount)
    {
    }
}

final class Fees
{
    public ?Tax $tax = null;
    public ?Money $convenienceFee = null;
}

final class Affiliate
{
    public ?string $affId = null;
    public ?string $subAffId = null;
}

/** XTL_UDF01..20, TPPE_ID, PROC_UDF01/02 */
final class Metadata
{
    /** @var array<string,string> */
    public array $udf = [];
    public ?string $tppeId = null;
    public ?string $procUdf1 = null;
    public ?string $procUdf2 = null;
}

/** 3DS — the gateway silently disables 3DS if any of these is missing. */
final class BrowserData
{
    public function __construct(
        public string $language,
        public string $userAgent,
        public string $header
    ) {
    }
}
