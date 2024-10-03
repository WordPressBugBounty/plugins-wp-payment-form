<?php
namespace WPPayForm\App\Modules\Builder;

use WPPayForm\Framework\Support\Arr;

use WPPayForm\App\Services\GlobalTools;

function getPaymentStatus($status) {
    $assetUrl = WPPAYFORM_URL . 'assets/images/payment-status';

    if(!empty($status)){
        return $assetUrl . '/' . strtolower($status) . '.svg';
    }
    return '';
}

function getPaymentGateways($gateways) {
    $assetUrl = WPPAYFORM_URL . 'assets/images/gateways';

    if (!empty($gateways)) {
        return $assetUrl . '/' . strtolower($gateways) . '.svg';
    }

    return '';
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
                        <?php echo esc_html($planName) ?> - Billings.
                    </p>
                </div>
                <?php if ($can_sync_subscription_billings == 'yes' && $isNotOfflinePayment && $subscriptionStatus != 'cancelled'): ?>
                    <div class="wpf-sync-action">
                        <span class="dashicons dashicons-update-alt"></span>
                        <button class="wpf-sync-subscription-btn" data-form_id="<?php echo esc_attr($formId) ?>"
                            data-submission_hash="<?php echo esc_attr($submissionHash) ?>">Sync</button>
                    </div>
                <?php endif ?>
            </div>
            <div class="wpf-table-container">
                <div class='wpf-user-dashboard-table__header'>
                    <div class='wpf-user-dashboard-table__column'>ID</div>
                    <div class='wpf-user-dashboard-table__column'>Amount</div>
                    <div class='wpf-user-dashboard-table__column'>Date</div>
                    <div class='wpf-user-dashboard-table__column'>Status</div>
                    <div class='wpf-user-dashboard-table__column'>Payment Method</div>
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
                                    <img src="<?php echo esc_url(getPaymentStatus(Arr::get($donationItem, 'status', ''))); ?>" alt="<?php echo esc_attr(Arr::get($donationItem, 'status', '')); ?>">
                                    <?php echo esc_html(Arr::get($donationItem, 'status', '')) ?>
                                </span>
                            </div>
                            <div class='wpf-user-dashboard-table__column'>
                                <!-- <?php echo esc_html(ucfirst(Arr::get($donationItem, 'payment_method', ''))) ?> -->
                                <img src="<?php echo esc_html(getPaymentGateways(Arr::get($donationItem, 'payment_method', ''))); ?>" alt="<?php echo esc_attr(Arr::get($donationItem, 'payment_method', '')); ?>">
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