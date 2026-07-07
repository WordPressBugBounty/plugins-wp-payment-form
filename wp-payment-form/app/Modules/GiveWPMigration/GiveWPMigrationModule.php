<?php

namespace WPPayForm\App\Modules\GiveWPMigration;

if (!defined('ABSPATH')) {
    exit;
}

use WPPayForm\App\Modules\GiveWPMigration\Admin\MigratorAjaxHandler;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\GatewayMapper;

/**
 * GiveWP → Paymattic Migration Module
 *
 * Entry point for the migration tool. Bootstrapped from app/Hooks/actions.php
 * on the admin_menu hook (priority 20, after the main Paymattic menu is registered).
 *
 * This module intentionally has a minimal footprint: it injects the migrator config
 * into wpPayFormsAdmin and delegates all AJAX work to dedicated handler classes.
 * The UI is rendered as a tab inside the Global Settings Vue app (settings.js).
 *
 * @package WPPayForm\App\Modules\GiveWPMigration
 * @since   4.6.21
 */
class GiveWPMigrationModule
{
    /**
     * Bootstrap the migration module.
     *
     * Called from the admin_menu action hook in app/Hooks/actions.php.
     * Injects the migrator config into the main Paymattic admin JS object
     * so the GiveWP Migrator component rendered via settings.js can access
     * the AJAX URL and nonce without a separate wp_localize_script call.
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('wppayform/admin_app_vars', function (array $vars): array {
            $vars['wppayform_migrator'] = [
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('wpf_givewp_migration'),
                'isPro'        => defined('WPPAYFORMHASPRO'),
                'givewpActive' => class_exists('Give'),
            ];
            return $vars;
        });
        // Enrich transaction_url on migrated PayPal entries.
        // The pre-computed vendor URL is stored in wpf_meta as 'give_transaction_url'
        // during migration. Priority 20 runs after any gateway-specific handler;
        // we only set the URL when the transaction does not already have one.
        add_filter('wppayform/entry_transactions_paypal', [$this, 'enrichTransactionUrlFromMeta'], 20, 2);

        // Same enrichment for entries migrated before the paypal-commerce fix —
        // those were stored with payment_method = 'offline'. Real offline payments
        // have no give_transaction_url in meta, so this is a no-op for them.
        add_filter('wppayform/entry_transactions_offline', [$this, 'enrichTransactionUrlFromMeta'], 20, 2);
    }

    /**
     * Copy give_transaction_url from wpf_meta onto migrated transaction objects.
     *
     * Called via wppayform/entry_transactions_{method}. Reads 'give_transaction_url'
     * from the submission's wpf_meta and sets it as transaction->transaction_url so
     * Entry.vue can display the "View on ..." link for migrated GiveWP donations.
     *
     * @param  \Illuminate\Support\Collection|\ArrayObject $transactions  Transaction collection.
     * @param  int                                         $submissionId  wpf_submissions.id.
     * @return \Illuminate\Support\Collection|\ArrayObject
     */
    public function enrichTransactionUrlFromMeta($transactions, int $submissionId)
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value
                 FROM {$wpdb->prefix}wpf_meta
                 WHERE meta_group = %s
                   AND option_id  = %d
                   AND meta_key   IN ('give_transaction_url', 'give_original_gateway')
                 LIMIT 2",
                'wpf_submissions',
                absint($submissionId)
            ),
            OBJECT_K
        );

        if (empty($rows)) {
            return $transactions;
        }

        $storedUrl       = isset($rows['give_transaction_url'])
            ? $rows['give_transaction_url']->meta_value
            : '';
        $originalGateway = isset($rows['give_original_gateway'])
            ? $rows['give_original_gateway']->meta_value
            : '';

        // When no pre-computed URL exists (entries migrated before the fix),
        // compute it on the fly from the original GiveWP gateway + charge_id.
        if (!$storedUrl && $originalGateway) {
            $mappedGateway = GatewayMapper::mapGateway($originalGateway);

            foreach ($transactions as $transaction) {
                // Correct the display payment_method label for entries stored as 'offline'
                // that originated from a real gateway — read-only, does not touch the DB.
                if ($mappedGateway !== 'offline' && $transaction->payment_method === 'offline') {
                    $transaction->payment_method = $mappedGateway;
                }

                if (!empty($transaction->transaction_url)) {
                    continue;
                }
                $chargeId    = $transaction->charge_id ?? '';
                $paymentMode = $transaction->payment_mode ?? 'live';
                $computed    = GatewayMapper::getPaymentUrl($mappedGateway, (string) $chargeId, (string) $paymentMode);
                if ($computed !== '') {
                    $transaction->transaction_url = $computed;
                }
            }

            return $transactions;
        }

        if (!$storedUrl) {
            return $transactions;
        }

        // Stored URL path: also fix the display label when original gateway is known.
        $mappedForLabel = $originalGateway ? GatewayMapper::mapGateway($originalGateway) : '';

        foreach ($transactions as $transaction) {
            if ($mappedForLabel && $mappedForLabel !== 'offline' && $transaction->payment_method === 'offline') {
                $transaction->payment_method = $mappedForLabel;
            }
            if (empty($transaction->transaction_url)) {
                $transaction->transaction_url = $storedUrl;
            }
        }

        return $transactions;
    }
}
