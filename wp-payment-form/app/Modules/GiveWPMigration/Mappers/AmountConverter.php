<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Mappers;

use WPPayForm\App\Services\GeneralSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AmountConverter — converts between GiveWP's decimal strings and Paymattic's minor units.
 *
 * GiveWP stores all monetary amounts as decimal strings (e.g. "50.00", "1234.56").
 * Paymattic stores all amounts as integer minor units (e.g. 5000 for $50.00 USD).
 *
 * Critical rules:
 *  - NEVER subtract _give_fee_amount from _give_payment_total before converting.
 *    _give_payment_total already includes the fee recovery amount. The full
 *    charged amount must be stored in wpf_submissions.payment_total.
 *  - For currency-switched donations, _give_payment_total is in the DONOR's
 *    selected currency (not the site base currency). Use _give_payment_currency
 *    as the currency code and convert _give_payment_total as-is.
 *  - Use round() before casting to int to avoid floating-point truncation
 *    (e.g. 50.00 * 100 = 4999.9999... on some PHP builds).
 *
 * @see §5 (fee recovery), §6 (currency switcher), §13.1 of the migration brief
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Mappers
 * @since   4.6.21
 */
class AmountConverter
{
    /**
     * Convert a GiveWP decimal amount string to Paymattic integer minor units.
     *
     * Example: "50.00" → 5000, "1.99" → 199, "0.00" → 0
     * For zero-decimal currencies (e.g. JPY): "500" → 500 (no multiplication).
     *
     * @param string $amount   GiveWP decimal amount string (e.g. "50.00").
     * @param string $currency ISO 4217 currency code (e.g. "USD"). Default 'USD'.
     *
     * @return int Amount in minor units (cents/paise/etc.).
     */
    public static function toMinorUnits(string $amount, string $currency = 'USD'): int
    {
        if (GeneralSettings::isZeroDecimal(strtoupper($currency))) {
            return (int) round((float) $amount);
        }

        // Cast to float first; round() guards against floating-point imprecision;
        // final cast to int is safe because the rounded value has no fractional part.
        return (int) round((float) $amount * 100);
    }

    /**
     * Convert a Paymattic integer minor-unit amount back to a decimal float.
     *
     * Intended for display/logging only — never use floats for payment math.
     *
     * Example: 5000 → 50.0, 199 → 1.99
     *
     * @param int $amount Amount in minor units.
     *
     * @return float Human-readable decimal amount.
     */
    public static function fromMinorUnits(int $amount): float
    {
        return $amount / 100.0;
    }
}
