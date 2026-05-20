<?php

namespace WPPayForm\App\Modules\GiveWPMigration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MigrationState — persists all migration progress in wp_options.
 *
 * A single wp_options key (`wpf_givewp_migration_state`) stores a serialized
 * array representing the full checkpoint of the ongoing (or completed) migration.
 * This allows AJAX batch processing to be safely interrupted and resumed.
 *
 * Idempotency guarantee: `form_map`, `donation_map`, and `subscription_map`
 * store GiveWP ID → Paymattic ID pairs. Writers check these maps before
 * inserting to avoid duplicate rows on retry.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration
 * @since   4.6.21
 */
class MigrationState
{
    /**
     * wp_options key where the state array is stored.
     *
     * @var string
     */
    const OPTION_KEY = 'wpf_givewp_migration_state';

    /**
     * Maximum number of error messages to keep in the errors array.
     * Older errors are dropped to avoid unbounded growth in wp_options.
     *
     * @var int
     */
    const MAX_ERRORS = 50;

    /**
     * Returns the default (empty) state shape.
     *
     * Calling reset() restores this shape.
     *
     * @return array
     */
    private static function defaultState(): array
    {
        return [
            // Overall lifecycle status.
            'status'      => 'idle', // idle | running | completed | failed

            // ISO 8601 timestamps set when migration starts / finishes.
            'started_at'   => null,
            'completed_at' => null,

            // Human-readable label for the current migration phase.
            'phase' => null,

            // give_form_id => wpf_form_id (int keys, int values).
            // Used by donation/subscription writers to look up the target form.
            'form_map' => [],

            // give_donation_id => wpf_submission_id.
            // Used to detect already-migrated donations on retry.
            'donation_map' => [],

            // give_subscription_id => wpf_subscription_id.
            // Used to detect already-migrated subscriptions on retry.
            'subscription_map' => [],

            // Cursor for paginated donation batch processing.
            // The reader queries WHERE ID > last_processed_donation_id ORDER BY ID ASC.
            'last_processed_donation_id' => 0,

            // Cursor for paginated subscription batch processing.
            'last_processed_subscription_id' => 0,

            // Running totals updated after each batch.
            'counts' => [
                'forms_total'         => 0,
                'forms_done'          => 0,
                'donations_total'     => 0,
                'donations_done'      => 0,
                'subscriptions_total' => 0,
                'subscriptions_done'  => 0,
                'errors'              => 0,
            ],

            // Capped ring-buffer of error messages (PII-free).
            'errors' => [],

            // When true, all readers and writers run in read-only mode:
            // counts are computed but no rows are inserted.
            'dry_run' => false,

            // When true, only test-mode (payment_mode = 'test') donations are migrated.
            'migrate_test_mode' => false,

            // Must be explicitly set to true by admin before migration proceeds
            // when give-gift-aid is detected. Forces pre-migration CSV export acknowledgement.
            'gift_aid_acknowledged' => false,
        ];
    }

    /**
     * Retrieve the current migration state from wp_options.
     *
     * If no state has been persisted yet (first run), returns the default shape.
     *
     * @return array
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY, null);

        if (!is_array($stored)) {
            return self::defaultState();
        }

        // Merge with defaults to ensure new keys added in future versions are present.
        return array_replace_recursive(self::defaultState(), $stored);
    }

    /**
     * Persist an updated state to wp_options.
     *
     * Merges the supplied data onto the existing state (partial update is safe).
     *
     * @param array $data Key-value pairs to merge into the current state.
     *
     * @return void
     */
    public static function set(array $data): void
    {
        $current = self::get();
        $updated = array_replace($current, $data);
        update_option(self::OPTION_KEY, $updated, false);
    }

    /**
     * Reset state to the default (idle) shape.
     *
     * Called when the admin triggers a "Reset migration" action before
     * starting fresh. Does NOT delete migrated Paymattic rows — that is
     * the rollback writer's responsibility.
     *
     * @return void
     */
    public static function reset(): void
    {
        update_option(self::OPTION_KEY, self::defaultState(), false);
    }

    /**
     * Increment (or decrement) a named count in the counts sub-array.
     *
     * Example: MigrationState::updateCounts('donations_done', 1)
     *          MigrationState::updateCounts('errors', 1)
     *
     * @param string $key   Key inside the 'counts' sub-array.
     * @param int    $delta Amount to add (use negative for decrement).
     *
     * @return void
     */
    public static function updateCounts(string $key, int $delta): void
    {
        $state = self::get();

        if (!isset($state['counts'][$key])) {
            $state['counts'][$key] = 0;
        }

        $state['counts'][$key] = max(0, (int) ($state['counts'][$key] + $delta));
        update_option(self::OPTION_KEY, $state, false);
    }

    /**
     * Append an error message to the error ring-buffer.
     *
     * Messages beyond MAX_ERRORS are silently dropped (oldest first).
     * Never pass raw exception messages that may contain PII or SQL.
     *
     * @param string $msg Error description (PII-free).
     *
     * @return void
     */
    public static function addError(string $msg): void
    {
        $state = self::get();

        $state['errors'][] = sanitize_text_field($msg);

        // Trim to cap.
        if (count($state['errors']) > self::MAX_ERRORS) {
            $state['errors'] = array_slice($state['errors'], -self::MAX_ERRORS);
        }

        // Also increment the error counter.
        $state['counts']['errors'] = absint($state['counts']['errors']) + 1;

        update_option(self::OPTION_KEY, $state, false);
    }

    /**
     * Record a GiveWP form ID → Paymattic form ID mapping.
     *
     * @param int $giveId GiveWP give_forms post ID.
     * @param int $wpfId  Paymattic wp_payform post ID.
     *
     * @return void
     */
    public static function setFormMap(int $giveId, int $wpfId): void
    {
        $state = self::get();
        $state['form_map'][absint($giveId)] = absint($wpfId);
        update_option(self::OPTION_KEY, $state, false);
    }

    /**
     * Record a GiveWP donation post ID → Paymattic submission ID mapping.
     *
     * @param int $giveId GiveWP give_payment post ID.
     * @param int $wpfId  Paymattic wpf_submissions.id.
     *
     * @return void
     */
    public static function setDonationMap(int $giveId, int $wpfId): void
    {
        $state = self::get();
        $state['donation_map'][absint($giveId)] = absint($wpfId);
        update_option(self::OPTION_KEY, $state, false);
    }

    /**
     * Record a GiveWP subscription ID → Paymattic subscription ID mapping.
     *
     * @param int $giveId GiveWP give_subscriptions.id.
     * @param int $wpfId  Paymattic wpf_subscriptions.id.
     *
     * @return void
     */
    public static function setSubscriptionMap(int $giveId, int $wpfId): void
    {
        $state = self::get();
        $state['subscription_map'][absint($giveId)] = absint($wpfId);
        update_option(self::OPTION_KEY, $state, false);
    }
}
