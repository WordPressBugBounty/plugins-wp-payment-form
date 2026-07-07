<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Writers;

if (!defined('ABSPATH')) {
    exit;
}

use WPPayForm\App\Modules\GiveWPMigration\Mappers\AmountConverter;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\GatewayMapper;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\IntervalMapper;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\StatusMapper;
use WPPayForm\App\Modules\GiveWPMigration\MigrationState;

/**
 * PaymatticSubscriptionWriter — inserts a migrated GiveWP subscription into Paymattic's tables.
 *
 * Writes directly to the database using $wpdb->insert() / $wpdb->update() to
 * avoid triggering Paymattic hooks (form_payment_success, GlobalNotificationManager,
 * etc.) that would send emails and fire integrations for historical data.
 *
 * Tables written per subscription:
 *   {prefix}wpf_submissions         — one "parent" submission representing the initial payment
 *   {prefix}wpf_order_transactions  — initial/signup transaction
 *   {prefix}wpf_order_items         — one signup_fee line item
 *   {prefix}wpf_subscriptions       — the subscription record itself
 *   {prefix}wpf_meta                — source IDs, interval notes, Stripe reference
 *
 * Idempotency guarantee:
 *   Before inserting, the writer checks wpf_meta for an existing row with
 *   meta_key = 'give_subscription_id' and meta_value = $giveSubId.
 *   If found, the existing wpf_subscriptions.id is returned and no rows are written.
 *   This makes the migrator safe to re-run after a partial failure.
 *
 * Renewal handling:
 *   After the subscription row is written, the caller should invoke
 *   writeRenewalTransactions() to migrate individual renewal charge records.
 *   These become wpf_order_transactions with transaction_type = 'subscription'.
 *
 * Key column note:
 *   wpf_subscriptions.vendor_subscriptipn_id has a known typo (subscriptipn).
 *   We write to that exact column name to match the live schema.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Writers
 * @since   4.6.21
 */
class PaymatticSubscriptionWriter
{
    /**
     * Write a single GiveWP subscription as a Paymattic subscription.
     *
     * @param array $giveSub  Subscription data from GiveSubscriptionReader::getBatch().
     *                         Expected keys: id, customer_id, period, frequency,
     *                         initial_amount, recurring_amount, bill_times,
     *                         transaction_id, parent_payment_id, payment_mode,
     *                         product_id, campaign_id, created, expiration,
     *                         status, profile_id, notes, post_date, post_status,
     *                         wp_user_id, plus all _give_* donationmeta keys.
     * @param array $formMap  GiveWP form_id → Paymattic form_id lookup table.
     *                         From MigrationState::get()['form_map'].
     * @param bool  $dryRun   When true, all logic runs but no writes are performed.
     *
     * @return int Paymattic wpf_subscriptions.id, or 0 on dry run / failure.
     */
    public static function writeSubscription(array $giveSub, array $formMap, bool $dryRun = false): int
    {
        global $wpdb;

        $giveSubId = absint($giveSub['id']);

        // ------------------------------------------------------------------
        // 1. Idempotency check.
        //
        // Query wpf_meta for an existing row that marks this GiveWP subscription
        // as already migrated. The meta_group 'wpf_subscriptions' scopes the
        // lookup to subscription records specifically, avoiding false matches
        // against give_source_donation_id rows in submission meta.
        // ------------------------------------------------------------------

        $existingSubscriptionId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_id
                 FROM {$wpdb->prefix}wpf_meta
                 WHERE meta_key   = %s
                   AND meta_value = %s
                   AND meta_group = %s
                 LIMIT 1",
                'give_subscription_id',
                (string) $giveSubId,
                'wpf_subscriptions'
            )
        );

        if ($existingSubscriptionId) {
            // Backfill form_data_formatted when it was stored as empty by an older run.
            $subRow = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT sub.submission_id FROM {$wpdb->prefix}wpf_subscriptions sub
                     WHERE sub.id = %d LIMIT 1",
                    absint($existingSubscriptionId)
                ),
                ARRAY_A
            );
            if (!empty($subRow['submission_id'])) {
                $subId   = absint($subRow['submission_id']);
                $submRow = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT customer_name, customer_email, form_data_formatted
                         FROM {$wpdb->prefix}wpf_submissions WHERE id = %d LIMIT 1",
                        $subId
                    ),
                    ARRAY_A
                );
                if ($submRow) {
                    $existing = wppayform_safeUnserialize($submRow['form_data_formatted'] ?? '');
                    if (!is_array($existing) || empty($existing['customer_name'])) {
                        $wpdb->update(
                            $wpdb->prefix . 'wpf_submissions',
                            ['form_data_formatted' => maybe_serialize([
                                'customer_name'  => $submRow['customer_name']  ?? '',
                                'customer_email' => $submRow['customer_email'] ?? '',
                            ])],
                            ['id' => $subId],
                            ['%s'],
                            ['%d']
                        );
                    }
                }
            }
            return absint($existingSubscriptionId);
        }

        // ------------------------------------------------------------------
        // 2. Resolve the target Paymattic form ID.
        //
        // GiveWP gives_subscriptions.product_id is the give_forms post ID.
        // (campaign_id was introduced in newer GiveWP for campaign filtering;
        //  product_id is the reliable form reference for all GiveWP versions.)
        // ------------------------------------------------------------------

        $giveFormId      = absint($giveSub['product_id'] ?? 0);
        $paymatticFormId = absint($formMap[$giveFormId] ?? 0);

        // ------------------------------------------------------------------
        // 3. Map status, gateway, and convert amounts.
        //
        // post_status: the parent payment's WP post_status ('publish' = paid).
        // subscription status: give_subscriptions.status (active, cancelled, etc.)
        // ------------------------------------------------------------------

        $parentPostStatus  = sanitize_text_field($giveSub['post_status'] ?? 'publish');
        $paymentStatus     = StatusMapper::mapDonationStatus($parentPostStatus);

        $giveGateway       = sanitize_text_field($giveSub['_give_payment_gateway'] ?? 'manual');
        $paymentMethod     = GatewayMapper::mapGateway($giveGateway);

        $currency             = strtoupper(sanitize_text_field($giveSub['_give_payment_currency'] ?? 'USD'));
        $giveInitialCents     = AmountConverter::toMinorUnits((string) ($giveSub['initial_amount']   ?? '0'), $currency);
        $recurringAmountCents = AmountConverter::toMinorUnits((string) ($giveSub['recurring_amount'] ?? '0'), $currency);
        $initialAmountCents   = max(0, $giveInitialCents - $recurringAmountCents);
        $paymentMode = sanitize_text_field($giveSub['payment_mode'] ?? 'live');

        // ------------------------------------------------------------------
        // 4. Build donor identity fields.
        // ------------------------------------------------------------------

        $wpUserId    = absint($giveSub['wp_user_id'] ?? 0);
        $userIdForDB = ($wpUserId > 0) ? $wpUserId : null;

        $firstName    = sanitize_text_field($giveSub['_give_donor_billing_first_name'] ?? '');
        $lastName     = sanitize_text_field($giveSub['_give_donor_billing_last_name']  ?? '');
        $customerName = trim($firstName . ' ' . $lastName);

        $customerEmail = sanitize_email($giveSub['_give_payment_donor_email'] ?? '');

        $purchaseKey    = sanitize_text_field($giveSub['_give_payment_purchase_key'] ?? '');
        $submissionHash = !empty($purchaseKey) ? $purchaseKey : wp_generate_uuid4();

        // ------------------------------------------------------------------
        // 5. Resolve billing interval via IntervalMapper.
        //
        // IntervalMapper returns a result array with:
        //   - billing_interval (string)
        //   - is_lossy         (bool)
        //   - note             (string)
        //
        // Lossy cases (quarter period, frequency > 1) are logged in wpf_meta
        // as 'subscription_interval_note' for admin review.
        // ------------------------------------------------------------------

        $period    = sanitize_text_field($giveSub['period'] ?? 'month');
        $frequency = absint($giveSub['frequency'] ?? 1);

        $intervalResult  = IntervalMapper::mapInterval($period, $frequency);
        $billingInterval = $intervalResult['billing_interval'];
        $intervalCount   = absint($intervalResult['interval_count'] ?? 1);

        // ------------------------------------------------------------------
        // 6. Timestamps.
        //
        // Use give_subscriptions.created for the subscription start date.
        // The parent payment's post_date is used for the submission timestamps.
        // Fall back to current time when either is empty.
        // ------------------------------------------------------------------

        $subCreatedAt = !empty($giveSub['created'])   ? $giveSub['created']   : current_time('mysql');
        $postDate     = !empty($giveSub['post_date'])  ? $giveSub['post_date'] : $subCreatedAt;

        // Expiration — can be a zero-padded MySQL timestamp meaning "no expiry".
        // NULL is cleaner for Paymattic display; keep it when it's a real date.
        $expirationAt = (!empty($giveSub['expiration']) && $giveSub['expiration'] !== '0000-00-00 00:00:00')
            ? $giveSub['expiration']
            : null;

        // ------------------------------------------------------------------
        // 7. Resolve the form item name for wpf_order_items.
        //
        // get_the_title() returns an empty string for missing/trashed posts.
        // We fall back to 'Donation' as the safe default.
        // ------------------------------------------------------------------

        $itemName = 'Donation';
        if ($paymatticFormId > 0) {
            $formTitle = get_the_title($paymatticFormId);
            if (!empty($formTitle)) {
                $itemName = sanitize_text_field($formTitle);
            }
        }

        // ------------------------------------------------------------------
        // 8. Dry-run exit — all logic has run; no writes below this point.
        // ------------------------------------------------------------------

        if ($dryRun) {
            return 0;
        }

        // ------------------------------------------------------------------
        // 9. Insert into wpf_submissions (parent submission for initial payment).
        //
        // We do NOT fire wppayform/after_submission_data_insert or any other
        // Paymattic hook — this is a silent historical data import.
        // ------------------------------------------------------------------

        try {
            $submissionInserted = $wpdb->insert(
                $wpdb->prefix . 'wpf_submissions',
                [
                    'form_id'             => $paymatticFormId,
                    'user_id'             => $userIdForDB,
                    'customer_name'       => $customerName,
                    'customer_email'      => $customerEmail,
                    'payment_method'      => $paymentMethod,
                    'payment_status'      => $paymentStatus,
                    'payment_total'       => $giveInitialCents,
                    'currency'            => $currency,
                    'payment_mode'        => $paymentMode,
                    'ip_address'          => substr(sanitize_text_field($giveSub['_give_payment_donor_ip'] ?? ''), 0, 45),
                    'submission_hash'     => $submissionHash,
                    'form_data_raw'       => '',
                    'form_data_formatted' => maybe_serialize([
                        'customer_name'  => $customerName,
                        'customer_email' => $customerEmail,
                    ]),
                    'created_at'          => $postDate,
                    'updated_at'          => $postDate,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            MigrationState::addError(
                sprintf(
                    'wpf_submissions insert failed for give_subscriptions.id=%d: %s',
                    $giveSubId,
                    substr(sanitize_text_field($e->getMessage()), 0, 200)
                )
            );
            return 0;
        }

        if (!$submissionInserted) {
            return 0;
        }

        $submissionId = absint($wpdb->insert_id);

        // charge_id is needed both for step 9b (meta) and step 10 (transaction).
        $chargeId = sanitize_text_field($giveSub['transaction_id'] ?? '');

        // ------------------------------------------------------------------
        // 9b. Insert submission-level meta for the subscription's parent submission.
        //
        // PaymatticSubmissionWriter inserts these meta rows for one-time donations.
        // The subscription writer also needs them so that:
        //   • Rollback: the rollback writer finds migrated submissions by querying
        //     wpf_meta for give_source_donation_id in meta_group = 'wpf_submissions'.
        //   • Idempotency: PaymatticSubmissionWriter checks this meta before creating
        //     a submission — without it, a re-run of the donation phase could create
        //     a duplicate submission for this subscription's parent payment.
        //   • Audit: give_original_gateway preserves the raw GiveWP gateway slug.
        //   • Entry view: give_transaction_url powers the "View on ..." link.
        // ------------------------------------------------------------------

        $metaNow = current_time('mysql');

        $insertSubmissionMeta = function (string $key, string $value) use (
            $wpdb, $submissionId, $paymatticFormId, $metaNow
        ): void {
            $wpdb->insert(
                $wpdb->prefix . 'wpf_meta',
                [
                    'meta_group' => 'wpf_submissions',
                    'option_id'  => $submissionId,
                    'form_id'    => $paymatticFormId,
                    'meta_key'   => $key,
                    'meta_value' => $value,
                    'created_at' => $metaNow,
                    'updated_at' => $metaNow,
                ],
                ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
            );
        };

        // Row 1 — link to the GiveWP parent donation post (for cross-reference + idempotency).
        $parentPaymentId = absint($giveSub['parent_payment_id'] ?? 0);
        if ($parentPaymentId > 0) {
            $insertSubmissionMeta('give_source_donation_id', (string) $parentPaymentId);
        }

        // Row 2 — raw GiveWP gateway slug (audit trail).
        $insertSubmissionMeta('give_original_gateway', $giveGateway);

        // Row 3 — pre-computed vendor dashboard URL (powers "View on ..." in Entry.vue).
        $txUrl = GatewayMapper::getPaymentUrl($paymentMethod, $chargeId, $paymentMode);
        if ($txUrl !== '') {
            $insertSubmissionMeta('give_transaction_url', $txUrl);
        }

        // ------------------------------------------------------------------
        // 10. Insert into wpf_order_transactions (initial / signup transaction).
        //
        // transaction_type = 'one_time' for the initial charge even though this
        // is a subscription — Paymattic models the signup payment as one_time
        // and subsequent renewals as 'subscription' type transactions.
        // charge_id = give_subscriptions.transaction_id (the gateway reference
        // for the first charge; may be empty for offline/manual subscriptions).
        // ($chargeId already defined in step 9b.)
        // ------------------------------------------------------------------

        try {
            $wpdb->insert(
                $wpdb->prefix . 'wpf_order_transactions',
                [
                    'form_id'          => $paymatticFormId,
                    'user_id'          => $userIdForDB,
                    'submission_id'    => $submissionId,
                    'subscription_id'  => null,
                    'transaction_type' => 'one_time',
                    'payment_method'   => $paymentMethod,
                    'card_last_4'      => null,
                    'card_brand'       => '',
                    'charge_id'        => $chargeId,
                    'payment_total'    => $giveInitialCents,
                    'status'           => $paymentStatus,
                    'currency'         => $currency,
                    'payment_mode'     => $paymentMode,
                    'payment_note'     => '',
                    'created_at'       => $postDate,
                    'updated_at'       => $postDate,
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            MigrationState::addError(
                sprintf(
                    'wpf_order_transactions insert failed for give_subscriptions.id=%d: %s',
                    $giveSubId,
                    substr(sanitize_text_field($e->getMessage()), 0, 200)
                )
            );
            // Submission was already inserted — continue so we record the subscription.
        }

        // ------------------------------------------------------------------
        // 11. Insert into wpf_order_items (signup fee line item).
        //
        // type = 'signup_fee' distinguishes the initial payment of a subscription
        // from a standalone one-time donation ('single').
        // parent_holder = 'donation_item' ensures Paymattic's goal/total queries
        // find this item correctly.
        // billing_interval is stored so the item is linked to the subscription cadence.
        // ------------------------------------------------------------------

        try {
            $wpdb->insert(
                $wpdb->prefix . 'wpf_order_items',
                [
                    'form_id'          => $paymatticFormId,
                    'submission_id'    => $submissionId,
                    'type'             => 'signup_fee',
                    'parent_holder'    => 'donation_item',
                    'billing_interval' => $billingInterval,
                    'item_name'        => $itemName,
                    'quantity'         => 1,
                    'item_price'       => $giveInitialCents,
                    'line_total'       => $giveInitialCents,
                    'created_at'       => $postDate,
                    'updated_at'       => $postDate,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']
            );
        } catch (\Exception $e) {
            MigrationState::addError(
                sprintf(
                    'wpf_order_items insert failed for give_subscriptions.id=%d: %s',
                    $giveSubId,
                    substr(sanitize_text_field($e->getMessage()), 0, 200)
                )
            );
        }

        // ------------------------------------------------------------------
        // 12. Insert into wpf_subscriptions.
        //
        // CRITICAL: vendor_subscriptipn_id has a typo in the live schema.
        // We write exactly "vendor_subscriptipn_id" (subscriptipn) to match
        // the column created by database/Migrations/Subscriptions.php.
        //
        // vendor_customer_id: the Stripe customer ID (cus_xxx) read from
        // give_donationmeta._give_stripe_customer_id.
        //
        // original_plan: JSON-encodes the GiveWP period + frequency so that
        // admins can reconstruct the true billing schedule even when is_lossy = true.
        //
        // bill_count: initialised to 0 here; updated after counting renewals below.
        // ------------------------------------------------------------------

        $subscriptionStatus = StatusMapper::mapSubscriptionStatus(
            sanitize_text_field($giveSub['status'] ?? 'pending')
        );

        $originalPlan = json_encode([
            'period'           => $period,
            'frequency'        => $frequency,
            'billing_interval' => $billingInterval,
            'interval_count'   => $intervalCount,
        ]);


        try {
            $subscriptionInserted = $wpdb->insert(
                $wpdb->prefix . 'wpf_subscriptions',
                [
                    'form_id'                  => $paymatticFormId,
                    'submission_id'            => $submissionId,
                    'item_name'                => $itemName,
                    'plan_name'                => $itemName,
                    'billing_interval'         => $billingInterval,
                    'initial_amount'           => $initialAmountCents,
                    'recurring_amount'         => $recurringAmountCents,
                    'bill_times'               => absint($giveSub['bill_times'] ?? 0),
                    'bill_count'               => 0,
                    // Gateway customer ID resolved per-gateway (Stripe: cus_xxx, Mollie: cst_xxx, etc.)
                    'vendor_customer_id'       => sanitize_text_field(self::resolveVendorCustomerId($giveSub, $paymentMethod)),
                    // ⚠️ Known schema typo — column is "vendor_subscriptipn_id" not "vendor_subscription_id"
                    'vendor_subscriptipn_id'   => sanitize_text_field($giveSub['profile_id'] ?? ''),
                    'status'                   => $subscriptionStatus,
                    'note'                     => sanitize_text_field($giveSub['notes'] ?? ''),
                    'original_plan'            => $originalPlan,
                    'created_at'               => $subCreatedAt,
                    'expiration_at'            => $expirationAt,
                    'updated_at'               => $subCreatedAt,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            MigrationState::addError(
                sprintf(
                    'wpf_subscriptions insert failed for give_subscriptions.id=%d: %s',
                    $giveSubId,
                    substr(sanitize_text_field($e->getMessage()), 0, 200)
                )
            );
            $wpdb->delete($wpdb->prefix . 'wpf_order_transactions', ['submission_id' => $submissionId],                              ['%d']);
            $wpdb->delete($wpdb->prefix . 'wpf_order_items',        ['submission_id' => $submissionId],                              ['%d']);
            $wpdb->delete($wpdb->prefix . 'wpf_meta',               ['meta_group' => 'wpf_submissions', 'option_id' => $submissionId], ['%s', '%d']);
            $wpdb->delete($wpdb->prefix . 'wpf_submissions',        ['id'            => $submissionId],                              ['%d']);
            return 0;
        }

        if (!$subscriptionInserted) {
            $wpdb->delete($wpdb->prefix . 'wpf_order_transactions', ['submission_id' => $submissionId],                              ['%d']);
            $wpdb->delete($wpdb->prefix . 'wpf_order_items',        ['submission_id' => $submissionId],                              ['%d']);
            $wpdb->delete($wpdb->prefix . 'wpf_meta',               ['meta_group' => 'wpf_submissions', 'option_id' => $submissionId], ['%s', '%d']);
            $wpdb->delete($wpdb->prefix . 'wpf_submissions',        ['id'            => $submissionId],                              ['%d']);
            return 0;
        }

        $wpfSubscriptionId = absint($wpdb->insert_id);

        // ------------------------------------------------------------------
        // 12b. Back-fill subscription_id on the initial transaction.
        //
        // The transaction was inserted (step 10) before the subscription row
        // existed, so subscription_id was NULL at that point. Now that we have
        // $wpfSubscriptionId we update it so the entry detail view can link
        // the initial payment to this subscription correctly.
        // ------------------------------------------------------------------

        $wpdb->update(
            $wpdb->prefix . 'wpf_order_transactions',
            ['subscription_id' => $wpfSubscriptionId],
            ['submission_id' => $submissionId, 'transaction_type' => 'one_time'],
            ['%d'],
            ['%d', '%s']
        );

        // ------------------------------------------------------------------
        // 13. Insert wpf_meta rows.
        //
        // Row 1 — CRITICAL: give_subscription_id with meta_group = 'wpf_subscriptions'
        //   enables the idempotency check (step 1).
        // Row 2 — give_source_donation_id links the subscription to the original
        //   parent payment post in GiveWP for cross-reference.
        // Row 3 (conditional) — subscription_interval_note written when the
        //   IntervalMapper reported a lossy mapping (quarterly or frequency > 1).
        //
        // All meta rows use meta_group = 'wpf_subscriptions' and option_id = $wpfSubscriptionId.
        // ------------------------------------------------------------------

        $now = current_time('mysql');

        $insertMeta = function (string $key, string $value) use ($wpdb, $wpfSubscriptionId, $paymatticFormId, $now): void {
            $wpdb->insert(
                $wpdb->prefix . 'wpf_meta',
                [
                    'meta_group' => 'wpf_subscriptions',
                    'option_id'  => $wpfSubscriptionId,
                    'form_id'    => $paymatticFormId,
                    'meta_key'   => $key,
                    'meta_value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
            );
        };

        // Row 1 — idempotency key.
        $insertMeta('give_subscription_id', (string) $giveSubId);

        // Row 2 — link to original GiveWP parent payment.
        $parentPaymentId = absint($giveSub['parent_payment_id'] ?? 0);
        if ($parentPaymentId > 0) {
            $insertMeta('give_source_donation_id', (string) $parentPaymentId);
        }

        if ($intervalCount > 1) {
            $insertMeta('billing_interval_count', (string) $intervalCount);
        }

        if ($intervalResult['is_lossy'] && !empty($intervalResult['note'])) {
            $insertMeta(
                'subscription_interval_note',
                sanitize_text_field($intervalResult['note'])
            );
        }

        // ------------------------------------------------------------------
        // 14. Count renewal donations and update bill_count.
        //
        // GiveWP stores renewal donations as posts where subscription_id = $giveSubId
        // in give_donationmeta. We count these to set the bill_count on the
        // wpf_subscriptions row, giving admins an accurate payment history count.
        //
        // We query give_donationmeta rather than wp_posts to avoid a JOIN;
        // the donationmeta table is indexed on meta_key + meta_value in most
        // GiveWP installations.
        // ------------------------------------------------------------------

        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix (trusted); all values bound via $wpdb->prepare().
        $donationMetaTable = $wpdb->prefix . 'give_donationmeta';

        // INNER JOIN wp_posts to exclude trashed or draft renewals — only
        // published (paid) renewals should count toward the bill total.
        $renewalCount = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$donationMetaTable} AS dm
                     INNER JOIN {$wpdb->posts} AS p ON p.ID = dm.donation_id
                     WHERE dm.meta_key   = %s
                       AND dm.meta_value = %s
                       AND p.post_status = 'publish'",
                    'subscription_id',
                    (string) $giveSubId
                )
            )
        );
        // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

        if ($renewalCount > 0) {
            // TRACE-DB-001: Sum lifetime paid revenue for this subscription
            // so wpf_subscriptions.payment_total reflects real value rather
            // than schema default 0. Includes both the initial (one_time)
            // signup charge — back-filled with subscription_id in step 12b —
            // and any migrated renewal rows.
            $paymentTotal = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COALESCE(SUM(payment_total), 0)
                         FROM {$wpdb->prefix}wpf_order_transactions
                         WHERE subscription_id = %d
                           AND status = %s",
                        $wpfSubscriptionId,
                        'paid'
                    )
                )
            );

            try {
                $wpdb->update(
                    $wpdb->prefix . 'wpf_subscriptions',
                    [
                        'bill_count'    => $renewalCount,
                        'payment_total' => $paymentTotal,
                    ],
                    ['id' => $wpfSubscriptionId],
                    ['%d', '%d'],
                    ['%d']
                );
            } catch (\Exception $e) {
                MigrationState::addError(
                    sprintf(
                        'bill_count update failed for wpf_subscriptions.id=%d: %s',
                        $wpfSubscriptionId,
                        substr(sanitize_text_field($e->getMessage()), 0, 200)
                    )
                );
            }
        }

        return $wpfSubscriptionId;
    }

    /**
     * Resolve the gateway vendor customer ID from GiveWP donation meta.
     *
     * GiveWP add-ons store the gateway customer ID under gateway-specific meta
     * keys. Returns the first non-empty value found for the detected gateway,
     * or empty string when no customer ID is available (e.g. PayPal, offline).
     *
     * @param array  $giveSub       Subscription data from GiveSubscriptionReader.
     * @param string $paymentMethod Paymattic payment_method slug.
     *
     * @return string Gateway customer ID, or empty string.
     */
    private static function resolveVendorCustomerId(array $giveSub, string $paymentMethod): string
    {
        $metaKeyMap = [
            'stripe'   => '_give_stripe_customer_id',
            'mollie'   => '_give_mollie_customer_id',
            'razorpay' => '_give_razorpay_customer_id',
            'paystack' => '_give_paystack_customer_id',
            'square'   => '_give_square_customer_id',
        ];

        if (isset($metaKeyMap[$paymentMethod])) {
            return (string) ($giveSub[$metaKeyMap[$paymentMethod]] ?? '');
        }

        return '';
    }

    /**
     * Write renewal transaction records for a migrated subscription.
     *
     * Each GiveWP renewal is stored as a give_payment post (post_parent > 0)
     * with _give_is_donation_recurring = '1' and subscription_id = $giveSubId
     * in give_donationmeta.
     *
     * Each renewal becomes a wpf_order_transactions row with:
     *   transaction_type = 'subscription'
     *   subscription_id  = $wpfSubscriptionId
     *
     * This method is intentionally separate from writeSubscription() so the
     * caller can invoke it independently for retry scenarios.
     *
     * @param int  $giveSubId          GiveWP give_subscriptions.id.
     * @param int  $wpfSubmissionId    Paymattic wpf_submissions.id (the parent submission).
     * @param int  $wpfSubscriptionId  Paymattic wpf_subscriptions.id.
     * @param bool $dryRun             When true, counts renewals but writes nothing.
     *
     * @return int Number of renewal transactions written (or would be written in dry-run).
     */
    public static function writeRenewalTransactions(
        int $giveSubId,
        int $wpfSubmissionId,
        int $wpfSubscriptionId,
        bool $dryRun = false
    ): int {
        global $wpdb;

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->prefix is a trusted, server-controlled value.
        $donationMetaTable = $wpdb->prefix . 'give_donationmeta';

        // ------------------------------------------------------------------
        // 1. Find all renewal donation IDs for this subscription.
        //
        // GiveWP tags renewal donations with subscription_id = $giveSubId.
        // We collect the donation_ids and then load each post + meta for
        // the transaction details.
        // ------------------------------------------------------------------

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $donationMetaTable composed from $wpdb->prefix only.
        $renewalDonationIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT donation_id
                 FROM {$donationMetaTable}
                 WHERE meta_key   = %s
                   AND meta_value = %s",
                'subscription_id',
                (string) $giveSubId
            )
        );

        if (empty($renewalDonationIds)) {
            return 0;
        }

        // ------------------------------------------------------------------
        // 2. Retrieve parent submission context for shared fields.
        // ------------------------------------------------------------------

        $parentSubmission = null;

        if ($wpfSubmissionId > 0) {
            $parentSubmission = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT form_id, user_id, payment_method, currency, payment_mode
                     FROM {$wpdb->prefix}wpf_submissions
                     WHERE id = %d
                     LIMIT 1",
                    $wpfSubmissionId
                ),
                ARRAY_A
            );
        }

        $formId        = absint($parentSubmission['form_id']        ?? 0);
        $userId        = isset($parentSubmission['user_id']) && $parentSubmission['user_id'] !== null
                            ? absint($parentSubmission['user_id'])
                            : null;
        $paymentMethod = sanitize_text_field($parentSubmission['payment_method'] ?? 'offline');
        $currency      = sanitize_text_field($parentSubmission['currency']       ?? 'USD');
        $paymentMode   = sanitize_text_field($parentSubmission['payment_mode']   ?? 'live');

        $written = 0;

        foreach ($renewalDonationIds as $renewalDonationId) {
            $renewalDonationId = absint($renewalDonationId);
            if ($renewalDonationId < 1) {
                continue;
            }

            // ------------------------------------------------------------------
            // 3. Check idempotency: skip if this renewal transaction already exists.
            //
            // We store the renewal donation ID as a meta row on wpf_meta with
            // meta_group = 'wpf_renewal_txn' and option_id = the transaction ID.
            // We look up by charge_id in wpf_order_transactions as the primary
            // idempotency key, falling back to a meta check.
            // ------------------------------------------------------------------

            // Load the renewal post to get the transaction ID (gateway charge reference).
            $renewalPost = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT ID, post_date, post_status FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                    $renewalDonationId
                ),
                ARRAY_A
            );

            if (empty($renewalPost)) {
                continue;
            }

            // Skip the initial/parent payment of the subscription.
            //
            // GiveWP tags the initial charge with both `subscription_id` and
            // `_give_subscription_payment = '1'`. The initial payment is already
            // written as a one_time transaction in writeSubscription() step 10.
            // Including it here would create a duplicate 'subscription' type row
            // for the same charge.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $donationMetaTable composed from $wpdb->prefix only.
            $isInitialPayment = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value
                     FROM {$donationMetaTable}
                     WHERE donation_id = %d
                       AND meta_key    = %s
                     LIMIT 1",
                    $renewalDonationId,
                    '_give_subscription_payment'
                )
            );

            if ($isInitialPayment === '1') {
                continue;
            }

            // Get the gateway transaction ID for this renewal from donationmeta.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $donationMetaTable composed from $wpdb->prefix only.
            $renewalTransactionId = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value
                     FROM {$donationMetaTable}
                     WHERE donation_id = %d
                       AND meta_key    = %s
                     LIMIT 1",
                    $renewalDonationId,
                    '_give_payment_transaction_id'
                )
            );

            // Get the renewal amount.
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $donationMetaTable composed from $wpdb->prefix only.
            $renewalAmountRaw = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value
                     FROM {$donationMetaTable}
                     WHERE donation_id = %d
                       AND meta_key    = %s
                     LIMIT 1",
                    $renewalDonationId,
                    '_give_payment_total'
                )
            );

            $renewalAmountCents = AmountConverter::toMinorUnits($renewalAmountRaw, $currency);

            $renewalStatus = StatusMapper::mapDonationStatus(
                sanitize_text_field($renewalPost['post_status'] ?? 'publish')
            );

            $renewalDate = !empty($renewalPost['post_date']) ? $renewalPost['post_date'] : current_time('mysql');

            // Idempotency: skip if a transaction row with this charge_id already exists
            // for this subscription (avoid duplicating renewals on re-run).
            if (!empty($renewalTransactionId)) {
                $txExists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id
                         FROM {$wpdb->prefix}wpf_order_transactions
                         WHERE subscription_id = %d
                           AND charge_id       = %s
                         LIMIT 1",
                        $wpfSubscriptionId,
                        $renewalTransactionId
                    )
                );

                if ($txExists) {
                    $written++;
                    continue;
                }
            } else {
                // Offline/manual renewals have no charge_id — check via wpf_meta sentinel.
                $renewalMetaExists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}wpf_meta
                         WHERE meta_key   = %s
                           AND meta_value = %s
                           AND meta_group = %s
                         LIMIT 1",
                        'give_renewal_migrated',
                        (string) $renewalDonationId,
                        'wpf_order_transactions'
                    )
                );

                if ($renewalMetaExists) {
                    $written++;
                    continue;
                }
            }

            if ($dryRun) {
                $written++;
                continue;
            }

            // ------------------------------------------------------------------
            // 4. Insert the renewal transaction.
            // ------------------------------------------------------------------

            try {
                $inserted = $wpdb->insert(
                    $wpdb->prefix . 'wpf_order_transactions',
                    [
                        'form_id'          => $formId,
                        'user_id'          => $userId,
                        'submission_id'    => $wpfSubmissionId,
                        'subscription_id'  => $wpfSubscriptionId,
                        'transaction_type' => 'subscription',
                        'payment_method'   => $paymentMethod,
                        'card_last_4'      => null,
                        'card_brand'       => '',
                        'charge_id'        => sanitize_text_field($renewalTransactionId),
                        'payment_total'    => $renewalAmountCents,
                        'status'           => $renewalStatus,
                        'currency'         => $currency,
                        'payment_mode'     => $paymentMode,
                        'payment_note'     => '',
                        'created_at'       => $renewalDate,
                        'updated_at'       => $renewalDate,
                    ],
                    ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
                );

                if ($inserted) {
                    $wpfTransactionId = absint($wpdb->insert_id);
                    $written++;

                    // Write sentinel for offline/manual renewals so re-runs skip them.
                    if (empty($renewalTransactionId) && $wpfTransactionId > 0) {
                        $metaNow = current_time('mysql');
                        $wpdb->insert(
                            $wpdb->prefix . 'wpf_meta',
                            [
                                'meta_group' => 'wpf_order_transactions',
                                'option_id'  => $wpfTransactionId,
                                'form_id'    => $formId,
                                'meta_key'   => 'give_renewal_migrated',
                                'meta_value' => (string) $renewalDonationId,
                                'created_at' => $metaNow,
                                'updated_at' => $metaNow,
                            ],
                            ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
                        );
                    }
                }
            } catch (\Exception $e) {
                MigrationState::addError(
                    sprintf(
                        'Renewal transaction insert failed for give_donation.ID=%d (sub %d): %s',
                        $renewalDonationId,
                        $giveSubId,
                        substr(sanitize_text_field($e->getMessage()), 0, 200)
                    )
                );
            }
        }

        return $written;
    }
}
