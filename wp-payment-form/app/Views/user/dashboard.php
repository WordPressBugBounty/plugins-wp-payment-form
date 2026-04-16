<?php

if ( ! defined( 'ABSPATH' ) ) exit;

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Services\GlobalTools;

$current_user = wp_get_current_user();
$wppayform_user_email = '';
$wppayform_user_name = '';
$wppayform_user_from = '';
if ($current_user) {
    $wppayform_user_email = $current_user->data->user_email;
    $wppayform_user_name = $current_user->data->display_name;
    $wppayform_user_from = $current_user->data->user_registered;
    $wppayform_date_time = new DateTime($wppayform_user_from);
    // Get the user from date with Day Month Year format
    $wppayform_user_from = $wppayform_date_time->format('l F Y');
}

$wppayform_read_entry = Arr::get($permissions, 'read_entry');
$wppayform_read_subscription_entry = Arr::get($permissions, 'read_subscription_entry');
$wppayform_can_sync_subscription_billings = Arr::get($permissions, 'can_sync_subscription_billings');
$wppayform_cancel_subscription = Arr::get($permissions, 'cancel_subscription');

if (!function_exists('wppayform_get_payment_status')) {
    function wppayform_get_payment_status($status) {
        $wppayform_asset_url = WPPAYFORM_URL . 'assets/images/payment-status';

        if(!empty($status)){
            return $wppayform_asset_url . '/' . strtolower($status) . '.svg';
        }
        return '';
    }
}

if (!function_exists('wppayform_get_payment_gateways')) {
    function wppayform_get_payment_gateways($gateways) {
        $wppayform_asset_url = WPPAYFORM_URL . 'assets/images/gateways';

        if (!empty($gateways)) {
            return $wppayform_asset_url . '/' . strtolower($gateways) . '.svg';
        }

        return '';
    }
}

if (!function_exists('wppayform_get_menu_icon')) {
    function wppayform_get_menu_icon($icon) {
        $wppayform_asset_url = WPPAYFORM_URL . 'assets/images/menu-icon';
        if (!empty($icon)) {
            return $wppayform_asset_url . '/' . strtolower($icon) . '.svg';
        }

        return '';
    }
}


?>

<div class="wpf-user-dashboard">
    <div class="wpf-user-profile">
        <div class="wpf-user-avatar">
            <?php echo get_avatar($wppayform_user_email, 75); ?>
        </div>
        <div class="wpf-user-info">
            <div class="wpf-user-name">
                <h2><?php echo esc_html(ucfirst($wppayform_user_name)) ?></h2>
            </div>
            <div class="wpf-sub-info">
                <div class="wpf-info-item">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <span><?php echo esc_html($wppayform_user_email) ?></span>
                </div>
                <div class="wpf-info-item">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="2"/></svg>
                    <span><?php echo esc_html__('Member since', 'wp-payment-form') ?> <?php echo esc_html($wppayform_user_from) ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php if ($wppayform_read_entry == 'yes' || $wppayform_read_subscription_entry == 'yes') { ?>
        <div class="wpf-user-content">
            <button class="wpf-mobile-menu-toggle" id="wpf-mobile-menu-toggle" aria-label="<?php echo esc_attr__('Toggle menu', 'wp-payment-form'); ?>" aria-expanded="false">
                <svg class="wpf-menu-icon-open" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                <svg class="wpf-menu-icon-close" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <div class="wpf-menu" id="wpf-sidebar-menu">
                <?php
                foreach ($menus as $menu) {
                    ?>
                    <div class="wpf-menu-item" id="<?php echo esc_attr($menu['slug']); ?>">
                        <img src="<?php echo esc_attr(wppayform_get_menu_icon($menu['slug'])); ?>" />
                        <span class="wpf-menu-name" data-translate="<?php echo esc_attr($menu['name']); ?>"><?php echo esc_html($menu['name']); ?></span>
                    </div>
                    <?php
                }
                ?>
                <div class="wpf-logout-btn" id="wpf-logout">
                    <span class="dashicons dashicons-upload"></span>
                    <a href="<?php echo esc_url(wp_logout_url()); ?>"><?php echo esc_html__('Logout', 'wp-payment-form'); ?></a>
                </div>
            </div>
            <div class="wpf-content wpf-dashboard" id="content-wpf-user-dashboard">
                <div class="wpf-user-stats wpf-dashboard-card">
                    <div class="wpf-stats-head">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chart-column"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                        <span><?php echo esc_html__('Your Submission Stats', 'wp-payment-form'); ?></span>
                    </div>
                    <div class="wpf-stats-card">
                        <div class="overview-card">
                            <div id="wpf_toal_amount_modal" class="wpf-dashboard-modal">
                                <!-- Modal content -->
                                <div class="modal-content max-width-340">
                                    <span class="wpf-close">&times;</span>
                                    <?php foreach ($payment_total as $wppayform_total_key => $wppayform_payment_total_amount): ?>
                                        <p>
                                            <?php echo esc_html($wppayform_payment_total_amount / 100) ?>
                                            <?php echo esc_html($wppayform_total_key) ?>
                                        </p>
                                    <?php endforeach ?>
                                </div>
                            </div>
                            <div data-v-5e7a3b24="" class="icon">
                                <img class="spent" src="<?php echo esc_attr(WPPAYFORM_URL . "assets/images/dashboard/spent.svg") ?>"
                                    alt="total-spent" />
                            </div>
                            <div class="info">
                                <span data-v-5e7a3b24=""> <?php echo esc_html__('Total Spend', 'wp-payment-form') ?></span>
                                <h4 class="h4">
                                    <?php echo esc_html(Arr::get(array_values($payment_total), '0')/ 100) ?>
                                    <?php echo esc_html(key($payment_total)); ?>
                                </h4>
                                <!-- <p class="wpf_toal_amount_btn" data-modal_id="wpf_toal_amount_modal">Expend All</p> -->
                                <!-- <span data-v-5e7a3b24=""> <?php echo esc_html__('Total Spend', 'wp-payment-form') ?></span> -->
                            </div>
                        </div>
                        <?php if ($wppayform_read_entry == 'yes'): ?>
                            <div class="overview-card">
                                <div data-v-5e7a3b24="" class="icon">
                                    <img class="order" src="<?php echo esc_attr(WPPAYFORM_URL . "assets/images/dashboard/order.svg") ?>"
                                        alt="order" />
                                </div>
                                <div class="info">
                                    <span data-v-5e7a3b24=""><?php echo esc_html__('Total Orders', 'wp-payment-form') ?></span>
                                    <h4 class="h4">
                                        <?php echo esc_html(count(Arr::get($donationItems, 'orders', []))) ?>
                                    </h4>
                                </div>
                            </div>
                        <?php endif ?>
                        <?php if ($wppayform_read_subscription_entry == 'yes'): ?>
                            <div class="overview-card">
                                <div data-v-5e7a3b24="" class="icon">
                                    <img class="subscription"
                                        src="<?php echo esc_attr(WPPAYFORM_URL . "assets/images/dashboard/subscription.svg") ?>"
                                        alt="subscription" />
                                </div>
                                <div class="info">
                                    <span data-v-5e7a3b24=""><?php echo esc_html__('Total Subscription', 'wp-payment-form') ?></span>
                                    <h4 class="h4">
                                        <?php echo esc_html(count(Arr::get($donationItems, 'subscriptions', []))) ?>
                                    </h4>
                                </div>
                            </div>
                        <?php endif ?>
                    </div>
                </div>
                <div class="wpf-submission-table wpf-dashboard-card">
                    <div class="wpf-submission-head">
                        <div class="wpf-submission-head__title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                            <span class="wpf-submission-head-title"><?php echo esc_html__('Your Submissions', 'wp-payment-form'); ?></span>
                        </div>
                        <div class="wpf-payment-filter-wrap">
                            <div class="wpf-custom-select" id="wpf-payment-status-select">
                                <button class="wpf-custom-select__trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="wpf-custom-select__label"><?php echo esc_html__('All', 'wp-payment-form'); ?></span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </button>
                                <ul class="wpf-custom-select__menu" role="listbox">
                                    <li class="wpf-custom-select__item active" data-filter="all" role="option" aria-selected="true">
                                        <span><?php echo esc_html__('All', 'wp-payment-form'); ?></span>
                                        <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    </li>
                                    <li class="wpf-custom-select__item" data-filter="paid" role="option" aria-selected="false">
                                        <span><?php echo esc_html__('Paid', 'wp-payment-form'); ?></span>
                                        <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    </li>
                                    <li class="wpf-custom-select__item" data-filter="pending" role="option" aria-selected="false">
                                        <span><?php echo esc_html__('Pending', 'wp-payment-form'); ?></span>
                                        <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    </li>
                                    <li class="wpf-custom-select__item" data-filter="failed" role="option" aria-selected="false">
                                        <span><?php echo esc_html__('Failed', 'wp-payment-form'); ?></span>
                                        <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    </li>
                                    <li class="wpf-custom-select__item" data-filter="refunded" role="option" aria-selected="false">
                                        <span><?php echo esc_html__('Refunded', 'wp-payment-form'); ?></span>
                                        <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="wpf-user-dashboard-table">
                        <div class="wpf-user-dashboard-loader"></div>
                        <div class="wpf-table-scroll-wrapper">
                            <div class="wpf-user-dashboard-table__header">
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('ID', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Amount', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Date', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Status', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Gateway', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Invoice', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Action', 'wp-payment-form') ?></div>
                            </div>
                            <div class="wpf-user-dashboard-table__rows">
                                <div class="wpf-table-empty-state" style="display:none;">
                                    <span class="wpf-empty-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/><path d="M11 8v6M8 11h6"/></svg>
                                    </span>
                                    <p class="wpf-empty-state-msg"><?php echo esc_html__('You have no payments yet.', 'wp-payment-form'); ?></p>
                                </div>
                                <?php
                                $wppayform_i = 0;
                                foreach (Arr::get($donationItems, 'entries', []) as $wppayform_donation_index => $wppayform_donation_item):
                                    $wppayform_payment_total = Arr::get($wppayform_donation_item, 'payment_total', 0);
                                    $wppayform_i++;
                                    ?>
                                    <div class=" wpf-user-dashboard-table__row" data-payment-status="<?php echo esc_attr(Arr::get($wppayform_donation_item, 'payment_status', '')); ?>">
                                        <div id="<?php echo esc_attr('wpf_toal_amount_modal' . $wppayform_i) ?>" class="wpf-dashboard-modal">
                                            <!-- Modal content -->
                                            <div class="modal-content">
                                                <div class="submission-modal">
                                                    <span class="wpf-close">&times;</span>
                                                    <?php
                                                    $wppayform_receipt_handler = new \WPPayForm\App\Modules\Builder\PaymentReceipt();
                                                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                    echo $wppayform_receipt_handler->render($wppayform_donation_item['id']);
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class=" wpf-user-dashboard-table__column">
                                            #
                                            <?php echo esc_html(Arr::get($wppayform_donation_item, 'id', '')) ?>
                                        </div>
                                        <div class=" wpf-user-dashboard-table__column">
                                            <?php echo esc_html($wppayform_payment_total / 100) ?>
                                            <?php echo esc_html(Arr::get($wppayform_donation_item, 'currency', '')) ?>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                            <?php echo esc_html(GlobalTools::convertStringToDate(Arr::get($wppayform_donation_item, 'created_at', ''))) ?>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                            <span
                                                class="wpf-payment-status <?php echo esc_attr(Arr::get($wppayform_donation_item, 'payment_status', '')) ?>">
                                                <img src="<?php echo esc_url(wppayform_get_payment_status(Arr::get($wppayform_donation_item, 'payment_status', ''))); ?>" alt="<?php echo esc_attr(Arr::get($wppayform_donation_item, 'payment_status', '')); ?>">
                                                <?php echo esc_html(Arr::get($wppayform_donation_item, 'payment_status', '')) ?>
                                            </span>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                            <?php
                                            $wppayform_payment_method = Arr::get($wppayform_donation_item, 'payment_method', '');
                                            if (!empty($wppayform_payment_method)):
                                            ?>
                                                <img src="<?php echo esc_url(wppayform_get_payment_gateways($wppayform_payment_method)); ?>" alt="<?php echo esc_attr($wppayform_payment_method); ?>">
                                            <?php else: ?>
                                                <span class="wpf-no-gateway"><?php echo esc_html__('N/A', 'wp-payment-form'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                        <?php
                                            // Ensure we have the correct data structure for the filter
                                            $invoice_item = is_array($wppayform_donation_item) ? $wppayform_donation_item : (array) $wppayform_donation_item;
                                            // Ensure id and form_id are present
                                            if (!isset($invoice_item['id']) && isset($invoice_item['ID'])) {
                                                $invoice_item['id'] = $invoice_item['ID'];
                                            }
                                            if (!isset($invoice_item['form_id']) && isset($invoice_item['formId'])) {
                                                $invoice_item['form_id'] = $invoice_item['formId'];
                                            }
                                            
                                            $wppayform_invoice_url = apply_filters('wppayform_dashboard_entry_invoice_url', '', $invoice_item);
                                            if ( ! empty($wppayform_invoice_url) ):
                                                ?>
                                            <a href="<?php echo esc_url($wppayform_invoice_url); ?>" 
                                            class="wpf-dashboard-download-invoice wpf-icon-button" 
                                            target="_blank" 
                                            rel="noopener"
                                            title="<?php echo esc_attr__('Download Invoice', 'wp-payment-form'); ?>"
                                            aria-label="<?php echo esc_attr__('Download Invoice', 'wp-payment-form'); ?>">
                                                <span class="wpf-icon-svg wpf-icon-download" aria-hidden="true"><?php echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'; ?></span>
                                            </a>
                                            <?php else: ?>
                                            <span class="wpf-icon-button wpf-icon-disabled" 
                                                title="<?php echo esc_attr__('Invoice not available', 'wp-payment-form'); ?>"
                                                aria-label="<?php echo esc_attr__('Invoice not available', 'wp-payment-form'); ?>">
                                                <span class="wpf-icon-svg wpf-icon-document" aria-hidden="true"><?php echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'; ?></span>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column wpf-user-dashboard-last_column">
                                            <span class="wpf-sub-id wpf_toal_amount_btn wpf-icon-button"
                                                data-modal_id="<?php echo esc_attr('wpf_toal_amount_modal' . $wppayform_i) ?>"
                                                title="<?php echo esc_attr__('View Receipt', 'wp-payment-form'); ?>"
                                                aria-label="<?php echo esc_attr__('View Receipt', 'wp-payment-form'); ?>">
                                                <span class="wpf-icon-svg wpf-icon-eye" aria-hidden="true"><?php echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>'; ?></span>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </div><!-- /.wpf-table-scroll-wrapper -->
                    </div>
                </div>
            </div>
            <!-- <div class="wpf-content" id="wpf-donor-history">Donor history</div> -->
            <div class="wpf-content wpf-dashboard" id="content-wpf-subscription">
                    <div class="wpf-submission-table wpf-dashboard-card">
                        <div class="wpf-submission-head">
                            <div class="wpf-submission-head__title">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                                <span><?php echo esc_html__('Your Subscription', 'wp-payment-form') ?></span>
                            </div>
                            <div class="wpf-subscription-filter-wrap">
                                <div class="wpf-custom-select" id="wpf-subscription-status-select">
                                    <button class="wpf-custom-select__trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                        <span class="wpf-custom-select__label"><?php echo esc_html__('All', 'wp-payment-form'); ?></span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                    </button>
                                    <ul class="wpf-custom-select__menu" role="listbox">
                                        <li class="wpf-custom-select__item active" data-filter="all" role="option" aria-selected="true">
                                            <span><?php echo esc_html__('All', 'wp-payment-form'); ?></span>
                                            <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        </li>
                                        <li class="wpf-custom-select__item" data-filter="active" role="option" aria-selected="false">
                                            <span><?php echo esc_html__('Active', 'wp-payment-form'); ?></span>
                                            <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        </li>
                                        <li class="wpf-custom-select__item" data-filter="cancelled" role="option" aria-selected="false">
                                            <span><?php echo esc_html__('Cancelled', 'wp-payment-form'); ?></span>
                                            <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        </li>
                                        <li class="wpf-custom-select__item" data-filter="failed" role="option" aria-selected="false">
                                            <span><?php echo esc_html__('Failed', 'wp-payment-form'); ?></span>
                                            <svg class="wpf-check-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="wpf-user-dashboard-table">
                            <div class="wpf-user-dashboard-loader"></div>
                            <div class="wpf-table-scroll-wrapper">
                            <div class="wpf-user-dashboard-table__header">
                                <div style="flex: 2" class="wpf-user-dashboard-table__column"><?php echo esc_html__('Plan', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Billing Time', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Status', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Interval', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Invoice', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column" style="text-align: right;" ><?php echo esc_html__('Action', 'wp-payment-form') ?></div>
                            </div>
                            <div class="wpf-user-dashboard-table__rows wpf-subscription-rows">
                                <div class="wpf-table-empty-state" style="display:none;">
                                    <span class="wpf-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg></span>
                                    <p class="wpf-empty-state-msg"><?php echo esc_html__('You have no subscriptions yet.', 'wp-payment-form'); ?></p>
                                </div>
                                <?php
                                $wppayform_i = 1000;
                                foreach (Arr::get($donationItems, 'subscriptions', []) as $wppayform_donation_key => $wppayform_donation_item):
                                    $wppayform_i++;
                                    $wppayform_sub_status_attr = strtolower((string) Arr::get($wppayform_donation_item, 'status', ''));
                                    ?>
                                    <div class=" wpf-user-dashboard-table__row" data-subscription-status="<?php echo esc_attr($wppayform_sub_status_attr); ?>">
                                        <div id="<?php echo esc_attr('wpf_toal_amount_modal' . $wppayform_i) ?>" class="wpf-dashboard-modal">
                                            <!-- Modal content -->
                                            <div class="modal-content">
                                                <div class="submission-modal">
                                                    <span class="wpf-close">&times;</span>
                                                    <div class="wpf-user-dashboard-table-container" style="padding-top: 28px">
                                                        <?php
                                                        $wppayform_receipt_handler = new \WPPayForm\App\Modules\Builder\SubscriptionEntries();
                                                        $wppayform_payment_method = Arr::get($wppayform_donation_item, 'submission.submission.payment_method', '');
                                                        $wppayform_is_not_offline_payment = $wppayform_payment_method != 'offline';
                                                        $wppayform_cancellable_sub = $wppayform_cancel_subscription == 'yes' && in_array($wppayform_payment_method, array('stripe', 'square', 'paypal', 'authorizedotnet', 'xendit'), true);
                                                        $wppayform_plan_name = Arr::get($wppayform_donation_item, 'plan_name', '');
                                                        $wppayform_submission_hash = Arr::get($wppayform_donation_item, 'submission.submission.submission_hash', '');
                                                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                        echo $wppayform_receipt_handler->render(
                                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                            Arr::get($wppayform_donation_item, 'related_payments', []),
                                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                            Arr::get($wppayform_donation_item, 'status', 'active'),
                                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                            Arr::get($wppayform_donation_item, 'form_id'),
                                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                            Arr::get($wppayform_donation_item, 'submission.submission.submission_hash', ''),
                                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                            $wppayform_can_sync_subscription_billings,
                                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                            $wppayform_is_not_offline_payment,
                                                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                                            $wppayform_plan_name
                                                        );
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div style=" flex: 2" class="wpf-user-dashboard-table__column">
                                            <?php echo esc_html($wppayform_plan_name) ?>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                            <?php echo esc_html(Arr::get($wppayform_donation_item, 'bill_times', '') == 0 ? 'Infinity' : Arr::get($wppayform_donation_item, 'bill_times', '')) ?>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                            <span class="wpf-payment-status <?php echo esc_attr(Arr::get($wppayform_donation_item, 'status', '')) ?>">
                                            <img src="<?php echo esc_url(wppayform_get_payment_status(Arr::get($wppayform_donation_item, 'status', ''))); ?>" alt="<?php echo esc_attr(Arr::get($wppayform_donation_item, 'status', '')); ?>">
                                                <?php echo esc_html(Arr::get($wppayform_donation_item, 'status', '')) ?>
                                            </span>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                            <?php echo esc_html(Arr::get($wppayform_donation_item, 'billing_interval', '')) ?>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                        <?php
                                            $wppayform_sub_invoice_item = [
                                                'id' => Arr::get($wppayform_donation_item, 'submission_id'),
                                                'form_id' => Arr::get($wppayform_donation_item, 'form_id'),
                                            ];
                                            $wppayform_sub_invoice_url = apply_filters('wppayform_dashboard_entry_invoice_url', '', $wppayform_sub_invoice_item);
                                            if ( ! empty($wppayform_sub_invoice_url) ):
                                                ?>
                                            <a href="<?php echo esc_url($wppayform_sub_invoice_url); ?>" 
                                                class="wpf-dashboard-download-invoice wpf-icon-button" 
                                                target="_blank" 
                                                rel="noopener"
                                                title="<?php echo esc_attr__('Download Invoice', 'wp-payment-form'); ?>"
                                                aria-label="<?php echo esc_attr__('Download Invoice', 'wp-payment-form'); ?>">
                                                <span class="wpf-icon-svg wpf-icon-download" aria-hidden="true"><?php echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'; ?></span>
                                            </a>
                                            <?php else: ?>
                                            <span class="wpf-icon-button wpf-icon-disabled" 
                                                  title="<?php echo esc_attr__('Invoice not available', 'wp-payment-form'); ?>"
                                                  aria-label="<?php echo esc_attr__('Invoice not available', 'wp-payment-form'); ?>">
                                                <span class="wpf-icon-svg wpf-icon-document" aria-hidden="true"><?php echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>'; ?></span>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="wpf-user-dashboard-table__column">
                                            <div id="<?php echo esc_attr('wpf_toal_amount_cancel_modal' . $wppayform_i) ?>"
                                                class="wpf-dashboard-modal wpf-confirmation-modal">
                                                <!-- Modal content -->
                                                <div class="modal-content">
                                                    <div class="modal-title">
                                                        <p class="title">
                                                            <?php echo esc_html__('Confirm subscription cancellation', 'wp-payment-form'); ?>
                                                        </p>
                                                        <span class="wpf-close" aria-label="<?php echo esc_attr__('Close', 'wp-payment-form'); ?>">&times;</span>
                                                    </div>

                                                    <div class="modal-body">
                                                        <span class="dashicons dashicons-info-outline wpf-info-icon" aria-hidden="true"></span>
                                                        <h4>
                                                            <?php echo esc_html__('Are you sure you want to cancel this subscription?', 'wp-payment-form'); ?>
                                                        </h4>
                                                        <p>
                                                            <?php echo esc_html__('This will also cancel the subscription in your', 'wp-payment-form'); ?>
                                                            <?php echo esc_html(Arr::get($wppayform_donation_item, 'submission.submission.payment_method', '')); ?>
                                                            <?php echo esc_html__('account, so no further automatic payments will be processed.', 'wp-payment-form'); ?>
                                                        </p>
                                                    </div>

                                                    <div class="modal-footer">
                                                        <button type="button" class="modal-btn wpf-cancel">
                                                            <?php echo esc_html__('Keep Subscription', 'wp-payment-form'); ?>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="modal-btn wpf-success wpf-confirm-subscription-cancel"
                                                            data-form_id="<?php echo esc_attr($wppayform_donation_item['form_id']); ?>"
                                                            data-submission_hash="<?php echo esc_attr(Arr::get($wppayform_donation_item, 'submission.submission.submission_hash', '')); ?>"
                                                            data-subscription_id="<?php echo esc_attr($wppayform_donation_item['id']); ?>">
                                                            <?php echo esc_html__('Yes, cancel this subscription', 'wp-payment-form'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="wpf-subscription-action-btn">
                                                <?php
                                                $wppayform_sub_status = strtolower((string) Arr::get($wppayform_donation_item, 'status', ''));
                                                $wppayform_cancel_btn_enabled = $wppayform_cancellable_sub && in_array($wppayform_sub_status, array('active', 'trialing'), true);
                                                if ($wppayform_cancellable_sub): ?>
                                                    <button type="button"
                                                            class="wpf-icon-button wpf-cancel-confirm-button wpf-cancel-subscription-dots<?php echo $wppayform_cancel_btn_enabled ? '' : ' wpf-cancel-disabled'; ?>"
                                                            data-modal_id="<?php echo esc_attr('wpf_toal_amount_cancel_modal' . $wppayform_i) ?>"
                                                            title="<?php echo $wppayform_cancel_btn_enabled ? esc_attr__('Cancel Subscription', 'wp-payment-form') : esc_attr__('Only active or trialing subscriptions can be cancelled', 'wp-payment-form'); ?>"
                                                            aria-label="<?php echo esc_attr__('Cancel Subscription', 'wp-payment-form'); ?>"
                                                            <?php echo $wppayform_cancel_btn_enabled ? '' : ' disabled'; ?>>
                                                        <span class="wpf-cancel-label" aria-hidden="true"><?php echo esc_html__('Cancel Subscription', 'wp-payment-form'); ?></span>
                                                        <span class="wpf-icon-svg wpf-icon-dots" aria-hidden="true"><?php echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>'; ?></span>
                                                    </button>
                                                <?php endif ?>
                                                <span class="wpf-sub-id wpf_toal_amount_btn wpf-icon-button"
                                                    data-modal_id="<?php echo esc_attr('wpf_toal_amount_modal' . $wppayform_i) ?>"
                                                    title="<?php echo esc_attr__('View Subscription Details', 'wp-payment-form'); ?>"
                                                    aria-label="<?php echo esc_attr__('View Subscription Details', 'wp-payment-form'); ?>">
                                                    <span class="wpf-icon-svg wpf-icon-eye" aria-hidden="true"><?php echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>'; ?></span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach ?>
                            </div>
                            </div><!-- /.wpf-table-scroll-wrapper -->
                        </div>
                    </div>
            </div>
            <div class="wpf-content wpf-community" id="content-wpf-community">
                <div id="dashboard_app"></div>
            </div>
        </div>
    <?php } else { ?>
        <div style="padding: 20px;">
            <?php echo esc_html__('You have not any access for read your entries from the administration', 'wp-payment-form') ?>
        </div>
    <?php } ?>
</div>