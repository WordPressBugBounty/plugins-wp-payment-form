<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Mappers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GatewayMapper — maps GiveWP payment gateway slugs to Paymattic equivalents.
 *
 * GiveWP stores the gateway slug in give_donationmeta under the key
 * `_give_payment_gateway` (e.g. "stripe", "paypal", "manual").
 *
 * Paymattic stores the gateway in wpf_submissions.payment_method and
 * wpf_order_transactions.payment_method using its own slug set.
 *
 * Migrated submissions always store the real gateway slug regardless of whether
 * Paymattic Pro is active. The Pro check only matters for live payment dispatch —
 * migrated historical submissions never go through gateway dispatch again, so
 * downgrading their gateway to 'offline' would produce inaccurate records.
 *
 * The preflight screen warns the admin when Pro is absent and Pro-only gateways
 * are present in GiveWP data — that warning is advisory, not blocking.
 *
 * @see §13.2 (gateway slug mapping) of the migration brief.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Mappers
 * @since   4.6.21
 */
class GatewayMapper
{
    /**
     * Paymattic gateway slugs that require Paymattic Pro.
     * Used only by requiresPro() to drive preflight warnings.
     * No longer used to downgrade payment_method on migrated submissions.
     *
     * @var array<string>
     */
    private static $proOnlyGateways = [
        'paypal',
        'mollie',
        'razorpay',
        'paystack',
        'square',
        'flutterwavepro',
        'billplz',
        'sslcommerz',
        'xendit',
        'payrexx',
    ];

    /**
     * GiveWP gateway slug → Paymattic payment_method mapping.
     *
     * All GiveWP Stripe add-on slug variants map to 'stripe'.
     * GiveWP PayPal variants map to 'paypal'.
     * Gateways without a Paymattic equivalent map to their own slug (preserving
     * the real gateway name). Unknown/custom slugs also fall back to their own slug.
     *
     * @var array<string, string>
     */
    private static $gatewayMap = [
        // -------------------------------------------------------------------
        // Stripe variants.
        // GiveWP ships multiple Stripe add-ons; each registers its own slug.
        // All map to 'stripe' — the single Paymattic Stripe gateway covers all.
        // -------------------------------------------------------------------
        'stripe'                  => 'stripe',
        'stripe_checkout'         => 'stripe',
        'stripe_ideal'            => 'stripe',
        'stripe_sepa'             => 'stripe',
        'stripe_bacs'             => 'stripe',
        'stripe_bancontact'       => 'stripe',
        'stripe_fpx'              => 'stripe',
        'stripe_apple_pay'        => 'stripe',
        'stripe_ach'              => 'stripe',
        'stripe_payment_element'  => 'stripe',
        'stripe_premium'          => 'stripe', // GiveWP Stripe Pro variant
        'give-stripe'             => 'stripe', // slug used in some older installations

        // -------------------------------------------------------------------
        // PayPal variants (Pro only in Paymattic).
        // GiveWP PayPal Commerce Platform uses a hyphenated slug ('paypal-commerce').
        // Both the hyphenated and underscored forms are mapped for compatibility.
        // -------------------------------------------------------------------
        'paypal'                  => 'paypal',
        'paypal-commerce'         => 'paypal', // GiveWP PayPal Commerce Platform (hyphenated slug)
        'paypal_commerce'         => 'paypal',
        'paypal_standard'         => 'paypal',
        'paypal-standard'         => 'paypal', // hyphenated variant
        'paypal_payflow'          => 'paypal',
        'paypal_pro'              => 'paypal',

        // -------------------------------------------------------------------
        // Offline / manual payment — direct equivalent.
        // -------------------------------------------------------------------
        'manual'                  => 'offline',

        // -------------------------------------------------------------------
        // Authorize.Net — not supported by Paymattic; preserve real slug.
        // -------------------------------------------------------------------
        'authorize'               => 'authorizedotnet',
        'authorize_echeck'        => 'authorizedotnet',

        // -------------------------------------------------------------------
        // Pro-only gateways in Paymattic (retained as real slugs for accuracy).
        // -------------------------------------------------------------------
        'mollie'                  => 'mollie',
        'razorpay'                => 'razorpay',
        'paystack'                => 'paystack',
        'square'                  => 'square',

        // -------------------------------------------------------------------
        // GiveWP Pro gateways that map to Paymattic Pro equivalents.
        // -------------------------------------------------------------------
        'give-flutterwave'        => 'flutterwavepro',
        'flutterwave'             => 'flutterwavepro',
        'give-billplz'            => 'billplz',
        'billplz'                 => 'billplz',
        'give-sslcommerz'         => 'sslcommerz',
        'sslcommerz'              => 'sslcommerz',
        'give-xendit'             => 'xendit',
        'xendit'                  => 'xendit',
        'give-payrexx'            => 'payrexx',
        'payrexx'                 => 'payrexx',

        // -------------------------------------------------------------------
        // GiveWP gateways with no Paymattic equivalent — preserve slug so the
        // admin sees the real gateway name instead of "offline".
        // All unknown/custom gateways fall through to mapGateway()'s fallback
        // which also returns the original slug.
        // -------------------------------------------------------------------
        'gocardless'              => 'gocardless',
        'give-gocardless'         => 'gocardless',
        'twocheckout'             => '2checkout',
        '2checkout'               => '2checkout',
        'wepay'                   => 'wepay',
        'braintree'               => 'braintree',
        'payfast'                 => 'payfast',
        'give-payfast'            => 'payfast',
        'forte'                   => 'forte',
        'payu'                    => 'payu',
        'paytm'                   => 'paytm',
        'cybersource'             => 'cybersource',
        'bitpay'                  => 'bitpay',
        'coinbase'                => 'coinbase',
        'klarna'                  => 'klarna',
        'affirm'                  => 'affirm',
        'afterpay'                => 'afterpay',
        'sagepay'                 => 'sagepay',
        'opayo'                   => 'sagepay',
    ];

    /**
     * Map a GiveWP gateway slug to a Paymattic payment_method string.
     *
     * Returns the real Paymattic gateway slug even when Pro is not active.
     * Migrated submissions are historical records — they never go through
     * gateway dispatch, so there is no risk of a dispatch error from an
     * unrecognised slug. Storing the accurate gateway preserves audit integrity
     * and allows the entry details page to display the correct payment method.
     *
     * Unknown GiveWP gateways (custom add-ons, future slugs) return their
     * original normalized slug so admins see the real gateway name.
     *
     * @param string $giveGateway GiveWP _give_payment_gateway meta value.
     *
     * @return string Paymattic payment_method string.
     */
    public static function mapGateway(string $giveGateway): string
    {
        $normalized = strtolower(trim($giveGateway));

        // Unknown/custom gateway: return the original slug so the admin sees
        // the real gateway name instead of "offline". Migrated submissions never
        // go through gateway dispatch, so an unrecognised slug is safe to store.
        if (!isset(self::$gatewayMap[$normalized])) {
            return $normalized ?: 'offline';
        }

        return self::$gatewayMap[$normalized];
    }

    /**
     * Check whether a GiveWP gateway maps to a Pro-only Paymattic gateway.
     *
     * Used by the preflight check to warn the admin that Pro is recommended
     * for full gateway UI support (e.g. "View on PayPal" links, Pro reports).
     * This does NOT affect the gateway slug stored for migrated submissions.
     *
     * @param string $giveGateway GiveWP gateway slug.
     *
     * @return bool True when the gateway requires Paymattic Pro for full support.
     */
    public static function requiresPro(string $giveGateway): bool
    {
        $normalized = strtolower(trim($giveGateway));

        if (!isset(self::$gatewayMap[$normalized])) {
            return false;
        }

        return in_array(self::$gatewayMap[$normalized], self::$proOnlyGateways, true);
    }

    /**
     * Return a vendor dashboard URL for a given gateway + transaction ID.
     *
     * Used during migration to pre-compute a browsable URL for the transaction,
     * stored in wpf_meta as give_transaction_url so the entry details page
     * can display a direct link without requiring Pro's entry_transactions filter.
     *
     * Returns an empty string when:
     *   - $chargeId is empty
     *   - the gateway does not have a known dashboard URL pattern
     *
     * @param string $gateway  Paymattic payment_method slug (e.g. 'stripe', 'paypal').
     * @param string $chargeId Gateway transaction / charge reference ID.
     * @param string $mode     'live' or 'test' (used by Stripe to pick the right subdomain).
     *
     * @return string Absolute HTTPS URL, or empty string if not applicable.
     */
    public static function getPaymentUrl(string $gateway, string $chargeId, string $mode): string
    {
        $chargeId = trim($chargeId);

        if ($chargeId === '') {
            return '';
        }

        if ($gateway === 'stripe') {
            // Stripe test dashboard uses a 'test/' path segment.
            $testSegment = (strtolower($mode) === 'test') ? 'test/' : '';
            return 'https://dashboard.stripe.com/' . $testSegment . 'payments/' . rawurlencode($chargeId);
        }

        if ($gateway === 'paypal') {
            // Only generate a PayPal URL for transaction IDs that look like
            // PayPal TX references (uppercase alphanumeric, 17 chars).
            // Avoids generating broken links for Stripe charge IDs on paypal rows.
            if (preg_match('/^[A-Z0-9]{17}$/', $chargeId)) {
                return 'https://www.paypal.com/activity/payment/' . rawurlencode($chargeId);
            }
            return '';
        }

        if ($gateway === 'mollie') {
            // Mollie payment IDs: tr_xxxxxxxxxx
            if (preg_match('/^tr_[A-Za-z0-9]+$/', $chargeId)) {
                return 'https://my.mollie.com/dashboard/payments/' . rawurlencode($chargeId);
            }
            return '';
        }

        if ($gateway === 'razorpay') {
            // Razorpay payment IDs: pay_xxxxxxxxxxxxxxxxxx
            if (preg_match('/^pay_[A-Za-z0-9]+$/', $chargeId)) {
                return 'https://dashboard.razorpay.com/app/payments/' . rawurlencode($chargeId);
            }
            return '';
        }

        if ($gateway === 'paystack') {
            // Paystack reference codes are alphanumeric strings.
            if ($chargeId !== '') {
                return 'https://dashboard.paystack.com/#/transactions/' . rawurlencode($chargeId);
            }
            return '';
        }

        if ($gateway === 'square') {
            // Square payment IDs are 32-char uppercase hex strings.
            if (preg_match('/^[A-Za-z0-9]{16,}$/', $chargeId)) {
                return 'https://squareup.com/dashboard/sales/transactions/' . rawurlencode($chargeId);
            }
            return '';
        }

        return '';
    }
}
