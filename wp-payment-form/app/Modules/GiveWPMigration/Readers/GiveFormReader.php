<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Readers;

if (!defined('ABSPATH')) {
    exit;
}

use WPPayForm\App\Modules\GiveWPMigration\Readers\GiveEmailReader;

/**
 * GiveFormReader — reads GiveWP donation form data for migration.
 *
 * Queries give_forms CPT posts and their associated metadata from
 * {prefix}give_formmeta (NOT wp_postmeta). GiveWP stores form-specific
 * configuration in its own meta table, separate from standard postmeta.
 *
 * Detection logic:
 *  - v3 forms: presence of 'formBuilderSettings' key in give_formmeta
 *  - FFM (Form Field Manager): presence of 'give-form-fields' in wp_postmeta
 *  - Currency switcher: 'cs_status' key in give_formmeta
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Readers
 * @since   4.6.21
 */
class GiveFormReader
{
    /**
     * Retrieve all non-trashed GiveWP donation forms with their metadata.
     *
     * Returns an array of form data arrays, each containing the post fields
     * plus metadata needed by PaymatticFormWriter to create a wp_payform post.
     *
     * @return array Array of form data arrays. Empty array if no forms exist.
     */
    public static function getAll(): array
    {
        global $wpdb;

        // ------------------------------------------------------------------
        // 1. Fetch all non-trashed give_forms posts.
        // ------------------------------------------------------------------

        $formPosts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_status
                 FROM {$wpdb->posts}
                 WHERE post_type   = %s
                   AND post_status != %s
                 ORDER BY ID ASC",
                'give_forms',
                'trash'
            ),
            ARRAY_A
        );

        if (empty($formPosts)) {
            return [];
        }

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->prefix is a trusted, server-controlled value.
        $formMetaTable    = $wpdb->prefix . 'give_formmeta';
        $results          = [];

        $campaignTitleByFormId = [];
        $campaignsTable        = $wpdb->prefix . 'give_campaigns';
        $campaignFormsTable    = $wpdb->prefix . 'give_campaign_forms';

        $hasCampaignsTable = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($campaignsTable))
        );
        $hasCampaignFormsTable = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($campaignFormsTable))
        );

        if ($hasCampaignsTable && $hasCampaignFormsTable) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb->prefix only
            $campaignRows = $wpdb->get_results(
                "SELECT cf.form_id, c.campaign_title
                 FROM `{$campaignFormsTable}` cf
                 INNER JOIN `{$campaignsTable}` c ON c.id = cf.campaign_id",
                ARRAY_A
            );
            foreach ((array) $campaignRows as $r) {
                $campaignTitleByFormId[absint($r['form_id'])] = (string) $r['campaign_title'];
            }
        }

        foreach ($formPosts as $post) {
            $formId = absint($post['ID']);

            // ------------------------------------------------------------------
            // 2. Fetch all metadata for this form from give_formmeta.
            //
            // give_formmeta schema: form_id, meta_key, meta_value
            // We read in bulk and build a key→value map to avoid N+1 round trips.
            // ------------------------------------------------------------------

            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $formMetaTable composed from $wpdb->prefix only.
            $rawMeta = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value
                     FROM {$formMetaTable}
                     WHERE form_id = %d",
                    $formId
                ),
                ARRAY_A
            );

            // Build a flat key → value map. When a key appears multiple times,
            // the last value wins (GiveWP does not support multi-value form meta).
            $meta = [];
            foreach ($rawMeta as $row) {
                $meta[$row['meta_key']] = $row['meta_value'];
            }

            // ------------------------------------------------------------------
            // 3. Detect v3 forms.
            //
            // GiveWP v3 introduced a React-based form builder that stores its
            // configuration under 'formBuilderSettings'. Classic forms do not
            // have this key.
            // ------------------------------------------------------------------

            $isV3 = isset($meta['formBuilderSettings']);

            // ------------------------------------------------------------------
            // 4. Detect FFM (Form Field Manager) custom fields.
            //
            // The FFM add-on stores its field definitions in wp_postmeta under
            // 'give-form-fields' (standard WP post meta, not give_formmeta).
            // ------------------------------------------------------------------

            $ffmRaw    = get_post_meta($formId, 'give-form-fields', true);
            $hasFFM    = !empty($ffmRaw);

            // ------------------------------------------------------------------
            // 5. Extract monetary amounts.
            //
            // _give_set_price: the fixed or default donation amount (decimal string).
            // _give_form_earnings: cumulative earnings — not migrated to form, only
            //   stored here for informational/validation use.
            // Goal amount: _give_set_goal (decimal string, may be '0' when disabled).
            // ------------------------------------------------------------------

            $rawGoalAmount = isset($meta['_give_set_goal']) ? (string) $meta['_give_set_goal'] : '0';
            $goalOption    = isset($meta['_give_goal_option']) ? sanitize_text_field($meta['_give_goal_option']) : 'disabled';
            $goalFormat    = isset($meta['_give_goal_format']) ? sanitize_text_field($meta['_give_goal_format']) : 'amount';

            // _give_set_price: fixed donation amount used when price_option = 'set'.
            // Kept as a decimal string; AmountConverter handles unit conversion.
            $setPrice = isset($meta['_give_set_price']) ? (string) $meta['_give_set_price'] : '0';

            // ------------------------------------------------------------------
            // 6. Currency switcher detection.
            //
            // The GiveWP Currency Switcher add-on stores 'cs_status' in
            // give_formmeta. When set to 'enabled', donors can choose a currency.
            // We record the switcher settings for the goal conversion later.
            // ------------------------------------------------------------------

            $csStatus = isset($meta['cs_status']) ? sanitize_text_field($meta['cs_status']) : 'disabled';

            $currencySwitcher = [
                // 'enabled' kept for backwards compat; new code should read 'status'.
                'enabled'           => ($csStatus === 'enabled'),
                // Raw status: 'enabled' | 'disabled' | 'global'
                'status'            => $csStatus,
                'supported'         => isset($meta['cs_supported_currency']) ? wppayform_safeUnserialize($meta['cs_supported_currency']) : [],
                'default_currency'  => isset($meta['give_cs_default_currency']) ? sanitize_text_field($meta['give_cs_default_currency']) : '',
                // Flag for migration logging: per-currency custom prices cannot be replicated.
                'has_custom_prices' => !empty($meta['_give_cs_custom_prices']),
            ];

            // ------------------------------------------------------------------
            // 7. Form status (open / closed / archived in GiveWP).
            // _give_form_status: 'open' means accepting donations; 'closed' means
            // the form is disabled.
            // ------------------------------------------------------------------

            $formStatus = isset($meta['_give_form_status']) ? sanitize_text_field($meta['_give_form_status']) : 'open';

            // ------------------------------------------------------------------
            // 8. Pricing / donation-level configuration.
            //
            // _give_price_option: 'set' = single fixed amount, 'multi' = preset
            //   levels, 'custom' = open custom-amount only.
            //
            // _give_donation_levels: serialized array of level objects. Each level:
            //   [
            //     '_give_id'      => ['level_id' => '1'],
            //     '_give_amount'  => '25.00',          // decimal string
            //     '_give_text'    => 'Bronze Donor',   // may be absent
            //     '_give_default' => 'default',        // only on default level, may be absent
            //     '_give_recurring' => 'yes',          // per-level recurring, may be absent
            //     '_give_period'    => 'month',        // per-level period, may be absent
            //   ]
            //
            // _give_custom_amount: 'enabled' / 'disabled'
            // ------------------------------------------------------------------

            $priceOption = isset($meta['_give_price_option'])
                ? sanitize_text_field($meta['_give_price_option'])
                : 'set';

            // Fixed/default donation amount used when price_option = 'set'.
            $setPrice = isset($meta['_give_set_price'])
                ? (string) $meta['_give_set_price']
                : '0';

            $donationLevels = [];
            if (isset($meta['_give_donation_levels'])) {
                $unserialized = wppayform_safeUnserialize($meta['_give_donation_levels']);
                if (is_array($unserialized)) {
                    $donationLevels = $unserialized;
                }
            }

            $customAmountEnabled = isset($meta['_give_custom_amount'])
                ? sanitize_text_field($meta['_give_custom_amount'])
                : 'enabled';

            // ------------------------------------------------------------------
            // 9. Recurring / subscription configuration.
            //
            // _give_recurring: 'yes' / 'no' — whether the form uses give-recurring.
            // _give_period: billing period ('day', 'week', 'month', 'quarter', 'year').
            // _give_period_interval: integer frequency multiplier (default 1).
            // ------------------------------------------------------------------

            $recurringEnabled = isset($meta['_give_recurring'])
                ? sanitize_text_field($meta['_give_recurring'])
                : 'no';

            $recurringPeriod = isset($meta['_give_period'])
                ? sanitize_text_field($meta['_give_period'])
                : 'month';

            $recurringFrequency = isset($meta['_give_period_interval'])
                ? absint($meta['_give_period_interval'])
                : 1;

            // Ensure frequency is at least 1 — a zero value is invalid.
            if ($recurringFrequency < 1) {
                $recurringFrequency = 1;
            }

            // ------------------------------------------------------------------
            // 10. Assemble the return array for this form.
            // ------------------------------------------------------------------

            $results[] = [
                'id'                     => $formId,
                'title'                  => sanitize_text_field($post['post_title']),
                'campaign_title'         => sanitize_text_field($campaignTitleByFormId[$formId] ?? ''),
                'post_status'            => sanitize_text_field($post['post_status']),
                'is_v3'                  => $isV3,
                // Goal fields — amounts kept as strings; AmountConverter handles conversion.
                'goal_amount'            => $rawGoalAmount,
                'goal_option'            => $goalOption,
                'goal_format'            => $goalFormat,
                // Form operational status.
                'form_status'            => $formStatus,
                // Currency switcher data.
                'currency_switcher'      => $currencySwitcher,
                // FFM flag — used in Phase 5 (custom field migration).
                'has_ffm_fields'         => $hasFFM,
                // Pricing configuration.
                'price_option'           => $priceOption,
                'set_price'              => $setPrice,
                'donation_levels'        => $donationLevels,
                'custom_amount_enabled'  => $customAmountEnabled,
                // Recurring / subscription configuration.
                'recurring_enabled'      => $recurringEnabled,
                'recurring_period'       => $recurringPeriod,
                'recurring_frequency'    => $recurringFrequency,
                // Email notification configuration.
                'email_notifications'    => GiveEmailReader::getFormEmailNotifications($formId, $meta),
                'email_from_name'        => sanitize_text_field((string) (isset($meta['_give_from_name']) ? $meta['_give_from_name'] : '')),
                'email_from_email'       => sanitize_email((string) (isset($meta['_give_from_email']) ? $meta['_give_from_email'] : '')),
            ];
        }

        return $results;
    }
}
