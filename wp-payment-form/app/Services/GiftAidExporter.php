<?php

namespace WPPayForm\App\Services;

if (!defined('ABSPATH')) {
    exit;
}

class GiftAidExporter
{
    /**
     * Returns true if the form has at least one Gift Aid opt-in declaration.
     */
    public static function hasDeclarations(int $formId): bool
    {
        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->prefix}wpf_meta
                 WHERE meta_key   = %s
                   AND meta_group = %s
                   AND form_id    = %d",
                'gift_aid_opted_in',
                'wpf_submissions',
                $formId
            )
        );

        return $count > 0;
    }

    /**
     * Streams an HMRC ChR1-format CSV to the browser and exits.
     *
     * @param int   $formId
     * @param array $options {
     *   @type string $charity_name  Required.
     *   @type string $hmrc_ref      Required.
     *   @type string $date_from     Optional, Y-m-d.
     *   @type string $date_to       Optional, Y-m-d.
     * }
     */
    public static function export(int $formId, array $options): void
    {
        global $wpdb;

        $charityName = sanitize_text_field($options['charity_name'] ?? '');
        $hmrcRef     = sanitize_text_field($options['hmrc_ref']     ?? '');
        $dateFrom    = sanitize_text_field($options['date_from']    ?? '');
        $dateTo      = sanitize_text_field($options['date_to']      ?? '');

        $validateDate = static function (string $value): string {
            if ($value === '') {
                return '';
            }
            $parsed = \DateTime::createFromFormat('Y-m-d', $value);
            return ($parsed && $parsed->format('Y-m-d') === $value) ? $value : '';
        };

        $dateFrom = $validateDate($dateFrom);
        $dateTo   = $validateDate($dateTo);

        $sanitizeCsvCell = static function (string $value): string {
            return ltrim($value, "=+-@\t\r");
        };

        $charityName = $sanitizeCsvCell($charityName);
        $hmrcRef     = $sanitizeCsvCell($hmrcRef);

        $submissionsTable  = $wpdb->prefix . 'wpf_submissions';
        $metaTable         = $wpdb->prefix . 'wpf_meta';
        $transactionsTable = $wpdb->prefix . 'wpf_order_transactions';

        // Date conditions filter paid transaction dates so the exported amount
        // reflects in-period payments, not in-period submission creation dates.
        $txDateConditions = '';
        $extraParams      = [];

        if ($dateFrom) {
            $txDateConditions .= ' AND created_at >= %s';
            $extraParams[]     = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $txDateConditions .= ' AND created_at <= %s';
            $extraParams[]     = $dateTo . ' 23:59:59';
        }

        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                     s.id                                                                           AS submission_id,
                     s.customer_name,
                     s.customer_email,
                     MAX(CASE WHEN m.meta_key = 'gift_aid_address'       THEN m.meta_value END)    AS ga_address,
                     MAX(CASE WHEN m.meta_key = 'gift_aid_address_line2' THEN m.meta_value END)    AS ga_address2,
                     MAX(CASE WHEN m.meta_key = 'gift_aid_city'          THEN m.meta_value END)    AS ga_city,
                     MAX(CASE WHEN m.meta_key = 'gift_aid_postcode'      THEN m.meta_value END)    AS ga_postcode,
                     COALESCE(tx.total_pence, 0)                                                    AS total_pence
                 FROM {$submissionsTable} s
                 INNER JOIN {$metaTable} m
                         ON m.option_id  = s.id
                        AND m.meta_group = 'wpf_submissions'
                 LEFT JOIN (
                     SELECT submission_id, SUM(payment_total) AS total_pence
                     FROM {$transactionsTable}
                     WHERE status = 'paid'
                       {$txDateConditions}
                     GROUP BY submission_id
                 ) tx ON tx.submission_id = s.id
                 WHERE s.form_id  = %d
                   AND EXISTS (
                       SELECT 1 FROM {$metaTable} gm
                       WHERE gm.option_id  = s.id
                         AND gm.meta_group = 'wpf_submissions'
                         AND gm.meta_key   = 'gift_aid_opted_in'
                         AND gm.meta_value = '1'
                   )
                   AND tx.total_pence IS NOT NULL
                 GROUP BY s.id, s.customer_name, s.customer_email
                 ORDER BY s.created_at ASC",
                array_merge($extraParams, [$formId])
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        // Aggregate per unique donor email (HMRC requires aggregated donations).
        $donors = [];
        foreach ((array) $rows as $row) {
            $email = $row['customer_email'];
            if (!isset($donors[$email])) {
                $donors[$email] = [
                    'name'     => $row['customer_name'],
                    'address'  => $row['ga_address']   ?? '',
                    'address2' => $row['ga_address2']  ?? '',
                    'city'     => $row['ga_city']      ?? '',
                    'postcode' => $row['ga_postcode']  ?? '',
                    'total'    => 0,
                ];
            }
            $donors[$email]['total'] += (int) $row['total_pence'];
        }

        $filename = 'gift-aid-' . $formId . '-' . gmdate('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        $output = fopen('php://output', 'w');

        fputcsv($output, [
            'Title',
            'First Name',
            'Last Name',
            'House Name or Number',
            'Postcode',
            'Aggregated Donations',
            'Sponsored Walk etc',
            'Charity Name',
            'HMRC Reference',
        ]);

        foreach ($donors as $donor) {
            $nameParts = explode(' ', $donor['name'], 2);
            $firstName = $sanitizeCsvCell($nameParts[0] ?? '');
            $lastName  = $sanitizeCsvCell($nameParts[1] ?? '');

            $fullAddress = $donor['address'];
            if ($donor['address2'] !== '') {
                $fullAddress .= ', ' . $donor['address2'];
            }

            fputcsv($output, [
                '',
                $firstName,
                $lastName,
                $sanitizeCsvCell($fullAddress),
                $sanitizeCsvCell($donor['postcode']),
                number_format($donor['total'] / 100, 2, '.', ''),
                '',
                $charityName,
                $hmrcRef,
            ]);
        }

        exit;
    }
}
