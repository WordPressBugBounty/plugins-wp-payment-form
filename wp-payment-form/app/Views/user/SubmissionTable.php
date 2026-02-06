<?php
use WPPayForm\Framework\Support\Arr;

?>
<div class="wpf-user-dashboard-table">
    <div class="wpf-user-dashboard-table__header">
        <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('ID', 'wp-payment-form'); ?></div>
        <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Amount', 'wp-payment-form'); ?></div>
        <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Date', 'wp-payment-form'); ?></div>
        <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Status', 'wp-payment-form'); ?></div>
        <div class="wpf-user-dashboard-table__column"><?php esc_html_e('Payment Method', 'wp-payment-form'); ?></div>
    </div>
    <div class="wpf-user-dashboard-table__rows">
        <?php
        $wppayform_i = 0;
        foreach (Arr::get($donationItems, 'orders', []) as $wppayform_donation_key => $wppayform_donation_item):
            ?>
            <div class="wpf-user-dashboard-table__row">
                <div class="wpf-user-dashboard-table__column">
                    <span class="wpf-sub-id wpf_toal_amount_btn" data-modal_id="<?php echo esc_attr('wpf_toal_amount_modal' . $wppayform_i) ?>">
                        <?php echo esc_html(Arr::get($wppayform_donation_item, 'id', '')) ?> <span class="dashicons dashicons-visibility"></span>
                    </span>
                </div>
                <div class="wpf-user-dashboard-table__column">
                    <?php echo esc_html(Arr::get($wppayform_donation_item, 'payment_total', '')) ?>
                    <?php echo esc_html(Arr::get($wppayform_donation_item, 'currency', '')) ?>
                </div>
                <div class="wpf-user-dashboard-table__column">
                    <?php echo esc_html(Arr::get($wppayform_donation_item, 'created_at', '')) ?>
                </div>
                <div class="wpf-user-dashboard-table__column">
                    <span class="wpf-payment-status <?php echo esc_attr(Arr::get($wppayform_donation_item, 'payment_status', '')) ?>">
                        <?php echo esc_html(Arr::get($wppayform_donation_item, 'payment_status', '')) ?>
                    </span>
                </div>
                <div class="wpf-user-dashboard-table__column">
                    <?php echo esc_html(Arr::get($wppayform_donation_item, 'payment_method', '')) ?>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</div>