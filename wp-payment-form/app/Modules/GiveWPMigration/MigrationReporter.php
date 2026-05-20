<?php

namespace WPPayForm\App\Modules\GiveWPMigration;

if (!defined('ABSPATH')) {
    exit;
}


/**
 * MigrationReporter — generates post-migration validation reports and handles rollback.
 *
 * This class is read-heavy by design: generateReport() executes only SELECT queries
 * against both the GiveWP source tables and the Paymattic destination tables, then
 * cross-references them against the MigrationState checkpoint. performRollback() is
 * the only mutating path, and it is always preceded by a dry-run count step.
 *
 * All queries use $wpdb->prepare() with typed placeholders (%s / %d). No raw SQL
 * string concatenation. Table names are composed from $wpdb->prefix which is a
 * trusted server-side value.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration
 * @since   4.6.21
 */
class MigrationReporter
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build the full migration validation report.
     *
     * Reads MigrationState and queries both source (GiveWP) and destination
     * (Paymattic) tables to produce counts, warnings, and a lossy-subscription
     * list.
     *
     * @return array {
     *     @type array  $counts              Counts sub-array (see inline docs).
     *     @type array  $lossy_subscriptions Subscriptions where interval could not be mapped.
     *     @type array  $addons_detected     Bool flags per addon.
     *     @type array  $warnings            Advisory strings.
     *     @type array  $errors              Last N errors from MigrationState.
     *     @type string $status              'idle'|'running'|'completed'|'failed'.
     *     @type string|null $started_at     ISO 8601 or null.
     *     @type string|null $completed_at   ISO 8601 or null.
     * }
     */
    public static function generateReport(): array
    {
        global $wpdb;

        $state = MigrationState::get();

        // ------------------------------------------------------------------
        // 1. Live GiveWP source counts (same queries as preflight).
        // ------------------------------------------------------------------

        $giveDonationsTotal = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     WHERE p.post_type = %s
                       AND p.post_status != %s
                       AND NOT (
                           p.post_parent > 0
                           AND EXISTS (
                               SELECT 1 FROM {$wpdb->postmeta} pm
                               WHERE pm.post_id = p.ID
                                 AND pm.meta_key = %s
                                 AND pm.meta_value = %s
                           )
                       )
                       AND NOT EXISTS (
                           SELECT 1 FROM {$wpdb->postmeta} pm2
                           WHERE pm2.post_id = p.ID
                             AND pm2.meta_key = %s
                             AND pm2.meta_value = %s
                       )",
                    'give_payment',
                    'trash',
                    '_give_is_donation_recurring', '1',
                    '_give_subscription_payment', '1'
                )
            )
        );

        $giveFormsTotal = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
                    'give_forms',
                    'trash'
                )
            )
        );

        // Subscription source table may not exist when recurring addon is inactive.
        $giveSubscriptionsTable = $wpdb->prefix . 'give_subscriptions';
        $subscriptionsTableExists = (bool) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($giveSubscriptionsTable))
        );

        $giveSubscriptionsTotal = 0;
        if ($subscriptionsTableExists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix only, no user input
            $giveSubscriptionsTotal = absint($wpdb->get_var("SELECT COUNT(*) FROM `{$giveSubscriptionsTable}`"));
        }

        // ------------------------------------------------------------------
        // 2. Paymattic destination counts — via wpf_meta idempotency keys.
        //
        // PaymatticSubmissionWriter stores meta_key='give_source_donation_id'
        // on every successfully migrated submission.
        // PaymatticSubscriptionWriter stores meta_key='give_subscription_id'
        // on every migrated subscription row.
        // ------------------------------------------------------------------

        $metaTable = $wpdb->prefix . 'wpf_meta';

        $donationsMigrated = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$metaTable} WHERE meta_key = %s AND meta_group = %s",
                    'give_source_donation_id',
                    'wpf_submissions'
                )
            )
        );

        $subscriptionsMigrated = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$metaTable} WHERE meta_key = %s AND meta_group = %s",
                    'give_subscription_id',
                    'wpf_subscriptions'
                )
            )
        );

        // Forms migrated: count wp_payform posts that have the give_source_form_id postmeta key.
        // JOIN with wp_posts to exclude orphaned postmeta rows for already-deleted posts.
        $formsMigrated = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT pm.post_id)
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s",
                    'give_source_form_id'
                )
            )
        );

        // ------------------------------------------------------------------
        // 3. Addon enrichment counts — read from wpf_meta idempotency markers.
        //
        // GiftAidEnricher writes gift_aid_opted_in rows; FFMEnricher writes ffm_* rows.
        // ------------------------------------------------------------------

        $donationsWithGiftAid = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT option_id) FROM {$metaTable}
                     WHERE meta_key = %s AND meta_value = %s AND meta_group = %s",
                    'gift_aid_opted_in',
                    '1',
                    'wpf_submissions'
                )
            )
        );

        $ffmFieldsMigrated = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$metaTable}
                     WHERE meta_key LIKE %s AND meta_group = %s",
                    $wpdb->esc_like('ffm_') . '%',
                    'wpf_submissions'
                )
            )
        );

        // ------------------------------------------------------------------
        // 4. Error count from MigrationState.
        // ------------------------------------------------------------------

        $errorCount = absint($state['counts']['errors'] ?? 0);

        // ------------------------------------------------------------------
        // 5. Lossy subscriptions — those where the billing interval could not
        //    be mapped to a Paymattic-native interval. PaymatticSubscriptionWriter
        //    stores a 'subscription_interval_note' meta_key when this occurs,
        //    with the original GiveWP period/frequency as the meta_value.
        //
        //    We join wpf_meta (option_id = subscription row) with wpf_subscriptions
        //    to get the give_subscription_id for the report.
        //    The give_subscription_id is also stored as a separate meta row, so we
        //    use a correlated sub-select to resolve it without an extra query round-trip.
        // ------------------------------------------------------------------

        $subscriptionsTable = $wpdb->prefix . 'wpf_subscriptions';
        $lossyRaw = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                     note_meta.option_id   AS wpf_sub_id,
                     note_meta.meta_value  AS note,
                     id_meta.meta_value    AS give_sub_id
                 FROM {$metaTable} note_meta
                 LEFT JOIN {$metaTable} id_meta
                     ON id_meta.option_id  = note_meta.option_id
                    AND id_meta.meta_key   = %s
                    AND id_meta.meta_group = %s
                 WHERE note_meta.meta_key   = %s
                   AND note_meta.meta_group = %s",
                'give_subscription_id',
                'wpf_subscriptions',
                'subscription_interval_note',
                'wpf_subscriptions'
            ),
            ARRAY_A
        );

        $lossySubscriptions = [];
        if (is_array($lossyRaw)) {
            foreach ($lossyRaw as $row) {
                $lossySubscriptions[] = [
                    'give_sub_id' => absint($row['give_sub_id']),
                    'wpf_sub_id'  => absint($row['wpf_sub_id']),
                    'note'        => sanitize_text_field($row['note']),
                ];
            }
        }

        // ------------------------------------------------------------------
        // 6. Addon detection (same SHOW TABLES + is_plugin_active approach as preflight).
        // ------------------------------------------------------------------

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $recurringAddon = $subscriptionsTableExists;

        $giftAidAddon = is_plugin_active('give-gift-aid/give-gift-aid.php');
        $ffmAddon     = is_plugin_active('give-form-field-manager/give-form-field-manager.php');

        // Detect addons that have no migration path to Paymattic.
        $noPathChecks = [
            'give-peer-to-peer/give-peer-to-peer.php'               => 'Peer-to-Peer (P2P)',
            'give-tributes/give-tributes.php'                        => 'Tributes',
            'give-annual-receipts/give-annual-receipts.php'          => 'Annual Receipts',
            'give-sequential-ordering/give-sequential-ordering.php'  => 'Sequential Donation IDs',
            'give-donor-dashboards/give-donor-dashboards.php'        => 'Donor Dashboards',
        ];
        $noPathAddons = [];
        foreach ($noPathChecks as $pluginFile => $label) {
            if (is_plugin_active($pluginFile)) {
                $noPathAddons[] = $label;
            }
        }

        // ------------------------------------------------------------------
        // 7. Warnings — same rules as preflight.
        // ------------------------------------------------------------------

        $warnings = [];

        if ($giftAidAddon) {
            $warnings[] = __('give-gift-aid is active. Ensure you have exported the Gift Aid declarations CSV from GiveWP (Tools → Export → Gift Aid Donations) before deactivating GiveWP.', 'wp-payment-form');
        }

        if (!defined('WPPAYFORMHASPRO')) {
            $warnings[] = __('Paymattic Pro is not active. Pro features and payment gateways (PayPal, Razorpay, Mollie, Paystack, Square, etc.) will not work until Paymattic Pro is installed and activated.', 'wp-payment-form');
        }

        if (!empty($noPathAddons)) {
            $warnings[] = sprintf(
                /* translators: %s: comma-separated list of addon names */
                __('The following GiveWP addons have NO migration path to Paymattic: %s. Export any data from these addons before deactivating GiveWP.', 'wp-payment-form'),
                implode(', ', $noPathAddons)
            );
        }

        // ------------------------------------------------------------------
        // 8. Not-migrated lists — source rows that exist in GiveWP but have
        //    no corresponding idempotency marker on the Paymattic side.
        //
        //    Each list is capped at 10k records so the downloaded JSON stays
        //    a reasonable size on extreme installs.
        // ------------------------------------------------------------------

        $stateErrors           = (array) ($state['errors'] ?? []);
        $formsNotMigrated         = self::collectFormsNotMigrated($stateErrors, 10000);
        $donationsNotMigrated     = self::collectDonationsNotMigrated($stateErrors, 10000);
        $subscriptionsNotMigrated = $subscriptionsTableExists
            ? self::collectSubscriptionsNotMigrated($stateErrors, 10000)
            : [];

        // ------------------------------------------------------------------
        // 9. Assemble the report.
        // ------------------------------------------------------------------

        return [
            'counts' => [
                'forms_total'             => $giveFormsTotal,
                'forms_migrated'          => $formsMigrated,
                'donations_total'         => $giveDonationsTotal,
                'donations_migrated'      => $donationsMigrated,
                'subscriptions_total'     => $giveSubscriptionsTotal,
                'subscriptions_migrated'  => $subscriptionsMigrated,
                'errors'                  => $errorCount,
                'donations_with_gift_aid' => $donationsWithGiftAid,
                'ffm_fields_migrated'     => $ffmFieldsMigrated,
            ],
            'lossy_subscriptions'      => $lossySubscriptions,
            'forms_not_migrated'       => $formsNotMigrated,
            'donations_not_migrated'   => $donationsNotMigrated,
            'subscriptions_not_migrated' => $subscriptionsNotMigrated,
            'addons_detected'          => [
                'recurring' => $recurringAddon,
                'gift_aid'  => $giftAidAddon,
                'ffm'       => $ffmAddon,
            ],
            'addons_no_path' => $noPathAddons,
            'warnings'       => $warnings,
            'errors'         => (array) ($state['errors'] ?? []),
            'status'         => sanitize_text_field($state['status'] ?? 'idle'),
            'started_at'     => $state['started_at'] ?? null,
            'completed_at'   => $state['completed_at'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------

    private static function collectDonationsNotMigrated(array $errorLog, int $limit): array
    {
        global $wpdb;

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->prefix is a trusted, server-controlled value.
        $metaTable = $wpdb->prefix . 'wpf_meta';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rowsSql = $wpdb->prepare(
            "SELECT p.ID, p.post_status, p.post_date
             FROM {$wpdb->posts} p
             WHERE p.post_type = %s
               AND p.post_status != %s
               AND NOT (
                   p.post_parent > 0
                   AND EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} pmr
                       WHERE pmr.post_id = p.ID
                         AND pmr.meta_key = %s
                         AND pmr.meta_value = %s
                   )
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pms
                   WHERE pms.post_id = p.ID
                     AND pms.meta_key = %s
                     AND pms.meta_value = %s
               )
               AND NOT EXISTS (
                   SELECT 1 FROM {$metaTable} wm
                   WHERE wm.meta_key = %s
                     AND wm.meta_group = %s
                     AND wm.meta_value = CAST(p.ID AS CHAR)
               )
             ORDER BY p.ID ASC
             LIMIT %d",
            'give_payment',
            'trash',
            '_give_is_donation_recurring', '1',
            '_give_subscription_payment', '1',
            'give_source_donation_id', 'wpf_submissions',
            $limit
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $rowsSql is built above via $wpdb->prepare().
        $rows = $wpdb->get_results($rowsSql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        $ids = array_map(static function ($row) {
            return absint($row['ID']);
        }, $rows);

        $metaKeys = [
            '_give_payment_total',
            '_give_payment_currency',
            '_give_payment_gateway',
            '_give_payment_donor_email',
            '_give_donor_billing_first_name',
            '_give_donor_billing_last_name',
            '_give_payment_form_id',
            '_give_payment_mode',
        ];

        $placeholders    = implode(',', array_fill(0, count($ids), '%d'));
        $keyPlaceholders = implode(',', array_fill(0, count($metaKeys), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $metaSql = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ($placeholders)
               AND meta_key IN ($keyPlaceholders)",
            array_merge($ids, $metaKeys)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $metaSql is built above via $wpdb->prepare().
        $rawMeta = $wpdb->get_results($metaSql, ARRAY_A);

        $metaByPost = [];
        foreach ($rawMeta as $r) {
            $metaByPost[(int) $r['post_id']][$r['meta_key']] = $r['meta_value'];
        }

        $erroredIds = [];
        foreach ($errorLog as $msg) {
            if (preg_match('/give_payment\.ID=(\d+)/', (string) $msg, $m)) {
                $erroredIds[(int) $m[1]] = true;
            }
        }

        $records = [];
        foreach ($rows as $row) {
            $id          = (int) $row['ID'];
            $meta        = $metaByPost[$id] ?? [];
            $postStatus  = (string) $row['post_status'];
            $paymentMode = $meta['_give_payment_mode'] ?? 'live';
            $isTestMode  = ($paymentMode === 'test');

            $firstName = $meta['_give_donor_billing_first_name'] ?? '';
            $lastName  = $meta['_give_donor_billing_last_name'] ?? '';
            $donorName = trim($firstName . ' ' . $lastName);

            $skipReason = 'unknown';
            if (isset($erroredIds[$id])) {
                $skipReason = 'error_logged';
            } elseif ($isTestMode) {
                $skipReason = 'test_mode';
            } elseif (in_array($postStatus, ['abandoned', 'pending', 'failed', 'revoked', 'refunded', 'preapproval', 'cancelled'], true)) {
                $skipReason = $postStatus;
            } elseif (empty($meta['_give_payment_total'])) {
                $skipReason = 'no_amount';
            } elseif (empty($meta['_give_payment_donor_email'])) {
                $skipReason = 'no_donor';
            }

            $records[] = [
                'give_donation_id' => $id,
                'post_status'      => $postStatus,
                'post_date'        => (string) $row['post_date'],
                'form_id'          => absint($meta['_give_payment_form_id'] ?? 0),
                'donor_email'      => sanitize_email($meta['_give_payment_donor_email'] ?? ''),
                'donor_name'       => sanitize_text_field($donorName),
                'amount'           => sanitize_text_field((string) ($meta['_give_payment_total'] ?? '')),
                'currency'         => sanitize_text_field($meta['_give_payment_currency'] ?? ''),
                'gateway'          => sanitize_text_field($meta['_give_payment_gateway'] ?? ''),
                'test_mode'        => $isTestMode,
                'skip_reason'      => $skipReason,
            ];
        }

        return $records;
    }

    // -------------------------------------------------------------------------

    private static function collectFormsNotMigrated(array $errorLog, int $limit): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rowsSql = $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, p.post_date
             FROM {$wpdb->posts} p
             WHERE p.post_type = %s
               AND p.post_status != %s
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->postmeta} pm
                   WHERE pm.meta_key = %s
                     AND pm.meta_value = CAST(p.ID AS CHAR)
               )
             ORDER BY p.ID ASC
             LIMIT %d",
            'give_forms',
            'trash',
            'give_source_form_id',
            $limit
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $rowsSql is built above via $wpdb->prepare().
        $rows = $wpdb->get_results($rowsSql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        $erroredIds = [];
        foreach ($errorLog as $msg) {
            if (preg_match('/give_forms\.ID=(\d+)|form_id=(\d+)/', (string) $msg, $m)) {
                $id = absint($m[1] ?: $m[2]);
                if ($id) {
                    $erroredIds[$id] = true;
                }
            }
        }

        $records = [];
        foreach ($rows as $row) {
            $id         = (int) $row['ID'];
            $postStatus = (string) $row['post_status'];
            $title      = (string) $row['post_title'];

            $skipReason = 'unknown';
            if (isset($erroredIds[$id])) {
                $skipReason = 'error_logged';
            } elseif (in_array($postStatus, ['draft', 'pending', 'private', 'auto-draft'], true)) {
                $skipReason = $postStatus;
            } elseif ($title === '') {
                $skipReason = 'no_title';
            }

            $records[] = [
                'give_form_id' => $id,
                'title'        => sanitize_text_field($title),
                'post_status'  => $postStatus,
                'post_date'    => (string) $row['post_date'],
                'skip_reason'  => $skipReason,
            ];
        }

        return $records;
    }

    // -------------------------------------------------------------------------

    private static function collectSubscriptionsNotMigrated(array $errorLog, int $limit): array
    {
        global $wpdb;

        $subsTable = $wpdb->prefix . 'give_subscriptions';
        $metaTable = $wpdb->prefix . 'wpf_meta';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rowsSql = $wpdb->prepare(
            "SELECT s.id, s.status, s.period, s.frequency, s.recurring_amount,
                    s.customer_id, s.parent_payment_id, s.product_id, s.created
             FROM `{$subsTable}` s
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$metaTable} wm
                 WHERE wm.meta_key   = %s
                   AND wm.meta_group = %s
                   AND wm.meta_value = CAST(s.id AS CHAR)
             )
             ORDER BY s.id ASC
             LIMIT %d",
            'give_subscription_id',
            'wpf_subscriptions',
            $limit
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $rowsSql is built above via $wpdb->prepare().
        $rows = $wpdb->get_results($rowsSql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        $erroredIds = [];
        foreach ($errorLog as $msg) {
            if (preg_match('/give_subscription\.id=(\d+)|subscription_id=(\d+)/', (string) $msg, $m)) {
                $id = absint($m[1] ?: $m[2]);
                if ($id) {
                    $erroredIds[$id] = true;
                }
            }
        }

        $records = [];
        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $status = (string) $row['status'];

            $skipReason = 'unknown';
            if (isset($erroredIds[$id])) {
                $skipReason = 'error_logged';
            } elseif (in_array($status, ['pending', 'failing', 'abandoned', 'expired', 'trashed'], true)) {
                $skipReason = $status;
            } elseif (empty($row['recurring_amount'])) {
                $skipReason = 'no_amount';
            } elseif (empty($row['period'])) {
                $skipReason = 'no_interval';
            }

            $records[] = [
                'give_subscription_id' => $id,
                'status'               => $status,
                'period'               => (string) $row['period'],
                'frequency'            => (int) $row['frequency'],
                'recurring_amount'     => (string) $row['recurring_amount'],
                'customer_id'          => (int) $row['customer_id'],
                'parent_payment_id'    => (int) $row['parent_payment_id'],
                'product_id'           => (int) $row['product_id'],
                'created'              => (string) $row['created'],
                'skip_reason'          => $skipReason,
            ];
        }

        return $records;
    }

    // -------------------------------------------------------------------------

    /**
     * Collect the IDs of every Paymattic entity created during migration.
     *
     * This is a read-only query that gathers all rollback targets. It is called
     * by performRollback() and exposed separately so the UI can display a dry-run
     * summary before the admin confirms destructive deletion.
     *
     * @return array {
     *     @type int[]  $submission_ids   wpf_submissions.id values to delete.
     *     @type int[]  $subscription_ids wpf_subscriptions.id values to delete.
     *     @type int[]  $form_ids         wp_payform post IDs to delete.
     * }
     */
    public static function generateRollbackData(): array
    {
        global $wpdb;

        $metaTable = $wpdb->prefix . 'wpf_meta';

        // ------------------------------------------------------------------
        // Submission IDs: wpf_meta rows with give_source_donation_id.
        // option_id in wpf_meta maps to wpf_submissions.id for submission meta.
        // ------------------------------------------------------------------

        $submissionIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT option_id FROM {$metaTable} WHERE meta_key = %s AND meta_group = %s",
                'give_source_donation_id',
                'wpf_submissions'
            )
        );

        $submissionIds = array_map('absint', (array) $submissionIds);
        $submissionIds = array_filter($submissionIds); // Remove zeros.

        // ------------------------------------------------------------------
        // Subscription IDs: wpf_meta rows with give_subscription_id.
        // ------------------------------------------------------------------

        $subscriptionIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT option_id FROM {$metaTable} WHERE meta_key = %s AND meta_group = %s",
                'give_subscription_id',
                'wpf_subscriptions'
            )
        );

        $subscriptionIds = array_map('absint', (array) $subscriptionIds);
        $subscriptionIds = array_filter($subscriptionIds);

        // ------------------------------------------------------------------
        // Form IDs: wp_payform posts that have the give_source_form_id postmeta.
        // JOIN with wp_posts to exclude orphaned rows for already-deleted posts.
        // ------------------------------------------------------------------

        $formIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s",
                'give_source_form_id'
            )
        );

        $formIds = array_map('absint', (array) $formIds);
        $formIds = array_filter($formIds);

        return [
            'submission_ids'   => array_values($submissionIds),
            'subscription_ids' => array_values($subscriptionIds),
            'form_ids'         => array_values($formIds),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Roll back all Paymattic data created by this migration.
     *
     * Deletes (in dependency order):
     *   1. wpf_meta rows for each submission / subscription
     *   2. wpf_order_items rows per submission
     *   3. wpf_order_transactions rows per submission
     *   4. wpf_submission_activities rows per submission
     *   5. wpf_subscriptions rows
     *   6. wpf_submissions rows
     *   7. wp_payform posts (force-deletes, bypasses trash)
     *
     * Also resets MigrationState to idle so the migration can be re-run.
     *
     * @param bool $dryRun When true, returns counts without performing deletes.
     *
     * @return array {
     *     @type int  $submissions_deleted   Number of submission rows deleted.
     *     @type int  $subscriptions_deleted Number of subscription rows deleted.
     *     @type int  $forms_deleted         Number of wp_payform posts deleted.
     *     @type int  $meta_rows_deleted     Number of wpf_meta rows deleted.
     *     @type int  $order_items_deleted   Number of wpf_order_items rows deleted.
     *     @type int  $transactions_deleted  Number of wpf_order_transactions rows deleted.
     *     @type int  $activities_deleted    Number of wpf_submission_activities rows deleted.
     *     @type bool $dry_run               Whether this was a dry run.
     * }
     */
    public static function performRollback(bool $dryRun = false): array
    {
        global $wpdb;

        $rollbackData    = self::generateRollbackData();
        $submissionIds   = $rollbackData['submission_ids'];
        $subscriptionIds = $rollbackData['subscription_ids'];
        $formIds         = $rollbackData['form_ids'];

        // Counts (populated regardless of dry-run mode for the summary).
        $metaRowsDeleted     = 0;
        $orderItemsDeleted   = 0;
        $transactionsDeleted = 0;
        $activitiesDeleted   = 0;
        $subscriptionsDeleted = 0;
        $submissionsDeleted   = 0;
        $formsDeleted         = 0;

        // Table names (all use $wpdb->prefix — trusted server value).
        $metaTable         = $wpdb->prefix . 'wpf_meta';
        $orderItemsTable   = $wpdb->prefix . 'wpf_order_items';
        $transactionsTable = $wpdb->prefix . 'wpf_order_transactions';
        $activitiesTable   = $wpdb->prefix . 'wpf_submission_activities';
        $subscriptionsTable = $wpdb->prefix . 'wpf_subscriptions';
        $submissionsTable   = $wpdb->prefix . 'wpf_submissions';

        // ------------------------------------------------------------------
        // COUNT phase — always executed so we can return accurate dry-run totals.
        // ------------------------------------------------------------------

        if (!empty($submissionIds)) {
            // SEC-MED-003: All ids passed through array_map('intval') as a
            // structural guarantee, then bound via %d placeholders so callers
            // never produce raw IN() strings.
            $submissionIds   = array_map('intval', $submissionIds);
            $subPlaceholders = implode(',', array_fill(0, count($submissionIds), '%d'));

            $metaRowsDeleted += absint($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$metaTable}
                     WHERE meta_group = %s
                       AND option_id IN ({$subPlaceholders})",
                    array_merge(['wpf_submissions'], $submissionIds)
                )
            ));

            $orderItemsDeleted = absint($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$orderItemsTable}
                     WHERE submission_id IN ({$subPlaceholders})",
                    $submissionIds
                )
            ));

            $transactionsDeleted = absint($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$transactionsTable}
                     WHERE submission_id IN ({$subPlaceholders})",
                    $submissionIds
                )
            ));

            $activitiesDeleted = absint($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$activitiesTable}
                     WHERE submission_id IN ({$subPlaceholders})",
                    $submissionIds
                )
            ));

            $submissionsDeleted = count($submissionIds);
        }

        if (!empty($subscriptionIds)) {
            $subscriptionIds  = array_map('intval', $subscriptionIds);
            $subsPlaceholders = implode(',', array_fill(0, count($subscriptionIds), '%d'));

            $metaRowsDeleted += absint($wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$metaTable}
                     WHERE option_id IN ({$subsPlaceholders})
                       AND meta_key IN (%s, %s)",
                    array_merge($subscriptionIds, ['give_subscription_id', 'subscription_interval_note'])
                )
            ));

            $subscriptionsDeleted = count($subscriptionIds);
        }

        $formsDeleted = count($formIds);

        // ------------------------------------------------------------------
        // DELETE phase — skipped when $dryRun = true.
        // ------------------------------------------------------------------

        if (!$dryRun) {
            // Process submissions in chunks to avoid hitting max_allowed_packet
            // or IN() clause length limits on large datasets.
            $chunkSize = 100;

            foreach (array_chunk($submissionIds, $chunkSize) as $chunk) {
                if (empty($chunk)) {
                    continue;
                }

                // SEC-MED-003: cast to int then bind via prepared %d placeholders.
                $chunk        = array_map('intval', $chunk);
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$metaTable}
                         WHERE meta_group = %s
                           AND option_id IN ({$placeholders})",
                        array_merge(['wpf_submissions'], $chunk)
                    )
                );

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$orderItemsTable} WHERE submission_id IN ({$placeholders})",
                        $chunk
                    )
                );

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$transactionsTable} WHERE submission_id IN ({$placeholders})",
                        $chunk
                    )
                );

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$activitiesTable} WHERE submission_id IN ({$placeholders})",
                        $chunk
                    )
                );

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$submissionsTable} WHERE id IN ({$placeholders})",
                        $chunk
                    )
                );
            }

            // Subscription rows and their meta.
            foreach (array_chunk($subscriptionIds, $chunkSize) as $chunk) {
                if (empty($chunk)) {
                    continue;
                }

                $chunk        = array_map('intval', $chunk);
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$metaTable}
                         WHERE option_id IN ({$placeholders})
                           AND meta_key IN (%s, %s)",
                        array_merge($chunk, ['give_subscription_id', 'subscription_interval_note'])
                    )
                );

                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$subscriptionsTable} WHERE id IN ({$placeholders})",
                        $chunk
                    )
                );
            }

            // wp_payform posts — force-delete (bypass trash) using WP API so
            // all associated postmeta and post relationships are cleaned up.
            foreach ($formIds as $formPostId) {
                wp_delete_post(absint($formPostId), true);
            }

            // Clean up any orphaned give_source_form_id postmeta that
            // wp_delete_post() skips when the post no longer exists.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
                    'give_source_form_id'
                )
            );

            // Reset the migration checkpoint so the admin can start fresh.
            MigrationState::reset();
        }

        return [
            'submissions_deleted'   => $submissionsDeleted,
            'subscriptions_deleted' => $subscriptionsDeleted,
            'forms_deleted'         => $formsDeleted,
            'meta_rows_deleted'     => $metaRowsDeleted,
            'order_items_deleted'   => $orderItemsDeleted,
            'transactions_deleted'  => $transactionsDeleted,
            'activities_deleted'    => $activitiesDeleted,
            'dry_run'               => $dryRun,
        ];
    }
}
