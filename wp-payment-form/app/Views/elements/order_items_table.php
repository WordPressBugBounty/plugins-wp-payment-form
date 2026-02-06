<?php
if (!$submission->order_items) {
    return '';
}

$wppayform_currency_setting = \WPPayForm\App\Services\GeneralSettings::getGlobalCurrencySettings($submission->form_id);
$wppayform_currency_setting['currency_sign'] = \WPPayForm\App\Services\GeneralSettings::getCurrencySymbol($submission->currency);
?>
<div class="wpf_order_items_table_wrapper">
    <table class="table wpf_order_items_table wpf_table table_bordered">
        <thead>
            <th>
                <?php esc_html_e('Item', 'wp-payment-form'); ?>
            </th>
            <th>
                <?php esc_html_e('Quantity', 'wp-payment-form'); ?>
            </th>
            <th>
                <?php esc_html_e('Price', 'wp-payment-form'); ?>
            </th>

            <th>
                <?php esc_html_e('Line Total', 'wp-payment-form'); ?>
            </th>
            <?php if ($submission->tax_items->count()): ?>
                <th>
                    <?php esc_html_e('Tax Total', 'wp-payment-form'); ?>
                </th>
                <th>
                    <?php esc_html_e('Gross Total', 'wp-payment-form'); ?>
                </th>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php $wppayform_sub_total = 0; ?>
            <?php foreach ($submission->order_items as $wppayform_order_item) {

                if (is_array($wppayform_order_item)) {
                    if ($wppayform_order_item['line_total']): ?>
                        <?php $wppayform_tax_total = 0; ?>
                        <tr>
                            <td style="text-align:center">
                                <?php echo esc_html($wppayform_order_item['item_name']); ?>
                            </td>
                            <td style="text-align:center">
                                <?php echo esc_html($wppayform_order_item['quantity']); ?>
                            </td>
                            <td style="text-align:center">
                                <?php echo esc_html(wpPayFormFormattedMoney($wppayform_order_item['item_price'], $wppayform_currency_setting)); ?>
                            </td style="text-align:center">
                            <td style="text-align:center">
                                <?php echo esc_html(wpPayFormFormattedMoney($wppayform_order_item['line_total'], $wppayform_currency_setting)); ?>
                            </td>
                            <?php foreach ($submission->tax_items as $wppayform_tax_item) : ?>
                                <?php
                                if ($wppayform_tax_item['parent_holder'] == $wppayform_order_item['parent_holder']) {
                                    $wppayform_tax_total += $wppayform_tax_item->line_total;
                                } ?>
                            <?php endforeach; ?>

                            <?php if ($wppayform_tax_total) : ?>
                                <td style="text-align:center">
                                    <?php echo esc_html(wpPayFormFormattedMoney($wppayform_tax_total, $wppayform_currency_setting)); ?>
                                </td>
                                <td style="text-align:center">
                                    <?php echo esc_html(wpPayFormFormattedMoney($wppayform_tax_total + $wppayform_order_item['line_total'], $wppayform_currency_setting)); ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                        $wppayform_sub_total += $wppayform_order_item['line_total'];
                    endif;
                } else {
                    if ($wppayform_order_item->line_total): ?>
                        <?php $wppayform_tax_total = 0; ?>
                        <tr>
                            <td style="text-align:center">
                                <?php echo esc_html($wppayform_order_item->item_name); ?>
                            </td>
                            <td style="text-align:center">
                                <?php echo esc_html($wppayform_order_item->quantity); ?>
                            </td>
                            <td style="text-align:center">
                                <?php echo esc_html(wpPayFormFormattedMoney($wppayform_order_item->item_price, $wppayform_currency_setting)); ?>
                            </td>
                            <td style="text-align:center">
                                <?php echo esc_html(wpPayFormFormattedMoney($wppayform_order_item->line_total, $wppayform_currency_setting)); ?>
                            </td>
                            <?php foreach ($submission->tax_items as $wppayform_tax_item) : ?>
                                <?php
                                if (isset($wppayform_tax['parent_holder']) && isset($wppayform_item['parent_holder']) && $wppayform_tax_item->parent_holder === $wppayform_order_item->parent_holder) {
                                    $wppayform_tax_total += $wppayform_tax_item->line_total;
                                } ?>
                            <?php endforeach; ?>

                            <?php if ($wppayform_tax_total) : ?>
                                <td style="text-align:center">
                                    <?php echo esc_html(wpPayFormFormattedMoney($wppayform_tax_total, $wppayform_currency_setting)); ?>
                                </td>
                                <td style="text-align:center">
                                    <?php echo esc_html(wpPayFormFormattedMoney($wppayform_tax_total + $wppayform_order_item->line_total, $wppayform_currency_setting)); ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                        $wppayform_sub_total += $wppayform_order_item->line_total;
                    endif;
                }
            }
            ;
            ?>
        </tbody>
        <tfoot>
            <?php $wppayform_discount_total = 0;
            if (isset($submission->discounts['applied']) && count($submission->discounts['applied'])): ?>
                <tr class="wpf_total_row">
                    <th style="text-align: right" colspan="3">
                        <?php esc_html__('Sub-Total', 'wp-payment-form'); ?>
                    </th>
                    <td>
                        <?php echo esc_html(wpPayFormFormattedMoney($wppayform_sub_total, $wppayform_currency_setting)); ?>
                    </td>
                </tr>
                <?php
                foreach ($submission->discounts['applied'] as $wppayform_discount):
                    $wppayform_discount_total += intval($wppayform_discount->line_total);
                    ?>
                    <tr class="wpf_discount_row">
                        <th style="text-align: right" colspan="3">
                            <?php echo esc_html__('Discounts', 'wp-payment-form') . ' (' . esc_html($wppayform_discount->item_name) . ')'; ?>
                        </th>
                        <td>
                            <?php echo '-' . esc_html(wpPayFormFormattedMoney($wppayform_discount->line_total, $wppayform_currency_setting)); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($submission->tax_items->count()): ?>
                <tr class="wpf_sub_total_row">
                    <th style="text-align: right" colspan="5">
                        <?php esc_html__('Sub Total', 'wp-payment-form'); ?>
                    </th>
                    <td>
                        <?php echo esc_html(wpPayFormFormattedMoney($wppayform_sub_total - $wppayform_discount_total, $wppayform_currency_setting)); ?>
                    </td>
                </tr>
                <?php foreach ($submission->tax_items as $wppayform_tax_item): ?>
                    <tr class="wpf_sub_total_row">
                        <td style="text-align: right" colspan="5">
                            <?php echo esc_html($wppayform_tax_item->item_name); ?>
                        </td>
                        <td>
                            <?php echo esc_html(wpPayFormFormattedMoney($wppayform_tax_item->line_total, $wppayform_currency_setting)); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            <tr class="wpf_total_payment_row">
                <th style="text-align: right"
                    colspan="
                        <?php if ($submission->tax_items->count()): ?>
                               <?php echo '5' ?>
                        <?php else: ?>
                            <?php echo '3' ?>
                        <?php endif  ?>
                    ">
                    <?php esc_html__('Total', 'wp-payment-form'); ?>
                </th>
                <td>
                    <?php echo esc_html(wpPayFormFormattedMoney(intval($submission->payment_total), $wppayform_currency_setting)); ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>

<?php
if ($submission->payment_method === 'paypal' && $submission->payment_status == 'pending') { ?>
    <div style="background: #f7fafc; border: 1px solid #cac8c8; padding: 10px; font-size:13px; margin-bottom: 12px;">
        <h3><?php esc_html_e('Payment is not marked as paid yet.', 'wp-payment-form') ?></h3>
        <?php esc_html_e('Sometimes, PayPal payments take a few moments to mark as paid! Try reloading receipt page after sometime.', 'wp-payment-form') ?>
        <div class="wpf_pending-loader">
            <div class="spinner"></div>
            <div class="countdown"></div>
        </div>
    </div>
<?php } ?>
