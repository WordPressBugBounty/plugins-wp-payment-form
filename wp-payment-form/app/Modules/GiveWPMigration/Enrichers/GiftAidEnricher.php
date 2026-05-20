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
 * and UK address details in give_donationmeta. This enricher reads those meta
 * keys and writes them as wpf_meta rows on the corresponding Paymattic submission.
 *
 * IMPORTANT — pre-migration requirement:
 *   Before this migration runs, the admin MUST have exported the Gift Aid
 *   declarations CSV from GiveWP (Tools → Export → Gift Aid Donations).
 *   That HMRC-format export is only available while GiveWP is active.
 *   This migration cannot replace that reporting requirement — it only preserves
 *   the raw declaration data in Paymattic for record-keeping.
 *
 * Meta keys read from give_donationmeta:
 *   _give_gift_aid_accept_term_condition  — opt-in flag: 'on' or 'enabled'
 *   _give_gift_aid_home_address           — UK house number / first line
 *   _give_gift_aid_address_line2          — address line 2
 *   _give_gift_aid_city                   — city / town
 *   _give_gift_aid_postcode               — UK postcode
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
        $metaTable         = $wpdb->prefix . 'wpf_meta';
        $now               = current_time('mysql');

        // ------------------------------------------------------------------
        // The meta keys we pull from give_donationmeta, mapped to the
        // Paymattic meta key names they will be stored under.
        // ------------------------------------------------------------------

        $metaKeyMap = [
            '_give_gift_aid_accept_term_condition' => null,            // handled specially below
            '_give_gift_aid_home_address'          => 'gift_aid_address',
            '_give_gift_aid_address_line2'         => 'gift_aid_address_line2',
            '_give_gift_aid_city'                  => 'gift_aid_city',
            '_give_gift_aid_postcode'              => 'gift_aid_postcode',
        ];

        $allGiveKeys  = array_keys($metaKeyMap);
        $placeholders = implode(', ', array_fill(0, count($allGiveKeys), '%s'));

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
            // Load all relevant give_donationmeta rows in a single query.
            // ------------------------------------------------------------------

            // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $rawMeta = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value
                     FROM {$donationMetaTable}
                     WHERE donation_id = %d
                       AND meta_key IN ({$placeholders})",
                    array_merge([$giveDonationId], $allGiveKeys)
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

            if (empty($rawMeta)) {
                $skipped++;
                continue;
            }

            // Flatten to key => value.
            $giveMetaValues = [];
            foreach ($rawMeta as $row) {
                $giveMetaValues[$row['meta_key']] = $row['meta_value'];
            }

            // ------------------------------------------------------------------
            // Determine opt-in status.
            //
            // GiveWP uses 'on' (standard HTML checkbox) or 'enabled' (some
            // custom implementations). Both mean the donor opted in to Gift Aid.
            // Missing or any other value = not opted in; skip the record.
            // ------------------------------------------------------------------

            $optInRaw   = $giveMetaValues['_give_gift_aid_accept_term_condition'] ?? '';
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
            // Look up form_id for the submission so we can populate wpf_meta.form_id.
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
            $insertMeta = function (string $key, string $value) use (
                $wpdb, $metaTable, $wpfSubmissionId, $formId, $now
            ): bool {
                if (empty($value)) {
                    return false;
                }

                try {
                    return (bool) $wpdb->insert(
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
                } catch (\Exception $e) {
                    MigrationState::addError(
                        sprintf(
                            'GiftAidEnricher meta insert failed for wpf_submission.id=%d key=%s: %s',
                            $wpfSubmissionId,
                            $key,
                            substr(sanitize_text_field($e->getMessage()), 0, 200)
                        )
                    );
                    return false;
                }
            };

            // Write the opt-in flag first (the idempotency key for future runs).
            $insertMeta('gift_aid_opted_in', '1');

            // Write address fields — only non-empty values.
            $insertMeta(
                'gift_aid_address',
                sanitize_text_field($giveMetaValues['_give_gift_aid_home_address'] ?? '')
            );
            $insertMeta(
                'gift_aid_address_line2',
                sanitize_text_field($giveMetaValues['_give_gift_aid_address_line2'] ?? '')
            );
            $insertMeta(
                'gift_aid_city',
                sanitize_text_field($giveMetaValues['_give_gift_aid_city'] ?? '')
            );
            $insertMeta(
                'gift_aid_postcode',
                sanitize_text_field($giveMetaValues['_give_gift_aid_postcode'] ?? '')
            );

            $enriched++;
        }

        return ['enriched' => $enriched, 'skipped' => $skipped];
    }
}
