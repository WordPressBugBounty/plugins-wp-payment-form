<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Readers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GiveDonationReader — reads GiveWP one-time donation records for migration.
 *
 * Implements cursor-based batch pagination (WHERE ID > $lastId ORDER BY ID ASC)
 * so batches can be retried idempotently without re-reading the same records.
 *
 * Exclusion rules applied by this reader:
 *
 *   1. Renewal payments: posts where post_parent > 0 AND _give_is_donation_recurring = '1'
 *      These are individual charge records for an active subscription. Phase 4
 *      (subscription migration) handles them by reading the wpf_subscriptions row.
 *
 *   2. Subscription first-payments: posts where _give_subscription_payment = '1'
 *      GiveWP marks the initial payment for a recurring donation with this flag.
 *      The subscription migration phase creates a wpf_subscription record whose
 *      initial_amount represents this payment — migrating it here would duplicate it.
 *
 *   3. Test-mode donations (optional): excluded when $includeTestMode = false
 *      (the default). GiveWP stores _give_payment_mode = 'test' in give_donationmeta
 *      for donations processed in Stripe test mode.
 *
 *   4. Trashed posts are excluded via the post_status != 'trash' filter.
 *
 * Meta loading strategy:
 *   All give_donationmeta rows for each donation are fetched in a single query
 *   per donation and flattened into the donation array. This is a deliberate
 *   trade-off: N single-row meta fetches are slower than batched, but this avoids
 *   a single huge JOIN query that can OOM on large datasets.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Readers
 * @since   4.6.21
 */
class GiveDonationReader
{
    /**
     * Return a batch of GiveWP donations starting after $lastId.
     *
     * @param int  $lastId          Cursor: only return donations with ID > $lastId.
     *                               Pass 0 to start from the beginning.
     * @param int  $batchSize       Maximum records to return. Capped at 100 internally.
     * @param bool $includeTestMode When false (default), skip test-mode donations.
     *
     * @return array Array of donation data arrays. Each array contains all
     *               post fields plus all give_donationmeta entries flattened.
     */
    /**
     * @param int  $lastId
     * @param int  $batchSize
     * @param bool $includeTestMode
     * @param int  $rawFetchedCount  OUT — number of raw posts fetched before exclusion filters.
     * @param int  $maxRawPostId     OUT — highest post ID among raw fetched posts (for cursor).
     */
    public static function getBatch(int $lastId, int $batchSize = 50, bool $includeTestMode = false, int &$rawFetchedCount = 0, int &$maxRawPostId = 0): array
    {
        global $wpdb;

        // Hard cap to prevent memory exhaustion on large installations.
        $batchSize = min(absint($batchSize), 100);
        if ($batchSize < 1) {
            $batchSize = 50;
        }

        $lastId = absint($lastId);

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->prefix is a trusted, server-controlled value.
        $donationMetaTable = $wpdb->prefix . 'give_donationmeta';

        // ------------------------------------------------------------------
        // 1. Fetch the post stubs for this batch.
        //
        // We load only the columns we need to keep the result set small.
        // Meta will be fetched per-post in step 2.
        // ------------------------------------------------------------------

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_status, post_date, post_modified, post_parent
                 FROM {$wpdb->posts}
                 WHERE post_type   = %s
                   AND post_status != %s
                   AND ID          > %d
                 ORDER BY ID ASC
                 LIMIT %d",
                'give_payment',
                'trash',
                $lastId,
                $batchSize
            ),
            ARRAY_A
        );

        if (empty($posts)) {
            $rawFetchedCount = 0;
            $maxRawPostId    = $lastId;
            return [];
        }

        // Track raw stats BEFORE exclusion filters so the caller can base
        // has_more and cursor advancement on the full set of fetched posts.
        $rawFetchedCount = count($posts);
        // Posts are sorted ASC by ID, so the last element has the highest ID.
        $maxRawPostId = absint($posts[$rawFetchedCount - 1]['ID']);

        // ------------------------------------------------------------------
        // 2. For each post, load ALL give_donationmeta rows and flatten.
        //
        // Meta keys are unique per donation in GiveWP's normal data model, but
        // if duplicates exist we keep the last value (safe fallback).
        // ------------------------------------------------------------------

        $donations = [];

        foreach ($posts as $post) {
            $donationId = absint($post['ID']);

            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $donationMetaTable composed from $wpdb->prefix only.
            $rawMeta = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value
                     FROM {$donationMetaTable}
                     WHERE donation_id = %d",
                    $donationId
                ),
                ARRAY_A
            );

            $meta = [];
            foreach ($rawMeta as $row) {
                $meta[$row['meta_key']] = $row['meta_value'];
            }

            // ------------------------------------------------------------------
            // 3. Apply exclusion rules.
            //
            // Rule 1 — Renewal payments: post_parent > 0 and _give_is_donation_recurring = '1'.
            // These records represent individual charge events inside an active
            // subscription. Phase 4 handles them via the give_subscriptions table.
            // ------------------------------------------------------------------

            $postParent      = absint($post['post_parent']);
            $isRecurring     = isset($meta['_give_is_donation_recurring']) && $meta['_give_is_donation_recurring'] === '1';
            $isRenewal       = ($postParent > 0) && $isRecurring;

            if ($isRenewal) {
                continue;
            }

            // Rule 2 — Subscription first-payments.
            // GiveWP sets _give_subscription_payment = '1' on the initial payment
            // of a recurring donation. Phase 4 (subscription migration) will
            // associate this amount with the wpf_subscriptions.initial_amount column.

            $isSubscriptionPayment = isset($meta['_give_subscription_payment']) && $meta['_give_subscription_payment'] === '1';

            if ($isSubscriptionPayment) {
                continue;
            }

            // Rule 3 — Test-mode donations (skipped unless caller opts in).
            if (!$includeTestMode) {
                $paymentMode = isset($meta['_give_payment_mode']) ? $meta['_give_payment_mode'] : 'live';
                if ($paymentMode === 'test') {
                    continue;
                }
            }

            // ------------------------------------------------------------------
            // 4. Resolve the donor's WordPress user ID.
            //
            // _give_payment_donor_id is the give_donors.id (NOT a WP user ID).
            // We look up give_donors.user_id to get the WP user_id.
            // A value of 0 in give_donors.user_id indicates a guest donor.
            // ------------------------------------------------------------------

            $giveDonorid = absint($meta['_give_payment_donor_id'] ?? 0);
            $wpUserId    = 0;

            if ($giveDonorid > 0) {
                $donorsTable = $wpdb->prefix . 'give_donors';
                $wpUserId    = absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT user_id FROM {$donorsTable} WHERE id = %d LIMIT 1",
                            $giveDonorid
                        )
                    )
                );
            }

            // ------------------------------------------------------------------
            // 5. Assemble the donation record.
            //
            // We merge the post fields and all meta into a single flat array.
            // All meta values are raw strings at this point; type conversions
            // (amounts → minor units, status mapping, gateway mapping) are the
            // responsibility of PaymatticSubmissionWriter.
            // ------------------------------------------------------------------

            $donation = array_merge(
                [
                    'donation_id'        => $donationId,
                    'post_status'        => sanitize_text_field($post['post_status']),
                    'post_date'          => $post['post_date'],
                    'post_modified'      => $post['post_modified'],
                    'post_parent'        => $postParent,
                    // Resolved WP user ID (0 = guest).
                    'wp_user_id'         => $wpUserId,
                ],
                $meta
            );

            $donations[] = $donation;
        }

        return $donations;
    }
}
