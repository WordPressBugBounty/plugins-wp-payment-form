<div class="wpf_payment_info">
    <div class="wpf_payment_info_item wpf_payment_info_item_order_id">
        <?php if ($submission->order_items) : ?>
            <div class="wpf_item_heading"><?php esc_html_e('Order ID:', 'wp-payment-form'); ?></div>
        <?php else : ?>
            <div class="wpf_item_heading"><?php esc_html_e('Submission ID:', 'wp-payment-form'); ?></div>
        <?php endif; ?>
        <div class="wpf_item_value">#<?php echo esc_html($submission->id); ?></div>
    </div>
    <div class="wpf_payment_info_item wpf_payment_info_item_date">
        <div class="wpf_item_heading"><?php esc_html_e('Date:', 'wp-payment-form'); ?></div>
        <div class="wpf_item_value"><?php echo esc_html(date(get_option('date_format'), strtotime($submission->created_at))); ?></div>
    </div>
    <?php if ($submission->payment_total) : ?>
        <?php
        $currencySetting = \WPPayForm\App\Services\GeneralSettings::getGlobalCurrencySettings();
        $currencySetting['currency_sign'] = \WPPayForm\App\Services\GeneralSettings::getCurrencySymbol($submission->currency);
        ?>
        <div class="wpf_payment_info_item wpf_payment_info_item_total">
            <div class="wpf_item_heading"><?php esc_html_e('Total:', 'wp-payment-form'); ?></div>
            <div class="wpf_item_value"><?php echo ($submission->payment_total > 0) ? esc_html(wpPayFormFormattedMoney($submission->payment_total, $currencySetting)) : 'pending'; ?></div>
        </div>
    <?php endif; ?>
    <?php if ($submission->payment_method) : 
         $paymentMethod = lcfirst(strtolower(esc_attr($submission->payment_method))); 
         $imageUrl = WPPAYFORM_URL . 'assets/images/gateways/' . $paymentMethod .'.svg';
        ?>
        <div class="wpf_payment_info_item wpf_payment_info_item_payment_method">
            <div class="wpf_item_heading"><?php esc_html_e('Payment Method:', 'wp-payment-form'); ?></div>
            <div class="wpf_item_value"><strong><?php echo ucfirst(esc_html($submission->payment_method)); ?></strong></div>
            <?php if (!empty($submission->transactions)  && count($submission->transactions) > 0): 
                 $transaction = $submission->transactions[0];
                 if (!empty($transaction->card_last_4)):
                ?>
                <span class="wpf_transactions_card_number">
                    <?php echo  '***'. esc_html($transaction->card_last_4); ?></span>  
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($submission->payment_status && $submission->order_items) : 
        $paymentStatus = lcfirst(strtolower(esc_attr($submission->payment_status))); 
        $imageUrl = WPPAYFORM_URL . 'assets/images/payment-status/' . $paymentStatus .'.svg';
        ?>
        <div class="wpf_payment_info_item wpf_payment_info_item_payment_status">
            <div class="wpf_item_heading"><?php esc_html_e('Payment Status:', 'wp-payment-form'); ?></div>
            <div class="wpf_item_content <?php echo esc_attr($paymentStatus) ?>">
                <div class="wpf_item_value"><?php echo esc_html($submission->payment_status); ?></div>
            </div>
        </div>
    <?php endif; ?>
</div>
