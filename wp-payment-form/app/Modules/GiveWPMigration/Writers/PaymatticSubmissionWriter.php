<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Writers;

if (!defined('ABSPATH')) {
    exit;
}

use WPPayForm\App\Modules\GiveWPMigration\Mappers\AmountConverter;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\GatewayMapper;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\StatusMapper;

/**
 * PaymatticSubmissionWriter — inserts a migrated GiveWP donation into Paymattic's tables.
 *
 * Writes directly to the database using $wpdb->insert() / $wpdb->prepare() to
 * avoid triggering Paymattic hooks (form_payment_success, after_submission_data_insert,
 * GlobalNotificationManager, etc.) that would send emails and fire integrations for
 * historical donations.
 *
 * Tables written:
 *   {prefix}wpf_submissions         — one row per donation
 *   {prefix}wpf_order_transactions  — one row per donation (one-time type)
 *   {prefix}wpf_order_items         — one row per donation (the donation amount)
 *   {prefix}wpf_meta                — multiple rows: source ID + address + optional fields
 *
 * Idempotency guarantee:
 *   Before inserting, the writer checks wpf_meta for an existing row with
 *   meta_key = 'give_source_donation_id' and meta_value = $donationId.
 *   If found, the existing submission ID is returned and no rows are written.
 *   This makes the migrator safe to re-run after a partial failure.
 *
 * Amount handling:
 *   _give_payment_total is stored as a decimal string (e.g. "50.00") and already
 *   INCLUDES any fee recovery amount. We convert to minor units (cents) and store
 *   the full charged amount. When _give_fee_status = 'accepted', we split the
 *   single order item into a donation row + a fee_recovery_item row and write
 *   Paymattic's native _wpf_fee_* meta keys so the entry view shows the breakdown.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Writers
 * @since   4.6.21
 */
class PaymatticSubmissionWriter
{
    /**
     * Write a single GiveWP donation as a Paymattic submission.
     *
     * @param array $donation  Donation data from GiveDonationReader::getBatch().
     *                         Expected keys: donation_id, post_status, post_date,
     *                         post_modified, wp_user_id, plus all give_donationmeta
     *                         entries as flat keys.
     * @param array $formMap   GiveWP form_id → Paymattic form_id lookup table.
     *                         From MigrationState::get()['form_map'].
     * @param bool  $dryRun    When true, all logic runs but no writes are performed.
     *
     * @return int Paymattic wpf_submissions.id, or 0 on dry run / failure.
     */
    public static function writeSubmission(array $donation, array $formMap, bool $dryRun = false): int
    {
        global $wpdb;

        $donationId = absint($donation['donation_id']);

        // ------------------------------------------------------------------
        // 1. Idempotency check — fast path before lock.
        //
        // For donations already migrated in a prior run this avoids lock
        // contention. The lock-protected re-check below covers the TOCTOU gap.
        // ------------------------------------------------------------------

        $existingSubmissionId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_id
                 FROM {$wpdb->prefix}wpf_meta
                 WHERE meta_key   = %s
                   AND meta_value = %s
                   AND meta_group = %s
                 LIMIT 1",
                'give_source_donation_id',
                (string) $donationId,
                'wpf_submissions'
            )
        );

        if ($existingSubmissionId) {
            return absint($existingSubmissionId);
        }

        // Dry-run exits here: fast-path already reported 'skipped' for migrated
        // donations above; no lock needed since no writes will happen.
        if ($dryRun) {
            return 0;
        }

        // ------------------------------------------------------------------
        // 1b. Per-donation advisory lock (TOCTOU guard).
        //
        // Two workers that both pass the SELECT above before either inserts
        // the idempotency marker would both write full submission rows.
        // GET_LOCK() serializes them: only one proceeds at a time.
        //
        // Released explicitly on every exit path because WordPress may use
        // persistent DB connections where it would otherwise persist across
        // requests even after this function returns.
        // ------------------------------------------------------------------
        $lockName     = sprintf('wpf_give_mig_%d', $donationId);
        $lockAcquired = (bool) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 10)", $lockName));
        if (!$lockAcquired) {
            return 0;
        }

        $releaseLock = fn() => $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));

        // Re-check inside the lock: another worker may have completed migration
        // between the fast-path SELECT above and this worker's lock acquisition.
        $existingSubmissionId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_id
                 FROM {$wpdb->prefix}wpf_meta
                 WHERE meta_key   = %s
                   AND meta_value = %s
                   AND meta_group = %s
                 LIMIT 1",
                'give_source_donation_id',
                (string) $donationId,
                'wpf_submissions'
            )
        );

        if ($existingSubmissionId) {
            $releaseLock();
            return absint($existingSubmissionId);
        }

        // ------------------------------------------------------------------
        // 2. Resolve the target Paymattic form ID.
        //
        // _give_payment_form_id holds the GiveWP give_forms post ID.
        // We look it up in $formMap (populated by the form migration phase).
        // If the form was not migrated (e.g. it was in a different batch or
        // an error occurred), we use 0 as a sentinel. Submissions with form_id = 0
        // can be identified and re-associated after investigation.
        // ------------------------------------------------------------------

        $giveFormId   = absint($donation['_give_payment_form_id'] ?? 0);
        $paymatticFormId = absint($formMap[$giveFormId] ?? 0);

        // ------------------------------------------------------------------
        // 3. Map status, gateway, and convert amounts.
        //
        // Status: GiveWP uses WP post_status for donations ('publish' = paid).
        // Gateway: GiveWP slug → Paymattic slug via GatewayMapper.
        // Amount: _give_payment_total is a decimal string; convert to cents.
        // Currency: _give_payment_currency is a 3-letter ISO code (e.g. 'USD').
        // Payment mode: 'live' or 'test' — preserved for reporting.
        // ------------------------------------------------------------------

        $paymentStatus  = StatusMapper::mapDonationStatus(
            sanitize_text_field($donation['post_status'] ?? 'pending')
        );

        $rawGiveGateway = sanitize_text_field($donation['_give_payment_gateway'] ?? 'manual');
        $giveGateway    = $rawGiveGateway;
        $paymentMethod  = GatewayMapper::mapGateway($giveGateway);

        $currency       = strtoupper(sanitize_text_field($donation['_give_payment_currency'] ?? 'USD'));
        $paymentMode    = sanitize_text_field($donation['_give_payment_mode'] ?? 'live');

        $rawAmount      = (string) ($donation['_give_payment_total'] ?? '0');
        $amountCents    = AmountConverter::toMinorUnits($rawAmount, $currency);

        // ------------------------------------------------------------------
        // 3b. Fee recovery resolution.
        //
        // GiveWP bakes the fee into _give_payment_total, so $amountCents is
        // always the full charged amount — we never subtract the fee.
        //
        // When the donor accepted the fee (_give_fee_status = 'accepted') we:
        //   a) use _give_fee_donation_amount for the donation order item so
        //      the two line items sum back to payment_total, and
        //   b) insert a separate fee_recovery_item order item + Paymattic's
        //      native _wpf_fee_* meta keys so the entry view shows the split.
        //
        // If _give_fee_donation_amount is absent (older GiveWP data), we fall
        // back to amountCents − feeCents.
        // ------------------------------------------------------------------

        $rawFeeAmount         = trim((string) ($donation['_give_fee_amount']          ?? ''));
        $rawFeeDonationAmount = trim((string) ($donation['_give_fee_donation_amount'] ?? ''));
        $feeStatus            = sanitize_text_field((string) ($donation['_give_fee_status'] ?? ''));

        $hasFeeRecovery = ($feeStatus === 'accepted'
            && $rawFeeAmount !== ''
            && (float) $rawFeeAmount > 0.0);

        $feeCents         = 0;
        $feeDonationCents = 0;
        if ($hasFeeRecovery) {
            $feeCents         = AmountConverter::toMinorUnits($rawFeeAmount, $currency);
            $feeDonationCents = ($rawFeeDonationAmount !== '')
                ? AmountConverter::toMinorUnits($rawFeeDonationAmount, $currency)
                : max(0, $amountCents - $feeCents);

            // Guard 1: donation amount must be positive.
            if ($feeDonationCents === 0 && $amountCents > 0) {
                $hasFeeRecovery = false;
                $feeCents       = 0;
            }

            // Guard 2: order items must sum to payment_total.
            // _give_fee_donation_amount can disagree with payment_total - fee_amount
            // when GiveWP data is inconsistent. Recompute from total - fee so the
            // wpf_order_items rows always reconcile with wpf_submissions.payment_total.
            if ($hasFeeRecovery && ($feeCents + $feeDonationCents !== $amountCents)) {
                $feeDonationCents = max(0, $amountCents - $feeCents);
                if ($feeDonationCents === 0) {
                    $hasFeeRecovery = false;
                    $feeCents       = 0;
                }
            }
        }

        // Donation order-item amount: pre-fee when split; full total otherwise.
        $donationOrderItemCents = $hasFeeRecovery ? $feeDonationCents : $amountCents;

        // ------------------------------------------------------------------
        // 4. Build donor identity fields.
        //
        // GiveWP guest donors have wp_user_id = 0; we store NULL in user_id.
        // customer_name is built from first + last name meta fields.
        // submission_hash: use the purchase key if available for traceability,
        // otherwise generate a fresh UUID.
        // ip_address: GiveWP stores the donor IP in _give_payment_donor_ip.
        // ------------------------------------------------------------------

        $wpUserId       = absint($donation['wp_user_id'] ?? 0);
        $userIdForDB    = ($wpUserId > 0) ? $wpUserId : null;

        $firstName      = sanitize_text_field($donation['_give_donor_billing_first_name'] ?? '');
        $lastName       = sanitize_text_field($donation['_give_donor_billing_last_name'] ?? '');
        $customerName   = trim($firstName . ' ' . $lastName);

        $customerEmail  = sanitize_email($donation['_give_payment_donor_email'] ?? '');

        $purchaseKey    = sanitize_text_field($donation['_give_payment_purchase_key'] ?? '');
        $submissionHash = !empty($purchaseKey) ? $purchaseKey : wp_generate_uuid4();

        $ipAddress      = sanitize_text_field($donation['_give_payment_donor_ip'] ?? '');
        // Enforce max length to match wpf_submissions.ip_address VARCHAR(45).
        $ipAddress      = substr($ipAddress, 0, 45);

        // Timestamps — fall back to current time if empty.
        $createdAt      = !empty($donation['post_date'])     ? $donation['post_date']     : current_time('mysql');
        $updatedAt      = !empty($donation['post_modified']) ? $donation['post_modified'] : current_time('mysql');

        // Wrap all table writes in a transaction so the idempotency marker
        // (give_source_donation_id in wpf_meta) is always atomic with the
        // submission row. Without this, a process failure between the submissions
        // insert and the meta write leaves orphaned rows that bypass the guard
        // on retry, producing duplicate submissions.
        $wpdb->query('START TRANSACTION');

        // ------------------------------------------------------------------
        // 6. Insert into wpf_submissions.
        //
        // We do NOT call do_action('wppayform/after_submission_data_insert', ...)
        // because that hook fires integrations (email, CRM, Slack) and would
        // blast notifications for every historical donation.
        //
        // form_data_raw is left empty intentionally — GiveWP's raw form data is
        // not in a format Paymattic can display. form_data_formatted is an empty
        // serialized array; Phase 5 (FFM migration) will populate it.
        // ------------------------------------------------------------------

        $submissionInserted = $wpdb->insert(
            $wpdb->prefix . 'wpf_submissions',
            [
                'form_id'             => $paymatticFormId,
                'user_id'             => $userIdForDB,
                'customer_name'       => $customerName,
                'customer_email'      => $customerEmail,
                'payment_method'      => $paymentMethod,
                'payment_status'      => $paymentStatus,
                'payment_total'       => $amountCents,
                'currency'            => $currency,
                'payment_mode'        => $paymentMode,
                'ip_address'          => $ipAddress,
                'submission_hash'     => $submissionHash,
                'form_data_raw'       => '',
                'form_data_formatted' => maybe_serialize([
                    'customer_name'  => $customerName,
                    'customer_email' => $customerEmail,
                ]),
                'status'              => 'new',
                'created_at'          => $createdAt,
                'updated_at'          => $updatedAt,
            ],
            [
                '%d',  // form_id
                '%d',  // user_id  (null is handled by $wpdb — passes NULL when value is null)
                '%s',  // customer_name
                '%s',  // customer_email
                '%s',  // payment_method
                '%s',  // payment_status
                '%d',  // payment_total
                '%s',  // currency
                '%s',  // payment_mode
                '%s',  // ip_address
                '%s',  // submission_hash
                '%s',  // form_data_raw
                '%s',  // form_data_formatted
                '%s',  // status
                '%s',  // created_at
                '%s',  // updated_at
            ]
        );

        if (!$submissionInserted) {
            $wpdb->query('ROLLBACK');
            $releaseLock();
            return 0;
        }

        $submissionId = absint($wpdb->insert_id);

        // ------------------------------------------------------------------
        // 7. Insert into wpf_order_transactions.
        //
        // charge_id: the gateway transaction reference (_give_payment_transaction_id).
        //   May be empty for offline/manual payments.
        // transaction_type: always 'one_time' — recurring handled in Phase 4.
        // card_last_4: not available in GiveWP — stored as NULL.
        //   (column is card_last_4 per TransactionsTable migration, NOT card_last_four)
        // ------------------------------------------------------------------

        $transactionId  = sanitize_text_field($donation['_give_payment_transaction_id'] ?? '');

        $transactionInserted = $wpdb->insert(
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
                'charge_id'        => $transactionId,
                'payment_total'    => $amountCents,
                'status'           => $paymentStatus,
                'currency'         => $currency,
                'payment_mode'     => $paymentMode,
                'payment_note'     => '',
                'created_at'       => $createdAt,
                'updated_at'       => $updatedAt,
            ],
            [
                '%d',   // form_id
                '%d',   // user_id
                '%d',   // submission_id
                '%d',   // subscription_id (null)
                '%s',   // transaction_type
                '%s',   // payment_method
                '%d',   // card_last_4 (null)
                '%s',   // card_brand
                '%s',   // charge_id
                '%d',   // payment_total
                '%s',   // status
                '%s',   // currency
                '%s',   // payment_mode
                '%s',   // payment_note
                '%s',   // created_at
                '%s',   // updated_at
            ]
        );

        if (!$transactionInserted) {
            $wpdb->query('ROLLBACK');
            $releaseLock();
            return 0;
        }

        // ------------------------------------------------------------------
        // 8. Insert into wpf_order_items.
        //
        // item_name: GiveWP stores the form title at donation time in
        //   _give_payment_form_title — this is more accurate than the current
        //   post title because it captures the title at the moment of donation.
        // parent_holder: must begin with 'donation_item' so that Paymattic's
        //   Submission::getDonationGoalAndTotal() finds the item correctly.
        // type: 'single' — the standard type for one-time payment items.
        // ------------------------------------------------------------------

        $itemName = sanitize_text_field($donation['_give_payment_form_title'] ?? 'Donation');

        $donationItemInserted = $wpdb->insert(
            $wpdb->prefix . 'wpf_order_items',
            [
                'form_id'       => $paymatticFormId,
                'submission_id' => $submissionId,
                'type'          => 'single',
                'parent_holder' => 'donation_item',
                'item_name'     => $itemName,
                'quantity'      => 1,
                'item_price'    => $donationOrderItemCents,
                'line_total'    => $donationOrderItemCents,
                'created_at'    => $createdAt,
                'updated_at'    => $updatedAt,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']
        );

        if (!$donationItemInserted) {
            $wpdb->query('ROLLBACK');
            $releaseLock();
            return 0;
        }

        // Fee recovery order item — only when donor opted in and fee > 0.
        // type = 'single' with parent_holder = 'fee_recovery_item' is the exact
        // discriminator Paymattic uses to identify fee rows in order-item queries.
        if ($hasFeeRecovery && $feeCents > 0) {
            $feeItemInserted = $wpdb->insert(
                $wpdb->prefix . 'wpf_order_items',
                [
                    'form_id'       => $paymatticFormId,
                    'submission_id' => $submissionId,
                    'type'          => 'single',
                    'parent_holder' => 'fee_recovery_item',
                    'item_name'     => 'Processing Fee',
                    'quantity'      => 1,
                    'item_price'    => $feeCents,
                    'line_total'    => $feeCents,
                    'created_at'    => $createdAt,
                    'updated_at'    => $updatedAt,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']
            );

            if (!$feeItemInserted) {
                $wpdb->query('ROLLBACK');
                $releaseLock();
                return 0;
            }
        }

        // ------------------------------------------------------------------
        // 9. Insert wpf_meta rows.
        //
        // CRITICAL first row: give_source_donation_id enables the idempotency
        // check (step 1) and the rollback writer to find all migrated submissions.
        //
        // meta_group = 'wpf_submissions' matches the Submission model's metaGroup
        // constant so that Meta::getFormMeta() can retrieve these values.
        //
        // All rows use the same shape:
        //   meta_group = 'wpf_submissions'
        //   option_id  = $submissionId
        //   form_id    = $paymatticFormId
        //   meta_key   = (key)
        //   meta_value = (value)
        //
        // Optional rows are only inserted when the source value is non-empty to
        // avoid cluttering wpf_meta with empty string rows.
        // ------------------------------------------------------------------

        $now = current_time('mysql');

        // Helper to keep insert calls DRY. Returns false on DB error.
        $insertMeta = fn(string $key, string $value): bool => (bool) $wpdb->insert(
            "{$wpdb->prefix}wpf_meta",
            [
                'meta_group' => 'wpf_submissions',
                'option_id'  => $submissionId,
                'form_id'    => $paymatticFormId,
                'meta_key'   => $key,
                'meta_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        // Row 1 — CRITICAL: source donation ID for idempotency + rollback.
        if (!$insertMeta('give_source_donation_id', (string) $donationId)) {
            $wpdb->query('ROLLBACK');
            $releaseLock();
            return 0;
        }

        // Row 2 — Billing address (stored as a serialized array, matching the
        // address_input field data format used by Paymattic's address component).
        $billingAddress = [
            'address_line_1' => sanitize_text_field($donation['_give_donor_billing_address1']  ?? ''),
            'address_line_2' => sanitize_text_field($donation['_give_donor_billing_address2']  ?? ''),
            'city'           => sanitize_text_field($donation['_give_donor_billing_city']       ?? ''),
            'state'          => sanitize_text_field($donation['_give_donor_billing_state']      ?? ''),
            'zip'            => sanitize_text_field($donation['_give_donor_billing_zip']        ?? ''),
            'country'        => sanitize_text_field($donation['_give_donor_billing_country']    ?? ''),
        ];

        // Only store billing address if at least one field is non-empty.
        $hasAddress = !empty(array_filter($billingAddress));
        if ($hasAddress) {
            $insertMeta('billing_address', maybe_serialize($billingAddress));
        }

        // ------------------------------------------------------------------
        // Remaining meta rows: optional, written only when the source value
        // is set and non-empty.
        // ------------------------------------------------------------------

        $optionalMeta = [
            // Original GiveWP gateway slug — stored for audit trail.
            'give_original_gateway' => $rawGiveGateway,
            // Donor preference for anonymous display.
            'anonymous_donation'    => (string) ($donation['_give_anonymous_donation'] ?? ''),
            // Free-text comment left by the donor at checkout.
            'donor_comment'         => sanitize_textarea_field((string) ($donation['_give_donation_comment'] ?? '')),
            // Donor phone (not always collected by GiveWP out of the box).
            'donor_phone'           => sanitize_text_field((string) ($donation['_give_payment_donor_phone'] ?? '')),
            // Company name (from FFM or core Tributes add-on).
            'donor_company'         => sanitize_text_field((string) ($donation['_give_donation_company'] ?? '')),
            // Honorific/title prefix (Mr., Ms., Dr., etc.).
            'donor_honorific'       => sanitize_text_field((string) ($donation['_give_donor_billing_title_prefix'] ?? '')),
            // Fee recovery — generic audit keys (all statuses preserved).
            'fee_amount'            => sanitize_text_field((string) ($donation['_give_fee_amount'] ?? '')),
            'fee_donation_amount'   => sanitize_text_field((string) ($donation['_give_fee_donation_amount'] ?? '')),
            'fee_status'            => sanitize_text_field((string) ($donation['_give_fee_status'] ?? '')),
            // Currency switcher: the donor's selected currency and exchange rate.
            // cs_base_currency is the site's default currency at the time of donation.
            'cs_base_currency'      => sanitize_text_field((string) ($donation['_give_cs_base_currency'] ?? '')),
            'cs_base_amount'        => sanitize_text_field((string) ($donation['_give_cs_base_amount'] ?? '')),
            'cs_exchange_rate'      => sanitize_text_field((string) ($donation['_give_cs_exchange_rate'] ?? '')),
        ];

        foreach ($optionalMeta as $key => $value) {
            if (!empty($value)) {
                $insertMeta($key, $value);
            }
        }

        // Native Paymattic fee recovery meta — used by the submission entry view
        // to display the fee breakdown. Only written when the donor accepted the fee.
        // _wpf_fee_amount and _wpf_net_donation_amount are stored as integer cent
        // strings; _wpf_fee_formatted is a plain decimal string with no currency sign.
        // These rows are structural: a submission with a fee_recovery_item order row
        // but missing _wpf_fee_* meta is inconsistent, so we rollback on any failure.
        if ($hasFeeRecovery && $feeCents > 0) {
            $feeFormatted = AmountConverter::toFormattedDecimal($feeCents, $currency);
            if (!$insertMeta('_wpf_fee_amount',          (string) $feeCents)
                || !$insertMeta('_wpf_fee_opted_in',        '1')
                || !$insertMeta('_wpf_net_donation_amount', (string) $feeDonationCents)
                || !$insertMeta('_wpf_fee_formatted',       $feeFormatted)) {
                $wpdb->query('ROLLBACK');
                $releaseLock();
                return 0;
            }
        }

        // SMS Text-to-Give: flag donations made via Twilio SMS integration.
        // Only insert this row when the Twilio meta is explicitly set to '1'.
        $isSmsGive = isset($donation['_give_text_to_give_donation_with_twilio'])
            && $donation['_give_text_to_give_donation_with_twilio'] === '1';
        if ($isSmsGive) {
            $insertMeta('source_channel', 'sms_text_to_give');
        }

        // ------------------------------------------------------------------
        // Pre-compute vendor dashboard URL for supported gateways.
        //
        // Stripe's entry_transactions filter (wppayform/entry_transactions_stripe)
        // adds a dashboard link at read time using charge_id + payment_mode stored
        // in wpf_order_transactions. That filter is active in both Lite and Pro and
        // will continue to work for Stripe submissions.
        //
        // For other gateways, we store the URL in wpf_meta so the entry details
        // page can display a direct link without depending on a gateway-specific
        // filter handler. This is stored as 'give_transaction_url'.
        //
        // We also store it for Stripe as a fallback in case the charge_id lookup
        // in the filter ever fails (e.g. empty charge_id for a manual Stripe charge).
        // ------------------------------------------------------------------

        $transactionUrl = GatewayMapper::getPaymentUrl($paymentMethod, $transactionId, $paymentMode);
        if ($transactionUrl !== '') {
            $insertMeta('give_transaction_url', $transactionUrl);
        }

        $wpdb->query('COMMIT');

        $releaseLock();
        return $submissionId;
    }
}
