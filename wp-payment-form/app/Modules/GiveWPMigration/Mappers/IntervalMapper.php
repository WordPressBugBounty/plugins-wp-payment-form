<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Mappers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IntervalMapper — maps a GiveWP recurring period + frequency to a
 * Paymattic-compatible (billing_interval, interval_count) pair.
 *
 * GiveWP's give-recurring addon stores the recurrence as:
 *   - `period`    (string): 'day', 'week', 'fortnight', 'month', 'quarter',
 *                           'half_year', 'year'
 *   - `frequency` (int):    multiplier (every N periods, default 1)
 *
 * Paymattic's wpf_subscriptions.billing_interval only accepts flat strings:
 *   'day' | 'week' | 'month' | 'year'
 *
 * To preserve the true cadence (e.g. quarterly = every 3 months) the mapper
 * normalises everything to the nearest base unit and returns an
 * `interval_count` multiplier. The subscription writer stores this multiplier
 * in wpf_meta (and inside `original_plan` JSON) so future Paymattic features
 * can render and renew on the correct cadence.
 *
 * Examples:
 *   period=quarter,    frequency=1 → ('month', 3)   = every 3 months
 *   period=quarter,    frequency=2 → ('month', 6)   = every 6 months
 *   period=fortnight,  frequency=1 → ('week',  2)   = every 2 weeks
 *   period=half_year,  frequency=1 → ('month', 6)   = every 6 months
 *   period=month,      frequency=2 → ('month', 2)   = every 2 months
 *   period=year,       frequency=1 → ('year',  1)   = every year
 *
 * @see §12.2 (quarterly), §12.3 (frequency > 1) of the migration brief.
 * @see §13.3 (subscription mapping table) of the migration brief.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Mappers
 * @since   4.6.21
 */
class IntervalMapper
{
    /**
     * GiveWP period → [Paymattic base interval, base multiplier in that unit].
     *
     * Each entry is [base_interval, base_count_multiplier]. The final
     * interval_count = base_count_multiplier × frequency.
     *
     * @var array<string, array{0:string, 1:int}>
     */
    private static $periodMap = [
        'day'       => ['day',   1],
        'week'      => ['week',  1],
        'fortnight' => ['week',  2],   // every 2 weeks
        'month'     => ['month', 1],
        'quarter'   => ['month', 3],   // every 3 months
        'half_year' => ['month', 6],   // every 6 months
        'year'      => ['year',  1],
    ];

    /**
     * Map a GiveWP period + frequency combination to a Paymattic
     * (billing_interval, interval_count) pair.
     *
     * Returns:
     *   - 'billing_interval' (string): one of day/week/month/year — safe for
     *                                  Paymattic's existing schema and Stripe API.
     *   - 'interval_count'   (int):    how many of those units make up one
     *                                  billing cycle (1 = standard, > 1 = multi-unit).
     *   - 'is_lossy'         (bool):   true ONLY when the source period is unknown
     *                                  (cadence cannot be reconstructed). Quarterly,
     *                                  fortnightly, half-yearly, and frequency > 1
     *                                  are NOT lossy because the multiplier is
     *                                  preserved verbatim.
     *   - 'note'             (string): admin-facing description of the mapping.
     *
     * @param string $period    GiveWP `give_subscriptions.period` value.
     * @param int    $frequency GiveWP `give_subscriptions.frequency` value.
     *
     * @return array{billing_interval: string, interval_count: int, is_lossy: bool, note: string}
     */
    public static function mapInterval(string $period, int $frequency): array
    {
        $normalized = strtolower(trim($period));
        $frequency  = max(1, $frequency);

        // Unknown period — fall back to 'month, 1' and flag as lossy.
        if (!isset(self::$periodMap[$normalized])) {
            return [
                'billing_interval' => 'month',
                'interval_count'   => 1,
                'is_lossy'         => true,
                'note'             => sprintf(
                    /* translators: 1: GiveWP period value */
                    __('Unknown GiveWP period "%s" mapped to monthly — review required.', 'wp-payment-form'),
                    $period
                ),
            ];
        }

        [$baseInterval, $baseMultiplier] = self::$periodMap[$normalized];
        $intervalCount = $baseMultiplier * $frequency;

        // Note copy — describe the cadence preservation when the count > 1
        // so the migration report is informative without crying "lossy".
        $note = '';
        if ($intervalCount > 1) {
            $note = sprintf(
                /* translators: 1: GiveWP period name, 2: frequency multiplier, 3: resulting interval count, 4: Paymattic base interval */
                __('GiveWP "%1$s" (frequency %2$d) preserved as every %3$d %4$s in Paymattic. Cadence is stored on the subscription as interval_count = %3$d — gateway billing schedule is unchanged.', 'wp-payment-form'),
                $normalized,
                $frequency,
                $intervalCount,
                $baseInterval
            );
        }

        return [
            'billing_interval' => $baseInterval,
            'interval_count'   => $intervalCount,
            'is_lossy'         => false,
            'note'             => $note,
        ];
    }

    /**
     * Return all Paymattic-supported billing interval values.
     *
     * Useful for validation in the subscription writer.
     *
     * @return array<string>
     */
    public static function supportedIntervals(): array
    {
        return ['day', 'week', 'month', 'year'];
    }
}
