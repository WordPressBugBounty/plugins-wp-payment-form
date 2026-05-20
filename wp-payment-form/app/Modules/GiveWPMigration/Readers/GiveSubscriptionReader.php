<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Readers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GiveSubscriptionReader — reads GiveWP recurring subscription records for migration.
 *
 * Implements cursor-based batch pagination (WHERE s.id > $lastId ORDER BY s.id ASC)
 * matching the same pattern used by GiveDonationReader, so batches are resumable
 * after interruption.
 *
 * Data sources combined per subscription:
 *   - {prefix}give_subscriptions      — subscription record (period, amounts, status, etc.)
 *   - {prefix}posts                   — parent payment post (post_date, post_status)
 *   - {prefix}give_donationmeta       — gateway, currency, IP, email, name, purchase key
 *   - {prefix}give_donors             — WP user_id for the subscriber
 *
 * Renewal payments (child posts with _give_is_donation_recurring = '1')
 * are NOT loaded here. The subscription writer handles renewals via
 * writeRenewalTransactions() which queries them from give_donationmeta.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Readers
 * @since   4.6.21
 */
class GiveSubscriptionReader
{
    /**
     * Return a batch of GiveWP subscription records starting after $lastId.
     *
     * Each returned array contains all columns from give_subscriptions plus
     * the parent payment's post_date and post_status, plus relevant
     * give_donationmeta values as flat keys, plus the donor's WP user_id.
     *
     * @param int $lastId    Cursor: only return subscriptions with id > $lastId.
     *                        Pass 0 to start from the beginning.
     * @param int $batchSize Maximum records to return. Capped at 100 internally.
     *
     * @return array Array of subscription data arrays, one entry per subscription.
     */
    public static function getBatch(int $lastId, int $batchSize = 50): array
    {
        global $wpdb;

        // Hard cap to prevent memory exhaustion on large installations.
        $batchSize = min(absint($batchSize), 100);
        if ($batchSize < 1) {
            $batchSize = 50;
        }

        $lastId = absint($lastId);

        $subscriptionsTable = $wpdb->prefix . 'give_subscriptions';
        $donationMetaTable  = $wpdb->prefix . 'give_donationmeta';
        $donorsTable        = $wpdb->prefix . 'give_donors';

        // ------------------------------------------------------------------
        // 1. Fetch subscription stubs with parent-payment post metadata.
        //
        // LEFT JOIN to wp_posts ensures subscriptions with missing or deleted
        // parent payments are still returned — the post_date and post_status
        // fields will be NULL and the writer falls back gracefully.
        //
        // All columns are selected from give_subscriptions so we get the full
        // record without enumerating every column explicitly.
        // ------------------------------------------------------------------

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                     s.id,
                     s.customer_id,
                     s.period,
                     s.frequency,
                     s.initial_amount,
                     s.recurring_amount,
                     s.recurring_fee_amount,
                     s.bill_times,
                     s.transaction_id,
                     s.parent_payment_id,
                     s.payment_mode,
                     s.product_id,
                     s.campaign_id,
                     s.created,
                     s.expiration,
                     s.status,
                     s.profile_id,
                     s.notes,
                     p.post_date,
                     p.post_status
                 FROM {$subscriptionsTable} s
                 LEFT JOIN {$wpdb->posts} p ON p.ID = s.parent_payment_id
                 WHERE s.id > %d
                 ORDER BY s.id ASC
                 LIMIT %d",
                $lastId,
                $batchSize
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return [];
        }

        // ------------------------------------------------------------------
        // 2. For each subscription, enrich with parent-payment meta and
        //    donor data. Done per-row to avoid a single unbounded JOIN.
        // ------------------------------------------------------------------

        $subscriptions = [];

        foreach ($rows as $row) {
            $parentPaymentId = absint($row['parent_payment_id']);
            $customerId      = absint($row['customer_id']);

            // ------------------------------------------------------------------
            // 2a. Load give_donationmeta for the parent payment.
            //
            // We collect only the specific keys needed for the migration to keep
            // memory usage low. Unknown or absent keys default to empty strings
            // in the assembled record.
            // ------------------------------------------------------------------

            $neededMetaKeys = [
                '_give_payment_currency',
                '_give_payment_gateway',
                '_give_payment_donor_ip',
                '_give_payment_donor_email',
                '_give_donor_billing_first_name',
                '_give_donor_billing_last_name',
                '_give_payment_purchase_key',
                '_give_stripe_customer_id',
                '_give_mollie_customer_id',    // Mollie subscriptions
                '_give_razorpay_customer_id',  // Razorpay (if present)
                '_give_paystack_customer_id',  // Paystack (if present)
                '_give_square_customer_id',    // Square (if present)
            ];

            $meta = [];

            if ($parentPaymentId > 0) {
                // Build a safe IN() clause using %s placeholders.
                // We know the count is fixed (8 items) so this is safe.
                $placeholders = implode(', ', array_fill(0, count($neededMetaKeys), '%s'));

                // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $rawMeta = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT meta_key, meta_value
                         FROM {$donationMetaTable}
                         WHERE donation_id = %d
                           AND meta_key IN ({$placeholders})",
                        array_merge([$parentPaymentId], $neededMetaKeys)
                    ),
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

                foreach ($rawMeta as $metaRow) {
                    $meta[$metaRow['meta_key']] = $metaRow['meta_value'];
                }
            }

            // ------------------------------------------------------------------
            // 2b. Resolve the donor's WordPress user ID.
            //
            // give_subscriptions.customer_id = give_donors.id (NOT a WP user ID).
            // We look up give_donors.user_id to get the WP user ID.
            // Zero in give_donors.user_id means a guest subscriber.
            // ------------------------------------------------------------------

            $wpUserId = 0;

            if ($customerId > 0) {
                $wpUserId = absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT user_id FROM {$donorsTable} WHERE id = %d LIMIT 1",
                            $customerId
                        )
                    )
                );
            }

            // ------------------------------------------------------------------
            // 3. Assemble the flat subscription record.
            //
            // We merge give_subscriptions columns, post metadata from the parent
            // payment, and the relevant donationmeta values into a single array.
            // The writer is responsible for all type conversions and sanitization.
            // ------------------------------------------------------------------

            $subscription = array_merge(
                $row,
                [
                    // Resolved WP user ID (0 = guest).
                    'wp_user_id'                => $wpUserId,

                    // Extracted meta keys — default to empty string when absent.
                    '_give_payment_currency'            => $meta['_give_payment_currency']           ?? '',
                    '_give_payment_gateway'             => $meta['_give_payment_gateway']            ?? '',
                    '_give_payment_donor_ip'            => $meta['_give_payment_donor_ip']           ?? '',
                    '_give_payment_donor_email'         => $meta['_give_payment_donor_email']        ?? '',
                    '_give_donor_billing_first_name'    => $meta['_give_donor_billing_first_name']   ?? '',
                    '_give_donor_billing_last_name'     => $meta['_give_donor_billing_last_name']    ?? '',
                    '_give_payment_purchase_key'        => $meta['_give_payment_purchase_key']       ?? '',
                    '_give_stripe_customer_id'          => $meta['_give_stripe_customer_id']         ?? '',
                    '_give_mollie_customer_id'          => $meta['_give_mollie_customer_id']         ?? '',
                    '_give_razorpay_customer_id'        => $meta['_give_razorpay_customer_id']       ?? '',
                    '_give_paystack_customer_id'        => $meta['_give_paystack_customer_id']       ?? '',
                    '_give_square_customer_id'          => $meta['_give_square_customer_id']         ?? '',
                ]
            );

            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }
}
