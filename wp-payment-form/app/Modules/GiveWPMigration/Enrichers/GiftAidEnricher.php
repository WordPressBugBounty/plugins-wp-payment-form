<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Enrichers;

if (!defined('ABSPATH')) {
    exit;
}

use WPPayForm\App\Modules\GiveWPMigration\MigrationState;

/**
 * GiftAidEnricher — enriches already-migrated submissions with Gift Aid declaration data.
 *
 * GiveWP's Gift Aid add-on (give-gift-aid) records the donor's Gift Aid opt-in
 * flag in give_donationmeta, but stores the UK address in give_donormeta (on the
 * donor record, not the individual donation). This enricher:
 *
 *   1. Reads _give_gift_aid_accept_term_condition from give_donationmeta to determine
 *      opt-in status per donation.
 *   2. For opted-in donations, resolves the GiveWP donor ID from
 *      _give_payment_donor_id (also in give_donationmeta).
 *   3. Reads the donor's Gift Aid address from give_donormeta using the
 *      _give_gift_aid_card_* keys that the give-gift-aid plugin writes.
 *   4. Writes all data as wpf_meta rows on the corresponding Paymattic submission.
 *
 * IMPORTANT — pre-migration requirement:
 *   Before this migration runs, the admin MUST have exported the Gift Aid
 *   declarations CSV from GiveWP (Tools → Export → Gift Aid Donations).
 *   That HMRC-format export is only available while GiveWP is active.
 *   This migration cannot replace that reporting requirement — it only preserves
 *   the raw declaration data in Paymattic for record-keeping.
 *
 * Source meta keys:
 *   give_donationmeta (per donation):
 *     _give_gift_aid_accept_term_condition  — opt-in flag: 'on' or 'enabled'
 *     _give_payment_donor_id                — FK → give_donors.id
 *
 *   give_donormeta (per donor, shared across all donations):
 *     _give_gift_aid_card_address           — UK house number / first line
 *     _give_gift_aid_card_address_2         — address line 2
 *     _give_gift_aid_card_city              — city / town
 *     _give_gift_aid_card_zip               — UK postcode
 *
 * Opt-in handling:
 *   GiveWP versions use two different values for the opt-in flag:
 *     'on'      — used by the checkbox input (standard HTML checkbox behaviour)
 *     'enabled' — used by some custom implementations and older add-on versions
 *   Both are treated as opted-in and stored as '1' in Paymattic for consistency.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Enrichers
 * @since   4.6.21
 */
class GiftAidEnricher
{
    /**
     * Enrich a batch of already-migrated submissions with Gift Aid metadata.
     *
     * For each give_donation_id in $donationIdMap, reads Gift Aid meta from
     * give_donationmeta and writes corresponding rows to wpf_meta on the
     * Paymattic submission. Only non-empty values are written to keep wpf_meta
     * clean.
     *
     * @param array $donationIdMap Map of give_donation_id (int) → wpf_submission_id (int).
     *                              Example: [42 => 7, 43 => 8, ...]
     * @param bool  $dryRun        When true, counts enrichable records but writes nothing.
     *
     * @return array{enriched: int, skipped: int}
     *   enriched: number of submissions that received at least one new Gift Aid meta row.
     *   skipped:  number skipped (no Gift Aid data found or already enriched).
     */
    public static function enrichBatch(array $donationIdMap, bool $dryRun = false): array
    {
        $enriched = 0;
        $skipped  = 0;

        global $wpdb;

        $donationMetaTable = $wpdb->prefix . 'give_donationmeta';
        $donorMetaTable    = $wpdb->prefix . 'give_donormeta';
        $metaTable         = $wpdb->prefix . 'wpf_meta';
        $now               = current_time('mysql');

        // ------------------------------------------------------------------
        // Keys read from give_donationmeta (per-donation).
        // ------------------------------------------------------------------

        $donationKeys  = ['_give_gift_aid_accept_term_condition', '_give_payment_donor_id'];
        $donationPlaceholders = implode(', ', array_fill(0, count($donationKeys), '%s'));

        // ------------------------------------------------------------------
        // Keys read from give_donormeta (per-donor, shared across donations).
        // The give-gift-aid plugin writes address to the donor object, not
        // to individual donation meta — source: class-give-gift-aid-frontend.php.
        // ------------------------------------------------------------------

        $donorAddressKeys  = [
            '_give_gift_aid_card_address',
            '_give_gift_aid_card_address_2',
            '_give_gift_aid_card_city',
            '_give_gift_aid_card_zip',
        ];
        $donorPlaceholders = implode(', ', array_fill(0, count($donorAddressKeys), '%s'));

        foreach ($donationIdMap as $giveDonationId => $wpfSubmissionId) {
            $giveDonationId  = absint($giveDonationId);
            $wpfSubmissionId = absint($wpfSubmissionId);

            if ($giveDonationId < 1 || $wpfSubmissionId < 1) {
                $skipped++;
                continue;
            }

            // ------------------------------------------------------------------
            // Idempotency: if gift_aid_opted_in already exists for this submission,
            // skip it entirely on re-run.
            // ------------------------------------------------------------------

            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $metaTable composed from $wpdb->prefix only.
            $alreadyEnriched = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$metaTable}
                     WHERE meta_group = %s
                       AND option_id  = %d
                       AND meta_key   = %s
                     LIMIT 1",
                    'wpf_submissions',
                    $wpfSubmissionId,
                    'gift_aid_opted_in'
                )
            );

            if ($alreadyEnriched) {
                $skipped++;
                continue;
            }

            // ------------------------------------------------------------------
            // Step 1: load opt-in flag + donor ID from give_donationmeta.
            // ------------------------------------------------------------------

            // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $rawDonationMeta = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value
                     FROM {$donationMetaTable}
                     WHERE donation_id = %d
                       AND meta_key IN ({$donationPlaceholders})",
                    array_merge([$giveDonationId], $donationKeys)
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

            if (empty($rawDonationMeta)) {
                $skipped++;
                continue;
            }

            $donationMeta = [];
            foreach ($rawDonationMeta as $row) {
                $donationMeta[$row['meta_key']] = $row['meta_value'];
            }

            // ------------------------------------------------------------------
            // Determine opt-in status.
            //
            // GiveWP uses 'on' (standard HTML checkbox) or 'enabled' (some
            // custom implementations). Both mean the donor opted in to Gift Aid.
            // Missing or any other value = not opted in; skip the record.
            // ------------------------------------------------------------------

            $optInRaw   = $donationMeta['_give_gift_aid_accept_term_condition'] ?? '';
            $optInValue = strtolower(trim($optInRaw));
            $optedIn    = ($optInValue === 'on' || $optInValue === 'enabled');

            if (!$optedIn) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $enriched++;
                continue;
            }

            // ------------------------------------------------------------------
            // Step 2: resolve the GiveWP donor ID and load address from
            // give_donormeta. The give-gift-aid plugin stores address on the
            // donor object (shared across all their donations) — not per donation.
            // Source: class-give-gift-aid-frontend.php lines 533–538.
            // ------------------------------------------------------------------

            $giveDonorId = absint($donationMeta['_give_payment_donor_id'] ?? 0);

            $donorAddress = [
                'address'  => '',
                'address2' => '',
                'city'     => '',
                'postcode' => '',
            ];

            if ($giveDonorId > 0) {
                // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $rawDonorMeta = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT meta_key, meta_value
                         FROM {$donorMetaTable}
                         WHERE donor_id = %d
                           AND meta_key IN ({$donorPlaceholders})",
                        array_merge([$giveDonorId], $donorAddressKeys)
                    ),
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

                foreach ((array) $rawDonorMeta as $row) {
                    switch ($row['meta_key']) {
                        case '_give_gift_aid_card_address':
                            $donorAddress['address']  = $row['meta_value'];
                            break;
                        case '_give_gift_aid_card_address_2':
                            $donorAddress['address2'] = $row['meta_value'];
                            break;
                        case '_give_gift_aid_card_city':
                            $donorAddress['city']     = $row['meta_value'];
                            break;
                        case '_give_gift_aid_card_zip':
                            $donorAddress['postcode'] = $row['meta_value'];
                            break;
                    }
                }
            }

            // ------------------------------------------------------------------
            // Step 3: look up form_id for the submission (needed for wpf_meta.form_id).
            // ------------------------------------------------------------------

            $formId = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT form_id FROM {$wpdb->prefix}wpf_submissions WHERE id = %d LIMIT 1",
                        $wpfSubmissionId
                    )
                )
            );

            // Helper closure — inserts one meta row; skips if value is empty.
            // $wpdb->insert() returns false on failure, not an exception — check the
            // return value rather than wrapping in try/catch which would never fire.
            $insertMeta = function (string $key, string $value) use (
                $wpdb, $metaTable, $wpfSubmissionId, $formId, $now
            ): bool {
                if (empty($value)) {
                    return false;
                }

                $result = $wpdb->insert(
                    $metaTable,
                    [
                        'meta_group' => 'wpf_submissions',
                        'option_id'  => $wpfSubmissionId,
                        'form_id'    => $formId,
                        'meta_key'   => $key,
                        'meta_value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
                );

                if ($result === false) {
                    MigrationState::addError(
                        sprintf(
                            'GiftAidEnricher meta insert failed for wpf_submission.id=%d key=%s: %s',
                            $wpfSubmissionId,
                            $key,
                            substr(sanitize_text_field($wpdb->last_error), 0, 200)
                        )
                    );
                    return false;
                }

                return true;
            };

            // Write the opt-in flag first (the idempotency key for future runs).
            $insertMeta('gift_aid_opted_in', '1');

            // Write address fields from give_donormeta — only non-empty values.
            $insertMeta('gift_aid_address',      sanitize_text_field($donorAddress['address']));
            $insertMeta('gift_aid_address_line2', sanitize_text_field($donorAddress['address2']));
            $insertMeta('gift_aid_city',          sanitize_text_field($donorAddress['city']));
            $insertMeta('gift_aid_postcode',      strtoupper(sanitize_text_field($donorAddress['postcode'])));

            $enriched++;
        }

        return ['enriched' => $enriched, 'skipped' => $skipped];
    }
}
