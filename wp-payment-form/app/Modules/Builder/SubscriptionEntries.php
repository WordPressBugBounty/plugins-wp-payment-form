<?php
namespace WPPayForm\App\Modules\Builder;

use WPPayForm\Framework\Support\Arr;

use WPPayForm\App\Services\GlobalTools;

if (!function_exists('wppayform_get_payment_status')) {
    function wppayform_get_payment_status($status) {
        $assetUrl = WPPAYFORM_URL . 'assets/images/payment-status';

        if(!empty($status)){
            return $assetUrl . '/' . strtolower($status) . '.svg';
        }
        return '';
    }
}

if (!function_exists('wppayform_get_payment_gateways')) {
    function wppayform_get_payment_gateways($gateways) {
        $assetUrl = WPPAYFORM_URL . 'assets/images/gateways';

        if (!empty($gateways)) {
            return $assetUrl . '/' . strtolower($gateways) . '.svg';
        }

        return '';
    }
}

class SubscriptionEntries
{
    public function render($subscriptionEntry, $subscriptionStatus, $formId, $submissionHash, $can_sync_subscription_billings, $isNotOfflinePayment, $planName)
    {
        if (getType($subscriptionEntry) == "object") {
            $subscriptionEntry = $subscriptionEntry->toArray();
        }
        ob_start();
        ?>
        <div class='wpf-user-dashboard-table'>
            <div class="wpf-user-table-title">
                <div>
                    <p style="margin: 0;font-size: 22px;font-weight: 500;color: #423b3b;">
                        <?php echo esc_html($planName) ?> - <?php esc_html_e('Billings', 'wp-payment-form') ?>.
                    </p>
                </div>
                <?php if ($can_sync_subscription_billings == 'yes' && $isNotOfflinePayment && $subscriptionStatus != 'cancelled'): ?>
                    <div class="wpf-sync-action">
                        <button class="wpf-sync-subscription-btn"
                                data-form_id="<?php echo esc_attr($formId); ?>"
                                data-submission_hash="<?php echo esc_attr($submissionHash); ?>"
                                aria-label="<?php esc_attr_e('Sync subscription billings', 'wp-payment-form'); ?>">
                            <span class="dashicons dashicons-update-alt" aria-hidden="true"></span>
                            <span class="sync-text"><?php esc_html_e('Sync', 'wp-payment-form'); ?></span>
                        </button>
                    </div>
                <?php endif ?>
            </div>
            <div class="wpf-table-container">
                <div class='wpf-user-dashboard-table__header'>
                    <div class='wpf-user-dashboard-table__column'><?php esc_html_e('ID', 'wp-payment-form') ?></div>
                    <div class='wpf-user-dashboard-table__column'><?php esc_html_e('Amount', 'wp-payment-form') ?></div>
                    <div class='wpf-user-dashboard-table__column'><?php esc_html_e('Date', 'wp-payment-form') ?></div>
                    <div class='wpf-user-dashboard-table__column'><?php esc_html_e('Status', 'wp-payment-form') ?></div>
                    <div class='wpf-user-dashboard-table__column'><?php esc_html_e('Payment Method test', 'wp-payment-form') ?></div>
                </div>
                <div class='wpf-user-dashboard-table__rows'>
                    <?php
                    foreach ($subscriptionEntry as $donationKey => $donationItem):
                        ?>
                        <div class='wpf-user-dashboard-table__row'>
                            <div class='wpf-user-dashboard-table__column'>
                                <span class='wpf-sub-id wpf_toal_amount_btn' style="color: black">
                                    <?php echo esc_html(Arr::get($donationItem, 'id', '')) ?>
                                </span>
                            </div>
                            <div class='wpf-user-dashboard-table__column'>
                                <?php echo esc_html(Arr::get($donationItem, 'payment_total', '')) / 100 ?>
                                <span style="text-transform: uppercase;"><?php echo esc_html(Arr::get($donationItem, 'currency', '')) ?></span>
                            </div>
                            <div class='wpf-user-dashboard-table__column'>
                                <?php echo esc_html(GlobalTools::convertStringToDate(Arr::get($donationItem, 'created_at', ''))) ?>
                            </div>
                            <div class='wpf-user-dashboard-table__column'>
                                <span class='wpf-payment-status <?php echo esc_attr(Arr::get($donationItem, 'status', ''))?>'>
                                    <img src="<?php echo esc_url(wppayform_get_payment_status(Arr::get($donationItem, 'status', ''))); ?>" alt="<?php echo esc_attr(Arr::get($donationItem, 'status', '')); ?>">
                                    <?php echo esc_html(Arr::get($donationItem, 'status', '')) ?>
                                </span>
                            </div>
                            <div class='wpf-user-dashboard-table__column'>
                                <!-- <?php echo esc_html(ucfirst(Arr::get($donationItem, 'payment_method', ''))) ?> -->
                                <img src="<?php echo esc_url(wppayform_get_payment_gateways(Arr::get($donationItem, 'payment_method', ''))); ?>" alt="<?php echo esc_attr(Arr::get($donationItem, 'payment_method', '')); ?>">
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
        <?php
        $view = ob_get_clean();
        return $view;
    }
}
?>