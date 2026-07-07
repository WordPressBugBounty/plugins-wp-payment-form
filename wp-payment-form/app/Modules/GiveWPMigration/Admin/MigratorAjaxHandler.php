<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use WPPayForm\App\Modules\GiveWPMigration\MigrationState;
use WPPayForm\App\Modules\GiveWPMigration\Readers\GiveFormReader;
use WPPayForm\App\Modules\GiveWPMigration\Readers\GiveDonationReader;
use WPPayForm\App\Modules\GiveWPMigration\Readers\GiveSubscriptionReader;
use WPPayForm\App\Modules\GiveWPMigration\Writers\PaymatticFormWriter;
use WPPayForm\App\Modules\GiveWPMigration\Writers\PaymatticSubmissionWriter;
use WPPayForm\App\Modules\GiveWPMigration\Writers\PaymatticSubscriptionWriter;
use WPPayForm\App\Modules\GiveWPMigration\Enrichers\GiftAidEnricher;
use WPPayForm\App\Modules\GiveWPMigration\Enrichers\FFMEnricher;
use WPPayForm\App\Modules\GiveWPMigration\MigrationReporter;
use WPPayForm\App\Services\AccessControl;

/**
 * MigratorAjaxHandler — handles all admin AJAX actions for the migration tool.
 *
 * Security model (applied to every handler):
 *   1. check_ajax_referer('wpf_givewp_migration') — verifies the nonce.
 *   2. AccessControl::hasGrandAccess() — checks manage_options OR wpf_full_access.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Admin
 * @since   4.6.21
 */
class MigratorAjaxHandler
{

    const NONCE_ACTION = 'wpf_givewp_migration';

    private function authorize(): void
    {
        check_ajax_referer(self::NONCE_ACTION, '_ajax_nonce');

        if (!AccessControl::hasGrandAccess()) {
            wp_send_json_error(
                ['message' => __('Permission denied.', 'wp-payment-form')],
                403
            );
            exit;
        }
    }

    /**
     * SEC-HIGH-001 / SEC-MED-002: phase-scoped serialisation lock.
     *
     * Uses add_option() which is atomic at the SQL level (UNIQUE on option_name).
     * A second concurrent request to the same phase returns false from add_option
     * and we 429 it. Lock is released in the finally branch of each handler.
     *
     * @param  string $phase Stable identifier, e.g. 'forms', 'donations'.
     * @return bool          true when lock acquired, false when already held.
     */
    private function acquireBatchLock(string $phase): bool
    {
        $optionName = 'wpf_givewp_migration_lock_' . sanitize_key($phase);
        $acquired   = add_option($optionName, time(), '', 'no');
        if ($acquired) {
            return true;
        }
        // Auto-expire stale locks after 5 minutes (no batch should take longer).
        $heldAt = absint(get_option($optionName, 0));
        if ($heldAt > 0 && (time() - $heldAt) > 300) {
            delete_option($optionName);
            return (bool) add_option($optionName, time(), '', 'no');
        }
        return false;
    }

    /**
     * Release a batch lock acquired via acquireBatchLock().
     *
     * @param  string $phase
     * @return void
     */
    private function releaseBatchLock(string $phase): void
    {
        delete_option('wpf_givewp_migration_lock_' . sanitize_key($phase));
    }

    /**
     * Send a 429 response for a phase already in flight.
     *
     * @param  string $phase
     * @return void
     */
    private function rejectConcurrent(string $phase): void
    {
        wp_send_json_error(
            [
                'message' => __('A migration batch is already running. Please wait for it to finish.', 'wp-payment-form'),
                'phase'   => $phase,
            ],
            429
        );
        exit;
    }

    // -------------------------------------------------------------------------
    // Handler: preflight
    // -------------------------------------------------------------------------

    /**
     * Pre-flight check — action: wpf_givewp_preflight
     *
     * Returns a comprehensive health report so the Vue UI can show warnings
     * before the user initiates the migration. This is read-only; nothing is
     * written to the database.
     *
     * Response shape: see inline documentation below.
     *
     * @return void  (calls wp_send_json_success/error and exits)
     */
    public function preflight(): void
    {
        $this->authorize();

        global $wpdb;

        // ------------------------------------------------------------------
        // GiveWP detection
        // ------------------------------------------------------------------

        // Check if GiveWP's main class is loaded (works regardless of plugin path).
        $givewpActive = class_exists('Give');

        // ------------------------------------------------------------------
        // Record counts (read-only queries, prepared or safe table names)
        // ------------------------------------------------------------------

        // Donation count: give_payment posts that are not trashed.
        $giveDonationCount = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
                    'give_payment',
                    'trash'
                )
            )
        );

        // Form count: give_forms posts that are not trashed.
        $giveFormCount = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
                    'give_forms',
                    'trash'
                )
            )
        );

        // ------------------------------------------------------------------
        // Addon detection: check for tables created by specific GiveWP addons.
        //
        // We use SHOW TABLES LIKE rather than information_schema so it works
        // on MySQL setups where the current user lacks information_schema access.
        // The result is a table name string on match, or NULL on no match.
        // ------------------------------------------------------------------

        $recurringAddon = (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($wpdb->prefix . 'give_subscriptions')
            )
        );

        // ------------------------------------------------------------------
        // Addon detection: file-based (is_plugin_active requires pluggable.php
        // to have been loaded, which it is in the admin context).
        // ------------------------------------------------------------------

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $giftAidAddon            = is_plugin_active('give-gift-aid/give-gift-aid.php');
        $ffmAddon                = is_plugin_active('give-form-field-manager/give-form-field-manager.php');
        $csCurrencySwitcherAddon = is_plugin_active('give-currency-switcher/give-currency-switcher.php');

        // ------------------------------------------------------------------
        // Gift Aid donation count (only when addon is active)
        // Counts donations where the donor opted in to Gift Aid.
        // ------------------------------------------------------------------

        $giftAidCount = 0;

        if ($giftAidAddon) {
            /*
             * give_donationmeta stores the opt-in as meta_key = '_give_gift_aid_accept_term_condition'
             * with meta_value = 'on' when the donor checked the Gift Aid checkbox.
             *
             * We JOIN with wp_posts to exclude trashed donation records.
             */
            $giftAidCount = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM {$wpdb->prefix}give_donationmeta dm
                         INNER JOIN {$wpdb->posts} p ON p.ID = dm.donation_id
                         WHERE dm.meta_key    = %s
                           AND dm.meta_value IN (%s, %s)
                           AND p.post_type    = %s
                           AND p.post_status != %s",
                        '_give_gift_aid_accept_term_condition',
                        'on',
                        'enabled',
                        'give_payment',
                        'trash'
                    )
                )
            );
        }

        // ------------------------------------------------------------------
        // Subscription count (only when recurring addon table is present).
        // ------------------------------------------------------------------

        $giveSubscriptionCount = 0;
        if ($recurringAddon) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix only, no user input
            $giveSubscriptionCount = absint($wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}give_subscriptions`"));
        }

        // ------------------------------------------------------------------
        // Memory limit evaluation.
        // ------------------------------------------------------------------

        $memoryBytes    = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memoryMinBytes = 128 * 1024 * 1024; // 128 MB minimum
        $memoryOk       = ($memoryBytes <= 0 || $memoryBytes >= $memoryMinBytes);

        // ------------------------------------------------------------------
        // Warnings — populated for conditions the admin must acknowledge
        // before migration can proceed.
        // ------------------------------------------------------------------

        $warnings = [];

        if ($giftAidAddon) {
            /*
             * ⚠️ CRITICAL: Gift Aid data is ONLY accessible via GiveWP's export tool.
             * Once GiveWP is deactivated, HMRC-format CSV export is permanently unavailable.
             *
             * The migration UI must require the admin to check a "I have already exported
             * the Gift Aid declarations CSV" checkbox before enabling the Start button.
             *
             * @see MigrationState::$gift_aid_acknowledged
             * @see §10 of the migration brief
             */
            $warnings[] = __('give-gift-aid is active and Gift Aid donations were detected. You MUST export the Gift Aid declarations CSV from GiveWP (Tools → Export → Gift Aid Donations) BEFORE running this migration. Once GiveWP is deactivated, that export is permanently unavailable. This migration cannot replace HMRC-format reporting.', 'wp-payment-form');
        }

        if (!defined('WPPAYFORMHASPRO')) {
            /*
             * Some GiveWP gateways (PayPal, Razorpay, Mollie, Paystack, Square) only
             * have full UI support (dashboard links, reports) in Paymattic Pro.
             * The gateway slug is stored accurately regardless — migrated submissions
             * will show the correct payment method name. Pro is recommended for the
             * full per-gateway entry detail experience (e.g. "View on PayPal" links).
             */
            $warnings[] = __('Paymattic Pro is not active. Donations from PayPal, Razorpay, Mollie, Paystack, and Square will be migrated with the correct gateway name, but some Pro-only features (such as gateway dashboard links and advanced reports) will not be available without Paymattic Pro.', 'wp-payment-form');
        }

        if ($csCurrencySwitcherAddon && !defined('WPPAYFORMHASPRO')) {
            $warnings[] = __('give-currency-switcher is active but Paymattic Pro is not. Currency switcher form configuration (the list of accepted currencies per form) requires Paymattic Pro and will be skipped.', 'wp-payment-form');
        }

        if (!$memoryOk) {
            $warnings[] = sprintf(
                /* translators: %s: current PHP memory_limit value, e.g. "64M" */
                __('PHP memory_limit is %s. For large GiveWP datasets (10k+ donations), 256MB or more is recommended. Ask your host to increase it.', 'wp-payment-form'),
                ini_get('memory_limit')
            );
        }

        // ------------------------------------------------------------------
        // Gateway distribution (group by _give_payment_gateway).
        // Drives "Missing in Paymattic" badges so admin sees which gateways
        // they actually use vs the static catalog.
        // ------------------------------------------------------------------

        $gatewayRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT dm.meta_value AS gateway, COUNT(*) AS total
                 FROM {$wpdb->prefix}give_donationmeta dm
                 INNER JOIN {$wpdb->posts} p ON p.ID = dm.donation_id
                 WHERE dm.meta_key = %s
                   AND p.post_type = %s
                   AND p.post_status != %s
                 GROUP BY dm.meta_value",
                '_give_payment_gateway',
                'give_payment',
                'trash'
            )
        );

        $gatewayCounts = [];
        foreach ((array) $gatewayRows as $row) {
            $slug = (string) $row->gateway;
            if ($slug === '') continue;
            $gatewayCounts[$slug] = absint($row->total);
        }

        $gatewayCountFor = function (array $slugs) use ($gatewayCounts) {
            $total = 0;
            foreach ($slugs as $slug) {
                if (isset($gatewayCounts[$slug])) $total += $gatewayCounts[$slug];
            }
            return $total;
        };

        // ------------------------------------------------------------------
        // Subscription interval analysis (lossy mapping detection).
        // ------------------------------------------------------------------

        $quarterlySubCount = 0;
        $freqGtOneSubCount = 0;

        if ($recurringAddon) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $quarterlySubCount = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}give_subscriptions` WHERE period = 'quarter'"
            ));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $freqGtOneSubCount = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}give_subscriptions` WHERE frequency > 1"
            ));
        }

        // ------------------------------------------------------------------
        // Extra addon detection (file-based + table-based).
        // ------------------------------------------------------------------

        $feeRecoveryAddon = is_plugin_active('give-fee-recovery/give-fee-recovery.php');
        $textToGiveAddon  = is_plugin_active('give-text-to-give/give-text-to-give.php');
        $fundsAddon       = (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($wpdb->prefix . 'give_funds')
            )
        );

        // ------------------------------------------------------------------
        // Additional detection queries — only run lightweight COUNTs so we
        // can build the "Missing in Paymattic" list with ONLY features the
        // admin actually uses on this install. Anything with 0 detected
        // usage is omitted from the response.
        // ------------------------------------------------------------------

        $hasPro       = defined('WPPAYFORMHASPRO');
        $paypalUse    = $gatewayCountFor(['paypal', 'paypal_commerce', 'paypal_standard', 'paypal_pro']);
        $authUse      = $gatewayCountFor(['authorize']);
        $razorpayUse  = $gatewayCountFor(['razorpay']);
        $mollieUse    = $gatewayCountFor(['mollie']);
        $paystackUse  = $gatewayCountFor(['paystack']);
        $squareUse    = $gatewayCountFor(['square']);
        $payfastUse   = $gatewayCountFor(['payfast', 'give_payfast', 'give-payfast']);

        // Premium / Addons gateways with no Paymattic equivalent.
        $gocardlessUse = $gatewayCountFor(['gocardless']);
        $paytmUse      = $gatewayCountFor(['paytm']);
        $payumoneyUse  = $gatewayCountFor(['payumoney']);
        $monerisUse    = $gatewayCountFor(['moneris']);
        $americloudUse = $gatewayCountFor(['americloud']);
        $braintreeUse  = $gatewayCountFor(['braintree']);
        $twoCheckoutUse = $gatewayCountFor(['2checkout']);
        $ccavenueUse   = $gatewayCountFor(['ccavenue']);
        $iatsUse       = $gatewayCountFor(['iatspayments', 'iats']);
        $sofortUse     = $gatewayCountFor(['sofort']);
        $paymillUse    = $gatewayCountFor(['paymill']);
        $bitpayUse     = $gatewayCountFor(['bitpay']);

        // ------------------------------------------------------------------
        // GiveWP global settings (used by the "configured" probes below).
        // ------------------------------------------------------------------
        $giveSettings = (array) get_option('give_settings', []);
        $hasSetting   = function ($keys) use ($giveSettings) {
            foreach ((array) $keys as $key) {
                if (!empty($giveSettings[$key])) return true;
            }
            return false;
        };

        // Plugin-active + configured fallback when no donations recorded yet.
        // Setting keys / main-file slugs are verified against each addon's
        // upstream source. Some plugins use folder !== bootstrap-file naming.
        $giveGoCardlessConfigured  = is_plugin_active('give-gocardless/give-gocardless.php')
            && $hasSetting(['gocardless_webhook_secret', 'gocardless_collect_billing']);
        // Upstream main-file slug is "give-paytm-gateway.php" not "give-paytm.php".
        $givePaytmConfigured       = is_plugin_active('give-paytm/give-paytm-gateway.php')
            && $hasSetting([
                'paytm_live_merchant_id',
                'paytm_live_mer_access_key',
                'paytm_sandbox_merchant_id',
                'paytm_sandbox_mer_access_key',
            ]);
        $giveBitPayConfigured      = is_plugin_active('give-bitpay/give-bitpay.php')
            && $hasSetting(['bitpay_api_client_token', 'bitpay_test_api_client_token']);
        // Upstream main-file typo: the plugin slug is "give-payumoney" but the
        // bootstrap file is named "give-paymoney.php" (missing "u").
        $givePayUmoneyConfigured   = is_plugin_active('give-payumoney/give-paymoney.php')
            && $hasSetting([
                'payumoney_live_merchant_key',
                'payumoney_live_salt_key',
                'payumoney_sandbox_merchant_key',
                'payumoney_sandbox_salt_key',
            ]);
        $giveMonerisConfigured     = is_plugin_active('give-moneris/give-moneris.php')
            && $hasSetting(['give_moneris_store_id', 'give_moneris_api_token']);
        $giveAmeriCloudConfigured  = is_plugin_active('give-americloud-payments/give-americloud.php')
            && $hasSetting(['americloud_merchant_id', 'americloud_user_id', 'americloud_pin']);
        $giveBraintreeConfigured   = is_plugin_active('give-braintree/give-braintree.php')
            && $hasSetting(['give_braintree_merchant_id', 'give_braintree_public_key', 'give_braintree_private_key']);
        // Upstream main-file slug is "give-twocheckout.php" not "give-2checkout.php".
        $give2CheckoutConfigured   = is_plugin_active('give-2checkout/give-twocheckout.php')
            && $hasSetting([
                'twocheckout-sellerId',
                'twocheckout-public-key',
                'twocheckout-private-key',
                'twocheckout-api-username',
                'twocheckout-api-password',
            ]);
        $giveCCAvenueConfigured    = is_plugin_active('give-ccavenue/give-ccavenue.php')
            && $hasSetting([
                'ccavenue_live_merchant_id',
                'ccavenue_live_working_key',
                'ccavenue_live_access_code',
            ]);
        // Upstream folder slug is "give-iats" not "give-iatspayments".
        $giveIatsConfigured        = is_plugin_active('give-iats/give-iats.php')
            && $hasSetting([
                'iats_live_agent_code',
                'iats_live_agent_password',
                'iats_sandbox_agent_code',
                'iats_sandbox_agent_password',
            ]);
        $giveSofortConfigured      = is_plugin_active('give-sofort/give-sofort.php')
            && $hasSetting(['live_sofort_config_key', 'sandbox_sofort_config_key']);
        $givePaymillConfigured     = is_plugin_active('give-paymill/give-paymill.php')
            && $hasSetting([
                'paymill_live_key',
                'paymill_live_public_key',
                'paymill_test_key',
                'paymill_test_public_key',
            ]);

        // Known gateways we treat as "supported / migratable as-is" (i.e. NOT
        // pushed as 'unknown' fallback).
        $knownGateways = [
            'stripe', 'stripe_checkout', 'stripe_ach', 'stripe_ideal', 'stripe_becs', 'stripe_sepa',
            'paypal', 'paypal_commerce', 'paypal_standard', 'paypal_pro',
            'authorize', 'razorpay', 'mollie', 'paystack', 'square',
            'payfast', 'give_payfast', 'give-payfast',
            'gocardless', 'paytm', 'payumoney', 'moneris', 'americloud',
            'braintree', '2checkout', 'ccavenue', 'iatspayments', 'iats',
            'sofort', 'paymill', 'bitpay',
            'manual', 'offline',
        ];
        $unknownGateways = [];
        foreach ($gatewayCounts as $slug => $count) {
            if (!in_array($slug, $knownGateways, true) && $count > 0) {
                $unknownGateways[$slug] = $count;
            }
        }

        // ------------------------------------------------------------------
        // Active GiveWP integration plugins (third-party connectors).
        //
        // Only third-party service connectors — not data addons. Config and
        // routing of these tools live inside the GiveWP plugin and cannot be
        // automatically migrated; admin must reconfigure on Paymattic side.
        // ------------------------------------------------------------------

        // Only integrations with NO Paymattic equivalent are probed.
        // Paymattic already supports (lite + pro): MailChimp, Slack, FluentCRM,
        // FluentSupport, ActiveCampaign, AWeber, GoogleSheet, LearnDash,
        // LifterLMS, SMSNotification, Telegram, TutorLMS, UserRegistration,
        // Webhook, Zapier — these are NOT flagged.
        //
        // "Configured" = plugin active AND has a non-empty credential in
        // GiveWP's `give_settings` option. A plugin being merely activated
        // without credentials means the admin never used it — skip.
        // ($giveSettings + $hasSetting were already defined above with the
        //  gateway probes.)

        $giveConvertKitConfigured = is_plugin_active('give-convertkit/give-convertkit.php')
            && $hasSetting(['give_convertkit_api']);

        // AWeber ships in Paymattic Pro only. Without Pro, flag as pro_required.
        $giveAWeberConfigured = is_plugin_active('give-aweber/give-aweber.php')
            && (
                $hasSetting(['give_aweber_api'])
                || (bool) get_option('give_aweber_secrets', false)
            );

        // ActiveCampaign ships in Paymattic Pro only.
        $giveActiveCampaignConfigured = is_plugin_active('give-activecampaign/give-activecampaign.php')
            && $hasSetting(['give_activecampaign_api', 'give_activecampaign_api_url']);

        $giveConstantContactConfigured = is_plugin_active('give-constant-contact/give-constant-contact.php')
            && $hasSetting([
                'give_constant_contact_access_token',
                'give_constant_contact_api_key',
            ]);

        $giveCampaignMonitorConfigured = is_plugin_active('give-campaignmonitor/give-campaignmonitor.php')
            && $hasSetting(['give_campaignmonitor_api', 'give_campaignmonitor_list']);

        $giveHubSpotConfigured = is_plugin_active('give-hubspot/give-hubspot.php')
            && $hasSetting([
                'give_hubspot_access_token',
                'give_hubspot_refresh_token',
                'give_hubspot_api_key',
            ]);

        $giveSalesforceConfigured = is_plugin_active('give-salesforce/give-salesforce.php')
            && $hasSetting([
                'give_salesforce_access_token',
                'give_salesforce_consumer_key',
                'give_salesforce_client_id',
            ]);

        $giveZohoConfigured = is_plugin_active('give-zoho-crm/give-zoho-crm.php')
            && $hasSetting([
                'give_zoho_crm_access_token',
                'give_zoho_crm_refresh_token',
                'give_zoho_crm_client_id',
            ]);

        $giveInfusionsoftConfigured = is_plugin_active('give-infusionsoft/give-infusionsoft.php')
            && $hasSetting([
                'give_infusionsoft_api_key',
                'give_infusionsoft_app_name',
            ]);

        $giveDripConfigured = is_plugin_active('give-drip/give-drip.php')
            && $hasSetting(['give_drip_api_token', 'give_drip_account_id']);

        $giveMailPoetConfigured = is_plugin_active('give-mailpoet/give-mailpoet.php')
            && (
                $hasSetting(['give_mailpoet_list', 'give_mailpoet_show_subscribe'])
                || (bool) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
                        '_give_mailpoet_list'
                    )
                )
            );

        // Automation — Zapier IS in Paymattic Pro; flag only what is missing.
        $giveMakeConfigured = is_plugin_active('give-make/give-make.php')
            && $hasSetting(['give_make_webhook_url', 'give_integromat_webhook_url']);

        $givePabblyConfigured = is_plugin_active('give-pabbly-connect/give-pabbly-connect.php')
            && $hasSetting(['give_pabbly_webhook_url', 'give_pabbly_connect_webhook']);

        $giveUncannyAutomatorConfigured = is_plugin_active('uncanny-automator/uncanny-automator.php')
            && $hasSetting(['uncanny_automator_api_key']);

        // Communication — Slack + Twilio SMS exist in Paymattic; flag Discord +
        // Google reCAPTCHA (no native Paymattic integration).
        $giveDiscordConfigured = is_plugin_active('give-discord/give-discord.php')
            && $hasSetting(['give_discord_webhook_url']);

        $giveRecaptchaConfigured = is_plugin_active('give-recaptcha/give-recaptcha.php')
            && $hasSetting(['give_recaptcha_site_key', 'give_recaptcha_secret_key']);

        // Accounting / Finance — no Paymattic equivalent.
        $giveQuickBooksConfigured = is_plugin_active('give-quickbooks/give-quickbooks.php')
            && $hasSetting([
                'give_quickbooks_access_token',
                'give_quickbooks_consumer_key',
                'give_quickbooks_client_id',
            ]);

        $giveXeroConfigured = is_plugin_active('give-xero/give-xero.php')
            && $hasSetting([
                'give_xero_access_token',
                'give_xero_consumer_key',
                'give_xero_client_id',
            ]);

        // PayFast: "configured" means plugin active AND at least one merchant
        // credential is stored in give_settings. Donations already recorded via
        // PayFast are also a strong signal even if credentials were cleared.
        $givePayFastConfigured = (
            is_plugin_active('give-payfast/give-payfast.php')
            && $hasSetting([
                'give_payfast_live_merchant_id',
                'give_payfast_live_merchant_access_key',
                'give_payfast_sandbox_merchant_id',
                'give_payfast_sandbox_merchant_access_key',
            ])
        );


        // ------------------------------------------------------------------
        // "Missing in Paymattic" catalog.
        //
        // Only items that are actively in use on this install are pushed.
        // Severity codes: 'lost' | 'partial' | 'pro_required' | 'manual_step'.
        // ------------------------------------------------------------------

        $missing = [];

        $push = function (array $row) use (&$missing) {
            $missing[] = $row;
        };

        // -------- Payment gateways (only if used) --------
        if ($paypalUse > 0) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'PayPal',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro
                    ? 'Migrated. Some Pro-only dashboard links may differ.'
                    : 'Requires Paymattic Pro. Without Pro, donations migrate as offline.',
                'in_use'   => true,
                'count'    => $paypalUse,
            ]);
        }
        if ($razorpayUse > 0) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Razorpay',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro ? 'Migrated with gateway name preserved.' : 'Requires Paymattic Pro.',
                'in_use'   => true,
                'count'    => $razorpayUse,
            ]);
        }
        if ($mollieUse > 0) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Mollie',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro ? 'Migrated with gateway name preserved.' : 'Requires Paymattic Pro.',
                'in_use'   => true,
                'count'    => $mollieUse,
            ]);
        }
        if ($paystackUse > 0) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Paystack',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro ? 'Migrated with gateway name preserved.' : 'Requires Paymattic Pro.',
                'in_use'   => true,
                'count'    => $paystackUse,
            ]);
        }
        if ($squareUse > 0) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Square',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro ? 'Migrated with gateway name preserved.' : 'Requires Paymattic Pro.',
                'in_use'   => true,
                'count'    => $squareUse,
            ]);
        }
        if ($authUse > 0) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Authorize.Net',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro
                    ? 'Migrated with gateway name preserved.'
                    : 'Authorize.Net is available in Paymattic Pro. Without Pro, donations migrate as offline.',
                'in_use'   => true,
                'count'    => $authUse,
            ]);
        }
        if ($gocardlessUse > 0 || $giveGoCardlessConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'GoCardless',
                'severity' => 'lost',
                'note'     => 'GoCardless is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $gocardlessUse,
            ]);
        }
        if ($paytmUse > 0 || $givePaytmConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Paytm',
                'severity' => 'lost',
                'note'     => 'Paytm is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $paytmUse,
            ]);
        }
        if ($payumoneyUse > 0 || $givePayUmoneyConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'PayUmoney',
                'severity' => 'lost',
                'note'     => 'PayUmoney is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $payumoneyUse,
            ]);
        }
        if ($monerisUse > 0 || $giveMonerisConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Moneris',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro
                    ? 'Migrated with gateway name preserved.'
                    : 'Moneris is available in Paymattic Pro. Without Pro, donations migrate as offline.',
                'in_use'   => true,
                'count'    => $monerisUse,
            ]);
        }
        if ($americloudUse > 0 || $giveAmeriCloudConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'AmeriCloud',
                'severity' => 'lost',
                'note'     => 'AmeriCloud is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $americloudUse,
            ]);
        }
        if ($braintreeUse > 0 || $giveBraintreeConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Braintree',
                'severity' => 'lost',
                'note'     => 'Braintree is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $braintreeUse,
            ]);
        }
        if ($twoCheckoutUse > 0 || $give2CheckoutConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => '2Checkout',
                'severity' => 'lost',
                'note'     => '2Checkout is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $twoCheckoutUse,
            ]);
        }
        if ($ccavenueUse > 0 || $giveCCAvenueConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'CCAvenue',
                'severity' => 'lost',
                'note'     => 'CCAvenue is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $ccavenueUse,
            ]);
        }
        if ($iatsUse > 0 || $giveIatsConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'iATS Payments',
                'severity' => 'lost',
                'note'     => 'iATS Payments is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $iatsUse,
            ]);
        }
        if ($sofortUse > 0 || $giveSofortConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Sofort',
                'severity' => 'lost',
                'note'     => 'Sofort is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $sofortUse,
            ]);
        }
        if ($paymillUse > 0 || $givePaymillConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'Paymill',
                'severity' => 'lost',
                'note'     => 'Paymill is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $paymillUse,
            ]);
        }
        if ($bitpayUse > 0 || $giveBitPayConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'BitPay',
                'severity' => 'lost',
                'note'     => 'BitPay is not supported in Paymattic. Existing donations migrate as Offline payments.',
                'in_use'   => true,
                'count'    => $bitpayUse,
            ]);
        }
        if ($payfastUse > 0 || $givePayFastConfigured) {
            $push([
                'category' => 'Payment Gateways',
                'feature'  => 'PayFast',
                'severity' => 'lost',
                'note'     => 'PayFast is not supported in Paymattic. Existing PayFast donations migrate as Offline payments and you will need to use a different gateway going forward.',
                'in_use'   => true,
                'count'    => $payfastUse,
            ]);
        }

        // -------- Integrations (third-party connectors active in GiveWP) --------
        // Rule: only surface integrations that are active in GiveWP AND have
        // no Paymattic equivalent. Integrations supported by Paymattic
        // (Webhooks, Zapier, ActiveCampaign, AWeber via Pro) are intentionally
        // omitted — admin can keep using them without flagging.
        if ($giveConvertKitConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'ConvertKit',
                'severity' => 'lost',
                'note'     => 'ConvertKit is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveAWeberConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'AWeber',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro
                    ? 'AWeber works in Paymattic Pro, but list IDs and OAuth tokens do not auto-migrate. Reconnect AWeber inside Paymattic after migration.'
                    : 'AWeber ships in Paymattic Pro only. Upgrade to Pro to keep AWeber list-sync working.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveActiveCampaignConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'ActiveCampaign',
                'severity' => $hasPro ? 'partial' : 'pro_required',
                'note'     => $hasPro
                    ? 'ActiveCampaign works in Paymattic Pro, but list IDs and API credentials do not auto-migrate. Reconnect ActiveCampaign inside Paymattic after migration.'
                    : 'ActiveCampaign ships in Paymattic Pro only. Upgrade to Pro to keep ActiveCampaign list-sync working.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveMailPoetConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'MailPoet',
                'severity' => 'lost',
                'note'     => 'MailPoet is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveConstantContactConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Constant Contact',
                'severity' => 'lost',
                'note'     => 'Constant Contact is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveHubSpotConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'HubSpot',
                'severity' => 'lost',
                'note'     => 'HubSpot is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveSalesforceConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Salesforce',
                'severity' => 'lost',
                'note'     => 'Salesforce is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveZohoConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Zoho CRM',
                'severity' => 'lost',
                'note'     => 'Zoho CRM is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveCampaignMonitorConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Campaign Monitor',
                'severity' => 'lost',
                'note'     => 'Campaign Monitor is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveInfusionsoftConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Keap (Infusionsoft)',
                'severity' => 'lost',
                'note'     => 'Keap (Infusionsoft) is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveDripConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Drip',
                'severity' => 'lost',
                'note'     => 'Drip is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveMakeConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Make (Integromat)',
                'severity' => 'lost',
                'note'     => 'Make (Integromat) has no native Paymattic integration. Use Paymattic Webhooks (Pro) or Zapier as a workaround.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($givePabblyConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Pabbly Connect',
                'severity' => 'lost',
                'note'     => 'Pabbly Connect has no native Paymattic integration. Use Paymattic Webhooks (Pro) or Zapier as a workaround.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveUncannyAutomatorConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Uncanny Automator',
                'severity' => 'lost',
                'note'     => 'Uncanny Automator has no native Paymattic integration. Use Paymattic Webhooks (Pro) or Zapier as a workaround.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveDiscordConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Discord',
                'severity' => 'lost',
                'note'     => 'Discord is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveRecaptchaConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Google reCAPTCHA',
                'severity' => 'lost',
                'note'     => 'GiveWP\'s donation-form reCAPTCHA config does not transfer. Paymattic provides its own reCAPTCHA setup — reconfigure inside Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveQuickBooksConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'QuickBooks',
                'severity' => 'lost',
                'note'     => 'QuickBooks is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }
        if ($giveXeroConfigured) {
            $push([
                'category' => 'Integrations',
                'feature'  => 'Xero',
                'severity' => 'lost',
                'note'     => 'Xero is not supported in Paymattic.',
                'in_use'   => true,
                'count'    => 0,
            ]);
        }

        // ------------------------------------------------------------------
        // Assemble and return the preflight response.
        // ------------------------------------------------------------------

        wp_send_json_success([
            // Plugin availability.
            'givewp_active'    => $givewpActive,
            'paymattic_active' => defined('WPPAYFORM_VERSION'),
            'is_pro'           => $hasPro,

            // Server environment.
            'memory_limit'       => ini_get('memory_limit'),
            'memory_ok'          => $memoryOk,
            'max_execution_time' => ini_get('max_execution_time'),
            'php_version'        => PHP_VERSION,

            // Record volumes.
            'give_donation_count'     => $giveDonationCount,
            'give_form_count'         => $giveFormCount,
            'give_subscription_count' => $giveSubscriptionCount,

            // Addon flags (drives which migration phases are shown in the UI).
            'recurring_addon'           => $recurringAddon,
            'gift_aid_addon'            => $giftAidAddon,
            'ffm_addon'                 => $ffmAddon,
            'fee_recovery_addon'        => $feeRecoveryAddon,
            'funds_addon'               => $fundsAddon,
            'text_to_give_addon'        => $textToGiveAddon,
            'currency_switcher_addon'   => $csCurrencySwitcherAddon,

            // Gift Aid: non-zero only when addon is active.
            'gift_aid_count' => $giftAidCount,

            // Per-gateway donation distribution.
            'gateway_counts' => $gatewayCounts,

            // Compatibility gaps surfaced to the admin in the UI.
            'missing_features' => $missing,

            // Advisory messages. UI displays these before allowing migration to start.
            'warnings' => $warnings,
        ]);
    }

    // -------------------------------------------------------------------------
    // Handler: getMigrationState
    // -------------------------------------------------------------------------

    /**
     * Return the current migration state — action: wpf_givewp_get_migration_state
     *
     * The Vue UI polls this endpoint to update the progress display during
     * an ongoing migration batch sequence.
     *
     * @return void
     */
    public function getMigrationState(): void
    {
        $this->authorize();

        wp_send_json_success(MigrationState::get());
    }

    // -------------------------------------------------------------------------
    // Handler: resetMigration
    // -------------------------------------------------------------------------

    /**
     * Reset the migration state — action: wpf_givewp_reset_migration
     *
     * Wipes the checkpoint so the user can start over. Does NOT delete any
     * Paymattic rows that were already written — that is the rollback writer's
     * job (Phase 7 of the implementation plan).
     *
     * @return void
     */
    public function resetMigration(): void
    {
        $this->authorize();

        MigrationState::reset();

        wp_send_json_success(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // Handler: migrateFormsBatch
    // -------------------------------------------------------------------------

    /**
     * Migrate all GiveWP forms to Paymattic — action: wpf_givewp_migrate_forms
     *
     * Reads every non-trashed give_forms post and creates a corresponding
     * wp_payform draft post. Forms already present in MigrationState::form_map
     * are skipped (idempotency).
     *
     * This is intentionally not paginated: form counts are typically small
     * (< 1000) and the per-form cost is one wp_insert_post + a few meta writes.
     * For the rare case of thousands of forms, the skip logic keeps retries fast.
     *
     * Response shape:
     *   {
     *     "migrated" : int — number of forms written in this call,
     *     "skipped"  : int — number already in form_map (previously migrated),
     *     "total"    : int — total forms found in GiveWP,
     *     "dry_run"  : bool
     *   }
     *
     * @return void
     */
    public function migrateFormsBatch(): void
    {
        $this->authorize();

        if (!$this->acquireBatchLock('forms')) {
            $this->rejectConcurrent('forms');
        }
        register_shutdown_function(function () { $this->releaseBatchLock('forms'); });

        global $wpdb;

        $state   = MigrationState::get();
        // TRACE-UI-001: prefer per-request POST flag; fall back to persisted state.
        $dryRun  = isset($_POST['dry_run']) ? (bool) absint(wp_unslash($_POST['dry_run'])) : (bool) ($state['dry_run'] ?? false);
        $formMap = (array) ($state['form_map'] ?? []);

        // Mark the current phase so the Vue UI can show progress.
        MigrationState::set(['phase' => 'forms', 'status' => 'running']);

        $giveForms = GiveFormReader::getAll();

        $migrated = 0;
        $skipped  = 0;
        $total    = count($giveForms);

        foreach ($giveForms as $giveForm) {
            $giveFormId = absint($giveForm['id']);

            // Primary idempotency: form_map in MigrationState.
            // Also verify the cached WPF post still exists and is not trashed —
            // a deleted/trashed form must be re-created, not silently skipped.
            if (isset($formMap[$giveFormId])) {
                $cachedWpfId  = absint($formMap[$giveFormId]);
                $existingPost = $cachedWpfId > 0 ? get_post($cachedWpfId) : null;
                if ($existingPost !== null && $existingPost->post_status !== 'trash') {
                    if (!$dryRun) {
                        PaymatticFormWriter::patchFFMElementsIfMissing($giveFormId, $cachedWpfId);
                        PaymatticFormWriter::patchEmailNotifications($giveFormId, $cachedWpfId);
                        PaymatticFormWriter::patchGiftAidSettings($giveFormId, $cachedWpfId);
                    }
                    $skipped++;
                    continue;
                }
                // Stale entry — form was deleted or trashed. Remove so it is re-created.
                unset($formMap[$giveFormId]);
            }

            // Secondary idempotency: scan for a live, non-trashed wp_payform post
            // carrying give_source_form_id = $giveFormId.  JOIN wp_posts so that
            // marker rows on unrelated post types are never mistaken for a migrated form.
            // This handles the "Start Over" case where the state was reset
            // but the Paymattic forms were not deleted.
            $existingPostId = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT pm.post_id
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = %s AND pm.meta_value = %s
                           AND p.post_type = 'wp_payform'
                           AND p.post_status != 'trash'
                         LIMIT 1",
                        'give_source_form_id',
                        (string) $giveFormId
                    )
                )
            );

            if ($existingPostId > 0) {
                // Rebuild form_map so downstream phases can resolve it.
                MigrationState::setFormMap($giveFormId, $existingPostId);
                $formMap[$giveFormId] = $existingPostId;
                if (!$dryRun) {
                    PaymatticFormWriter::patchFFMElementsIfMissing($giveFormId, $existingPostId);
                    PaymatticFormWriter::patchEmailNotifications($giveFormId, $existingPostId);
                    PaymatticFormWriter::patchGiftAidSettings($giveFormId, $existingPostId);
                }
                $skipped++;
                continue;
            }

            // SEC-MED-005: Scope orphan cleanup to wp_payform posts only.
            // A third-party plugin that happens to write the same meta_key on
            // a different post_type must not be clobbered. Resolve target IDs
            // via JOIN, then delete per-post via delete_post_meta() which is
            // safe + cache-aware.
            $orphanPostIds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT pm.post_id
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key   = %s
                       AND pm.meta_value = %s
                       AND p.post_type   = %s",
                    'give_source_form_id',
                    (string) $giveFormId,
                    'wp_payform'
                )
            );
            foreach ((array) $orphanPostIds as $orphanPostId) {
                delete_post_meta(absint($orphanPostId), 'give_source_form_id', (string) $giveFormId);
            }

            $paymatticFormId = 0;

            try {
                $paymatticFormId = PaymatticFormWriter::writeForm($giveForm, $dryRun);
            } catch (\Exception $e) {
                // Log PII-free error: include only the GiveWP form ID, not title.
                MigrationState::addError(
                    sprintf(
                        'Form migration failed for give_forms.ID=%d: %s',
                        $giveFormId,
                        substr(sanitize_text_field($e->getMessage()), 0, 200)
                    )
                );
                continue;
            }

            if (!$dryRun && $paymatticFormId > 0) {
                // Record the mapping so donation migration can resolve form IDs.
                MigrationState::setFormMap($giveFormId, $paymatticFormId);
                MigrationState::updateCounts('forms_done', 1);
                $migrated++;
            } elseif ($dryRun) {
                // In dry-run mode we count the would-be migration without writing.
                $migrated++;
            }
        }

        // Read fresh state (loop may have updated counts via updateCounts) then
        // write forms_total. Avoids the stale-read/overwrite bug.
        $freshState = MigrationState::get();
        MigrationState::set(['counts' => array_merge(
            $freshState['counts'],
            ['forms_total' => $total]
        )]);

        wp_send_json_success([
            'migrated' => $migrated,
            'skipped'  => $skipped,
            'total'    => $total,
            'dry_run'  => $dryRun,
        ]);
    }

    // -------------------------------------------------------------------------
    // Handler: migrateDonationsBatch
    // -------------------------------------------------------------------------

    /**
     * Migrate one batch of GiveWP one-time donations — action: wpf_givewp_migrate_donations
     *
     * The Vue UI calls this endpoint repeatedly until has_more = false.
     * Each call advances the cursor (last_processed_donation_id) stored in
     * MigrationState so the sequence is resumable after interruption.
     *
     * POST parameters (all optional, read from $_POST):
     *   batch_size : int  — records per batch, default 50, max 100.
     *   include_test_mode : '1' — pass '1' to include test-mode donations.
     *
     * Response shape:
     *   {
     *     "has_more"      : bool — whether more donations remain to migrate,
     *     "batch_migrated": int  — donations written in this call,
     *     "batch_skipped" : int  — donations skipped (already migrated),
     *     "last_id"       : int  — cursor position after this batch,
     *     "total_done"    : int  — cumulative donations_done from MigrationState,
     *     "dry_run"       : bool
     *   }
     *
     * @return void
     */
    public function migrateDonationsBatch(): void
    {
        $this->authorize();

        if (!$this->acquireBatchLock('donations')) {
            $this->rejectConcurrent('donations');
        }
        register_shutdown_function(function () { $this->releaseBatchLock('donations'); });

        // ------------------------------------------------------------------
        // 1. Read and sanitize request parameters.
        // ------------------------------------------------------------------

        // $_POST is read directly in all handlers: check_ajax_referer() in
        // authorize() already verified the nonce, and wp_ajax_* only fires for
        // logged-in admins — the framework Request class is not available here.
        $batchSize = absint($_POST['batch_size'] ?? 50);
        if ($batchSize < 1 || $batchSize > 100) {
            $batchSize = 50;
        }

        $includeTestMode = isset($_POST['include_test_mode']) && $_POST['include_test_mode'] === '1';

        // ------------------------------------------------------------------
        // 2. Load the current migration state.
        // ------------------------------------------------------------------

        $state   = MigrationState::get();
        // TRACE-UI-001: prefer per-request POST flag; fall back to persisted state.
        $dryRun  = isset($_POST['dry_run']) ? (bool) absint(wp_unslash($_POST['dry_run'])) : (bool) ($state['dry_run'] ?? false);
        $formMap = (array) ($state['form_map'] ?? []);
        $lastId  = absint($state['last_processed_donation_id'] ?? 0);

        MigrationState::set(['phase' => 'donations', 'status' => 'running']);

        // ------------------------------------------------------------------
        // 3. Fetch the batch from GiveWP.
        // ------------------------------------------------------------------

        $rawFetchedCount = 0;
        $maxRawPostId    = 0;
        $donations = GiveDonationReader::getBatch($lastId, $batchSize, $includeTestMode, $rawFetchedCount, $maxRawPostId);

        $batchMigrated = 0;
        $batchSkipped  = 0;
        $newLastId     = $lastId;

        // ------------------------------------------------------------------
        // 4. Migrate each donation in the batch.
        // ------------------------------------------------------------------

        $donationMap = (array) ($state['donation_map'] ?? []);

        foreach ($donations as $donation) {
            $donationId = absint($donation['donation_id']);

            // Advance the cursor even if we skip or fail this record, so the
            // next batch call does not re-load it.
            if ($donationId > $newLastId) {
                $newLastId = $donationId;
            }

            $submissionId = 0;

            try {
                $submissionId = PaymatticSubmissionWriter::writeSubmission($donation, $formMap, $dryRun);
            } catch (\Exception $e) {
                MigrationState::addError(
                    sprintf(
                        'Donation migration failed for give_payment.ID=%d: %s',
                        $donationId,
                        substr(sanitize_text_field($e->getMessage()), 0, 200)
                    )
                );
                continue;
            }

            if ($submissionId === 0 && !$dryRun) {
                // writeSubmission returned 0 = insert failure (already logged via $wpdb->last_error).
                MigrationState::addError(
                    sprintf('DB insert returned 0 for give_payment.ID=%d.', $donationId)
                );
                continue;
            }

            // ------------------------------------------------------------------
            // Determine whether this was a fresh insert or a previously migrated
            // record. PaymatticSubmissionWriter::writeSubmission() returns the
            // existing submission ID when the idempotency check fires, so we
            // detect skips by checking whether donation_map already contains the ID.
            // ------------------------------------------------------------------

            if (isset($donationMap[$donationId])) {
                // Record was already in donation_map from a previous run.
                $batchSkipped++;
            } else {
                if (!$dryRun && $submissionId > 0) {
                    MigrationState::setDonationMap($donationId, $submissionId);
                    MigrationState::updateCounts('donations_done', 1);
                    $donationMap[$donationId] = $submissionId;
                }
                $batchMigrated++;
            }
        }

        // ------------------------------------------------------------------
        // 5. Persist the new cursor position.
        //
        // Advance to the highest raw post ID (not just the filtered donation IDs)
        // so that exclusion-filtered records (renewals, subscription payments) are
        // not re-fetched on the next batch call.
        // ------------------------------------------------------------------

        $newLastId = max($newLastId, $maxRawPostId);

        if ($newLastId > $lastId) {
            MigrationState::set(['last_processed_donation_id' => $newLastId]);
        }

        // ------------------------------------------------------------------
        // 6. Determine whether more donations exist after this batch.
        //
        // Base has_more on rawFetchedCount (posts before exclusion filters), not
        // count($donations) (posts after filters). If a full batch of posts was
        // fetched but all were renewals/subscription-payments, count($donations)
        // would be 0 — incorrectly signalling end-of-data when more posts exist.
        // ------------------------------------------------------------------

        $hasMore = $rawFetchedCount >= $batchSize;

        // Reload counts to get the fresh cumulative value.
        $freshState  = MigrationState::get();
        $totalDone   = absint($freshState['counts']['donations_done'] ?? 0);

        wp_send_json_success([
            'has_more'       => $hasMore,
            'batch_migrated' => $batchMigrated,
            'batch_skipped'  => $batchSkipped,
            'last_id'        => $newLastId,
            'total_done'     => $totalDone,
            'dry_run'        => $dryRun,
        ]);
    }

    // -------------------------------------------------------------------------
    // Handler: migrateSubscriptionsBatch
    // -------------------------------------------------------------------------

    /**
     * Migrate one batch of GiveWP recurring subscriptions — action: wpf_givewp_migrate_subscriptions
     *
     * The Vue UI calls this endpoint repeatedly until has_more = false.
     * Each call advances the cursor (last_processed_subscription_id) stored in
     * MigrationState so the sequence is resumable after interruption.
     *
     * For each subscription, this handler also calls writeRenewalTransactions()
     * to migrate the individual renewal charge records that belong to it.
     *
     * POST parameters (all optional):
     *   batch_size : int — records per batch, default 50, max 100.
     *
     * Response shape:
     *   {
     *     "has_more"      : bool — whether more subscriptions remain,
     *     "batch_migrated": int  — subscriptions written in this call,
     *     "batch_skipped" : int  — subscriptions skipped (already migrated),
     *     "last_id"       : int  — cursor position after this batch,
     *     "total_done"    : int  — cumulative subscriptions_done from MigrationState,
     *     "dry_run"       : bool
     *   }
     *
     * @return void
     */
    public function migrateSubscriptionsBatch(): void
    {
        $this->authorize();

        if (!$this->acquireBatchLock('subscriptions')) {
            $this->rejectConcurrent('subscriptions');
        }
        register_shutdown_function(function () { $this->releaseBatchLock('subscriptions'); });

        // ------------------------------------------------------------------
        // 1. Read and sanitize request parameters.
        // ------------------------------------------------------------------

        // $_POST is read directly in all handlers: check_ajax_referer() in
        // authorize() already verified the nonce, and wp_ajax_* only fires for
        // logged-in admins — the framework Request class is not available here.
        $batchSize = absint($_POST['batch_size'] ?? 50);
        if ($batchSize < 1 || $batchSize > 100) {
            $batchSize = 50;
        }

        // ------------------------------------------------------------------
        // 2. Load the current migration state.
        // ------------------------------------------------------------------

        $state   = MigrationState::get();
        // TRACE-UI-001: prefer per-request POST flag; fall back to persisted state.
        $dryRun  = isset($_POST['dry_run']) ? (bool) absint(wp_unslash($_POST['dry_run'])) : (bool) ($state['dry_run'] ?? false);
        $formMap = (array) ($state['form_map'] ?? []);
        $lastId  = absint($state['last_processed_subscription_id'] ?? 0);

        MigrationState::set(['phase' => 'subscriptions', 'status' => 'running']);

        // ------------------------------------------------------------------
        // 3. Fetch the batch from GiveWP.
        // ------------------------------------------------------------------

        $subscriptions = GiveSubscriptionReader::getBatch($lastId, $batchSize);

        $batchMigrated = 0;
        $batchSkipped  = 0;
        $newLastId     = $lastId;

        // ------------------------------------------------------------------
        // 4. Migrate each subscription in the batch.
        // ------------------------------------------------------------------

        foreach ($subscriptions as $giveSub) {
            $giveSubId = absint($giveSub['id']);

            // Advance the cursor even on skip/fail so the next batch does not
            // re-load this record.
            if ($giveSubId > $newLastId) {
                $newLastId = $giveSubId;
            }

            $wpfSubscriptionId = 0;

            try {
                $wpfSubscriptionId = PaymatticSubscriptionWriter::writeSubscription(
                    $giveSub,
                    $formMap,
                    $dryRun
                );
            } catch (\Exception $e) {
                MigrationState::addError(
                    sprintf(
                        'Subscription migration failed for give_subscriptions.id=%d: %s',
                        $giveSubId,
                        substr(sanitize_text_field($e->getMessage()), 0, 200)
                    )
                );
                continue;
            }

            if ($wpfSubscriptionId === 0 && !$dryRun) {
                MigrationState::addError(
                    sprintf('DB insert returned 0 for give_subscriptions.id=%d.', $giveSubId)
                );
                continue;
            }

            // ------------------------------------------------------------------
            // Detect skip vs. fresh insert using subscription_map.
            // ------------------------------------------------------------------

            $freshState       = MigrationState::get();
            $subscriptionMap  = (array) ($freshState['subscription_map'] ?? []);

            if (isset($subscriptionMap[$giveSubId])) {
                $batchSkipped++;
            } else {
                if (!$dryRun && $wpfSubscriptionId > 0) {
                    MigrationState::setSubscriptionMap($giveSubId, $wpfSubscriptionId);
                    MigrationState::updateCounts('subscriptions_done', 1);

                    // ------------------------------------------------------------------
                    // 4a. Write renewal transactions for this subscription.
                    //
                    // We need the parent wpf_submission_id: the subscription writer
                    // inserts one submission row per subscription; we look it up via
                    // wpf_subscriptions.submission_id.
                    // ------------------------------------------------------------------

                    global $wpdb;

                    $wpfSubmissionId = absint(
                        $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT submission_id
                                 FROM {$wpdb->prefix}wpf_subscriptions
                                 WHERE id = %d
                                 LIMIT 1",
                                $wpfSubscriptionId
                            )
                        )
                    );

                    if ($wpfSubmissionId > 0) {
                        try {
                            PaymatticSubscriptionWriter::writeRenewalTransactions(
                                $giveSubId,
                                $wpfSubmissionId,
                                $wpfSubscriptionId,
                                false
                            );
                        } catch (\Exception $e) {
                            MigrationState::addError(
                                sprintf(
                                    'Renewal transactions failed for give_subscriptions.id=%d: %s',
                                    $giveSubId,
                                    substr(sanitize_text_field($e->getMessage()), 0, 200)
                                )
                            );
                        }
                    }
                }
                $batchMigrated++;
            }
        }

        // ------------------------------------------------------------------
        // 5. Persist the new cursor position.
        // ------------------------------------------------------------------

        if ($newLastId > $lastId) {
            MigrationState::set(['last_processed_subscription_id' => $newLastId]);
        }

        $hasMore = count($subscriptions) >= $batchSize;

        $freshState = MigrationState::get();
        $totalDone  = absint($freshState['counts']['subscriptions_done'] ?? 0);

        wp_send_json_success([
            'has_more'       => $hasMore,
            'batch_migrated' => $batchMigrated,
            'batch_skipped'  => $batchSkipped,
            'last_id'        => $newLastId,
            'total_done'     => $totalDone,
            'dry_run'        => $dryRun,
        ]);
    }

    // -------------------------------------------------------------------------
    // Handler: enrichGiftAidBatch
    // -------------------------------------------------------------------------

    /**
     * Enrich a batch of already-migrated submissions with Gift Aid data — action: wpf_givewp_enrich_gift_aid
     *
     * Uses 'enrich_gift_aid_cursor'
     * as the state key. Calls GiftAidEnricher::enrichBatch().
     *
     * POST parameters (all optional):
     *   batch_size : int — records per batch, default 100, max 200.
     *
     * Response shape:
     *   {
     *     "enriched" : int  — submissions enriched in this call,
     *     "skipped"  : int  — submissions skipped,
     *     "has_more" : bool — whether more submissions remain,
     *     "dry_run"  : bool
     *   }
     *
     * @return void
     */
    public function enrichGiftAidBatch(): void
    {
        $this->authorize();

        if (!$this->acquireBatchLock('enrich_gift_aid')) {
            $this->rejectConcurrent('enrich_gift_aid');
        }
        register_shutdown_function(function () { $this->releaseBatchLock('enrich_gift_aid'); });

        // $_POST is read directly in all handlers: check_ajax_referer() in
        // authorize() already verified the nonce, and wp_ajax_* only fires for
        // logged-in admins — the framework Request class is not available here.
        $batchSize = absint($_POST['batch_size'] ?? 100);
        if ($batchSize < 1 || $batchSize > 200) {
            $batchSize = 100;
        }

        $state       = MigrationState::get();
        // TRACE-UI-001: prefer per-request POST flag; fall back to persisted state.
        $dryRun      = isset($_POST['dry_run']) ? (bool) absint(wp_unslash($_POST['dry_run'])) : (bool) ($state['dry_run'] ?? false);
        $donationMap = (array) ($state['donation_map'] ?? []);
        $cursor      = absint($state['enrich_gift_aid_cursor'] ?? 0);

        MigrationState::set(['phase' => 'enrich_gift_aid', 'status' => 'running']);

        $allKeys   = array_keys($donationMap);
        $sliceKeys = array_slice($allKeys, $cursor, $batchSize);
        $slicedMap = [];

        foreach ($sliceKeys as $giveId) {
            $slicedMap[$giveId] = $donationMap[$giveId];
        }

        $result  = ['enriched' => 0, 'skipped' => 0];
        $hasMore = false;

        if (!empty($slicedMap)) {
            try {
                $result = GiftAidEnricher::enrichBatch($slicedMap, $dryRun);
            } catch (\Exception $e) {
                MigrationState::addError(
                    sprintf(
                        'GiftAidEnricher batch failed at cursor=%d: %s',
                        $cursor,
                        substr(sanitize_text_field($e->getMessage()), 0, 200)
                    )
                );
            }

            $newCursor = $cursor + count($slicedMap);
            $hasMore   = ($newCursor < count($allKeys));

            MigrationState::set(['enrich_gift_aid_cursor' => $newCursor]);
        }

        wp_send_json_success([
            'enriched' => $result['enriched'],
            'skipped'  => $result['skipped'],
            'has_more' => $hasMore,
            'dry_run'  => $dryRun,
        ]);
    }

    // -------------------------------------------------------------------------
    // Handler: generateReport
    // -------------------------------------------------------------------------

    /**
     * Generate the post-migration validation report — action: wpf_givewp_generate_report
     *
     * Calls MigrationReporter::generateReport() which performs read-only queries
     * against both GiveWP source tables and Paymattic destination tables, then
     * cross-references against MigrationState to produce counts, warnings, and
     * a list of subscriptions where the billing interval could not be mapped.
     *
     * Response shape: see MigrationReporter::generateReport() return type.
     *
     * @return void
     */
    public function generateReport(): void
    {
        $this->authorize();

        $report = MigrationReporter::generateReport();

        wp_send_json_success($report);
    }

    // -------------------------------------------------------------------------
    // Handler: performRollback
    // -------------------------------------------------------------------------

    /**
     * Roll back all Paymattic data created by the migration — action: wpf_givewp_rollback
     *
     * Reads the POST parameter `dry_run` (absint — 1 = dry run, 0 = real delete)
     * and delegates to MigrationReporter::performRollback().
     *
     * In dry-run mode the response contains the counts of rows that WOULD be
     * deleted without actually performing any delete. The UI uses this to show
     * a confirmation summary before the admin confirms the destructive action.
     *
     * Response shape:
     *   {
     *     "submissions_deleted"   : int,
     *     "subscriptions_deleted" : int,
     *     "forms_deleted"         : int,
     *     "meta_rows_deleted"     : int,
     *     "order_items_deleted"   : int,
     *     "transactions_deleted"  : int,
     *     "activities_deleted"    : int,
     *     "dry_run"               : bool
     *   }
     *
     * @return void
     */
    public function performRollback(): void
    {
        $this->authorize();

        if (!$this->acquireBatchLock('rollback')) {
            $this->rejectConcurrent('rollback');
        }
        register_shutdown_function(function () { $this->releaseBatchLock('rollback'); });

        // absint() ensures the value is 0 or 1 — truthy/falsy without
        // any risk of injecting unexpected values from POST.
        $dryRun = (bool) absint($_POST['dry_run'] ?? 1);

        $result = MigrationReporter::performRollback($dryRun);

        wp_send_json_success($result);
    }

    // -------------------------------------------------------------------------
    // Handler: markComplete
    // -------------------------------------------------------------------------

    /**
     * Mark the migration as complete — action: wpf_givewp_mark_complete
     *
     * Sets status = 'completed' and completed_at in MigrationState. Called by
     * the Vue UI after all migration phases finish and the admin has reviewed
     * the validation report.
     *
     * @return void
     */
    public function markComplete(): void
    {
        $this->authorize();

        MigrationState::set([
            'status'       => 'completed',
            'completed_at' => current_time('c'),
        ]);

        $this->grantGiveDonorDashboardAccess();

        wp_send_json_success(['success' => true]);
    }

    /**
     * Add the `give_donor` role to Paymattic's user-dashboard permitted roles
     * so that GiveWP donors (whose WP users carry this role) can access the
     * Paymattic User Dashboard immediately after migration. Idempotent — the
     * role is only appended when absent.
     *
     * @return void
     */
    private function grantGiveDonorDashboardAccess(): void
    {
        if (!get_role('give_donor')) {
            return;
        }

        $roles = get_option('_wppayform_dashboard_permitted_roles', []);
        if (!is_array($roles)) {
            $roles = [];
        }

        if (in_array('give_donor', $roles, true)) {
            return;
        }

        $roles[] = 'give_donor';
        update_option('_wppayform_dashboard_permitted_roles', array_values(array_unique($roles)));
    }

    // -------------------------------------------------------------------------
    // Handler: enrichFFMBatch
    // -------------------------------------------------------------------------

    /**
     * Enrich a batch of already-migrated submissions with FFM custom field data — action: wpf_givewp_enrich_ffm
     *
     * Reads a slice of MigrationState::donation_map and builds the enrichment
     * map expected by FFMEnricher::enrichBatch() (which needs both the wpf_submission_id
     * AND the give_form_id per donation so it can look up FFM field definitions).
     *
     * The give_form_id is resolved from wpf_submissions.form_id via the inverse
     * of formMap, or directly from wpf_submissions if available.
     *
     * POST parameters (all optional):
     *   batch_size : int — records per batch, default 100, max 200.
     *
     * Response shape:
     *   {
     *     "enriched" : int  — submissions enriched in this call,
     *     "skipped"  : int  — submissions skipped,
     *     "has_more" : bool — whether more submissions remain,
     *     "dry_run"  : bool
     *   }
     *
     * @return void
     */
    public function enrichFFMBatch(): void
    {
        $this->authorize();

        if (!$this->acquireBatchLock('enrich_ffm')) {
            $this->rejectConcurrent('enrich_ffm');
        }
        register_shutdown_function(function () { $this->releaseBatchLock('enrich_ffm'); });

        // $_POST is read directly in all handlers: check_ajax_referer() in
        // authorize() already verified the nonce, and wp_ajax_* only fires for
        // logged-in admins — the framework Request class is not available here.
        $batchSize = absint($_POST['batch_size'] ?? 100);
        if ($batchSize < 1 || $batchSize > 200) {
            $batchSize = 100;
        }

        $state       = MigrationState::get();
        // TRACE-UI-001: prefer per-request POST flag; fall back to persisted state.
        $dryRun      = isset($_POST['dry_run']) ? (bool) absint(wp_unslash($_POST['dry_run'])) : (bool) ($state['dry_run'] ?? false);
        $donationMap = (array) ($state['donation_map'] ?? []);
        $formMap     = (array) ($state['form_map']     ?? []);
        $cursor      = absint($state['enrich_ffm_cursor'] ?? 0);

        MigrationState::set(['phase' => 'enrich_ffm', 'status' => 'running']);

        $allKeys   = array_keys($donationMap);
        $sliceKeys = array_slice($allKeys, $cursor, $batchSize);

        // Build the enrichment map: give_donation_id → [wpf_submission_id, give_form_id].
        // The give_form_id is the inverse-lookup of formMap (Paymattic formId → GiveWP formId).
        // We build the inverse once per batch call to avoid repeated array scanning.
        $inverseFormMap = [];
        foreach ($formMap as $giveFormId => $wpfFormId) {
            $inverseFormMap[absint($wpfFormId)] = absint($giveFormId);
        }

        $enrichmentMap = [];

        if (!empty($sliceKeys)) {
            global $wpdb;

            // Collect submission IDs for this slice to resolve form_id in one query.
            $submissionIdMap = [];
            foreach ($sliceKeys as $giveDonationId) {
                $giveDonationId  = absint($giveDonationId);
                $wpfSubmissionId = absint($donationMap[$giveDonationId] ?? 0);
                if ($wpfSubmissionId > 0) {
                    $submissionIdMap[$giveDonationId] = $wpfSubmissionId;
                }
            }

            $submissionFormMap = [];
            if (!empty($submissionIdMap)) {
                $submissionIds  = array_values(array_map('absint', $submissionIdMap));
                $idPlaceholders = implode(', ', array_fill(0, count($submissionIds), '%d'));

                // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, form_id FROM {$wpdb->prefix}wpf_submissions WHERE id IN ({$idPlaceholders})",
                        ...$submissionIds
                    ),
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

                foreach ((array) $rows as $row) {
                    $submissionFormMap[absint($row['id'])] = absint($row['form_id']);
                }
            }

            foreach ($sliceKeys as $giveDonationId) {
                $giveDonationId  = absint($giveDonationId);
                $wpfSubmissionId = $submissionIdMap[$giveDonationId] ?? 0;

                if ($wpfSubmissionId < 1) {
                    continue;
                }

                $wpfFormId  = $submissionFormMap[$wpfSubmissionId] ?? 0;
                $giveFormId = $inverseFormMap[$wpfFormId] ?? 0;

                // If we cannot resolve the GiveWP form ID, skip — FFMEnricher
                // needs it to load the field definitions from postmeta.
                if ($giveFormId < 1) {
                    continue;
                }

                $enrichmentMap[$giveDonationId] = [
                    'wpf_submission_id' => $wpfSubmissionId,
                    'give_form_id'      => $giveFormId,
                ];
            }
        }

        $result  = ['enriched' => 0, 'skipped' => 0];
        $hasMore = false;

        if (!empty($enrichmentMap)) {
            try {
                $result = FFMEnricher::enrichBatch($enrichmentMap, $formMap, $dryRun);
            } catch (\Exception $e) {
                MigrationState::addError(
                    sprintf(
                        'FFMEnricher batch failed at cursor=%d: %s',
                        $cursor,
                        substr(sanitize_text_field($e->getMessage()), 0, 200)
                    )
                );
            }
        }

        $newCursor = $cursor + count($sliceKeys);
        $hasMore   = ($newCursor < count($allKeys));

        MigrationState::set(['enrich_ffm_cursor' => $newCursor]);

        wp_send_json_success([
            'enriched' => $result['enriched'],
            'skipped'  => $result['skipped'],
            'has_more' => $hasMore,
            'dry_run'  => $dryRun,
        ]);
    }

}
