<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Mappers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * StatusMapper — maps GiveWP statuses to their Paymattic equivalents.
 *
 * GiveWP uses WordPress post_status values for donations and a custom
 * `status` column for subscriptions. Paymattic uses its own string set.
 *
 * Key quirk: GiveWP uses 'publish' (a standard WP post status) to mean
 * "completed/successful donation". This is NOT the same as a draft or
 * published page — it means the payment was accepted.
 *
 * GiveWP also has statuses (cancelled, abandoned, paused, etc.) that have
 * no direct Paymattic equivalent; these collapse to 'failed' or 'cancelled'.
 *
 * @see §14.1 (donation status mapping) and §14.2 (subscription status mapping)
 *      of the migration brief.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Mappers
 * @since   4.6.21
 */
class StatusMapper
{
    // -------------------------------------------------------------------------
    // Donation status map
    // -------------------------------------------------------------------------

    /**
     * Map of GiveWP donation post_status → Paymattic payment_status.
     *
     * @var array<string, string>
     */
    private static $donationStatusMap = [
        // ⚠️ 'publish' = completed in GiveWP, NOT a published post.
        'publish'          => 'paid',

        // GiveWP 'complete' is an alias used in some older versions.
        'complete'         => 'paid',

        // Standard WP/GiveWP statuses with direct equivalents.
        'pending'          => 'pending',
        'processing'       => 'processing',
        'refunded'         => 'refunded',
        'failed'           => 'failed',

        'cancelled'        => 'cancelled',

        // No 'abandoned' status in Paymattic — map to 'failed'.
        'abandoned'        => 'failed',

        // 'preapproval' = authorized but not yet captured — map to 'pending'.
        'preapproval'      => 'pending',

        // 'revoked' = chargeback/disputed — map to 'failed'.
        'revoked'          => 'failed',

        // Trashed records treated as failed (they exist but are deleted).
        'trash'            => 'failed',

        // Legacy GiveWP renewal type. These are renewal payment records
        // treated as successful — map to 'paid'.
        'give_subscription' => 'paid',
    ];

    // -------------------------------------------------------------------------
    // Subscription status map
    // -------------------------------------------------------------------------

    /**
     * Map of GiveWP subscription status → Paymattic subscription status.
     *
     * Paymattic supports: active, pending, completed, cancelled.
     * GiveWP adds: failing, suspended, paused, trashed, refunded, abandoned.
     *
     * @var array<string, string>
     */
    private static $subscriptionStatusMap = [
        'active'    => 'active',
        'pending'   => 'pending',

        // GiveWP 'expired' = all installments billed. Paymattic 'completed' is the closest.
        'expired'   => 'completed',
        'completed' => 'completed',

        // No 'failing' in Paymattic. The Stripe subscription is still active;
        // map to 'cancelled' as the closest administrative state.
        'failing'   => 'cancelled',

        'cancelled'  => 'cancelled',

        // No 'suspended' or 'paused' in Paymattic.
        'suspended'  => 'cancelled',
        'paused'     => 'cancelled',

        'trashed'    => 'cancelled',

        // Deprecated GiveWP statuses.
        'refunded'   => 'cancelled',
        'abandoned'  => 'cancelled',
    ];

    // -------------------------------------------------------------------------
    // Public methods
    // -------------------------------------------------------------------------

    /**
     * Map a GiveWP donation post_status to a Paymattic payment_status.
     *
     * Unknown statuses (custom or future GiveWP additions) fall back to 'pending'
     * so migrations are not blocked by unexpected values.
     *
     * @param string $giveStatus GiveWP post_status value.
     *
     * @return string Paymattic payment_status value.
     */
    public static function mapDonationStatus(string $giveStatus): string
    {
        $normalized = strtolower(trim($giveStatus));

        return isset(self::$donationStatusMap[$normalized])
            ? self::$donationStatusMap[$normalized]
            : 'pending';
    }

    /**
     * Map a GiveWP subscription status to a Paymattic subscription status.
     *
     * Unknown statuses fall back to 'pending' to avoid silently setting
     * active subscriptions to cancelled.
     *
     * @param string $giveStatus GiveWP subscription status value.
     *
     * @return string Paymattic subscription status value.
     */
    public static function mapSubscriptionStatus(string $giveStatus): string
    {
        $normalized = strtolower(trim($giveStatus));

        return isset(self::$subscriptionStatusMap[$normalized])
            ? self::$subscriptionStatusMap[$normalized]
            : 'pending';
    }

    /**
     * Check whether a GiveWP donation status maps to a lossy target.
     *
     * Returns true for statuses that have no precise Paymattic equivalent
     * (cancelled, abandoned, revoked, trash, preapproval) so callers can
     * include them in migration reports for admin review.
     *
     * @param string $giveStatus GiveWP post_status value.
     *
     * @return bool True when the mapping is approximate.
     */
    public static function isDonationStatusLossy(string $giveStatus): bool
    {
        $lossyStatuses = ['abandoned', 'revoked', 'trash', 'preapproval'];

        return in_array(strtolower(trim($giveStatus)), $lossyStatuses, true);
    }

    /**
     * Check whether a GiveWP subscription status maps to a lossy target.
     *
     * @param string $giveStatus GiveWP subscription status value.
     *
     * @return bool True when the mapping is approximate.
     */
    public static function isSubscriptionStatusLossy(string $giveStatus): bool
    {
        $lossyStatuses = ['failing', 'suspended', 'paused', 'trashed', 'refunded', 'abandoned', 'expired'];

        return in_array(strtolower(trim($giveStatus)), $lossyStatuses, true);
    }
}
