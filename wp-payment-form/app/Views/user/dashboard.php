<?php
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Services\GlobalTools;

$current_user = wp_get_current_user();
$wppayform_user_email = '';
$wppayform_user_name = '';
$wppayform_user_from = '';
// dd($current_user);
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
            <?php echo get_avatar($wppayform_user_email, 96); ?>
        </div>
        <div class="wpf-user-info">
            <div class="wpf-user-name">
                <p>
                    <?php echo esc_html($wppayform_user_name) ?>
                </p>
            </div>
            <div class="wpf-sub-info">
                <div class="wpf-info-item">
                    <img src="<?php echo esc_attr(WPPAYFORM_URL . "assets/images/dashboard/email.svg") ?>" />
                    <span>
                        <?php echo esc_html($wppayform_user_email) ?>
                    </span>
                </div>
                <div class="wpf-info-item">
                    <img src="<?php echo esc_attr(WPPAYFORM_URL . "assets/images/dashboard/register.svg") ?>" />
                    <span>
                        <?php echo esc_html__('Registered since', 'wp-payment-form') ?> - <?php echo esc_html($wppayform_user_from) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php if ($wppayform_read_entry == 'yes' || $wppayform_read_subscription_entry == 'yes') { ?>
        <div class="wpf-user-content">
            <div class="wpf-menu">
                <?php
                foreach ($menus as $menu) {
                    // dd($menu);
                    ?>

                    <div class="wpf-menu-item" id="<?php echo esc_attr($menu['slug']); ?>">
                        <img src="<?php echo esc_attr(wppayform_get_menu_icon($menu['slug'])); ?>" />
                        <span><?php echo esc_html($menu['name']); ?></span>
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
                        <span class="dashicons dashicons-analytics"></span>
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
                        <span class="dashicons dashicons-calendar"></span>
                        <?php echo esc_html__('Your Submissions', 'wp-payment-form')?>
                    </div>
                    <div class="wpf-user-dashboard-table">
                        <div class="wpf-user-dashboard-loader"></div>
                        <div class="wpf-user-dashboard-table__header">
                            <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('ID', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Amount', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Date', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Status', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Gateway', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Action', 'wp-payment-form') ?></div>
                        </div>
                        <div class="wpf-user-dashboard-table__rows">
                            <?php
                            $wppayform_i = 0;
                            foreach (Arr::get($donationItems, 'entries', []) as $wppayform_donation_index => $wppayform_donation_item):
                                $wppayform_payment_total = Arr::get($wppayform_donation_item, 'payment_total', 0);
                                $wppayform_i++;
                                ?>
                                <div class=" wpf-user-dashboard-table__row">
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
                                    <img src="<?php echo esc_url(wppayform_get_payment_gateways(Arr::get($wppayform_donation_item, 'payment_method', ''))); ?>" alt="<?php echo esc_attr(Arr::get($wppayform_donation_item, 'payment_method', '')); ?>">
                                        <!-- <?php echo esc_html(Arr::get($wppayform_donation_item, 'payment_method', '')) ?> -->
                                    </div>
                                    <div class="wpf-user-dashboard-table__column wpf-user-dashboard-last_column">
                                        <span class="wpf-sub-id wpf_toal_amount_btn"
                                            data-modal_id="<?php echo esc_attr('wpf_toal_amount_modal' . $wppayform_i) ?>">
                                            <?php echo esc_html__('View Receipt', 'wp-payment-form') ?> <span class="dashicons dashicons-arrow-right-alt"></span>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- <div class="wpf-content" id="wpf-donor-history">Donor history</div> -->
            <div class="wpf-content wpf-dashboard" id="content-wpf-subscription">
                <?php if (!empty(Arr::get($donationItems, 'subscriptions', [])) ): ?>
                    <div class="wpf-submission-table wpf-dashboard-card">
                        <div class="wpf-submission-head">
                            <span class="dashicons dashicons-calendar"></span>
                            <?php echo esc_html__('Your Subscription', 'wp-payment-form') ?>
                        </div>

                        <div class="wpf-user-dashboard-table">
                            <div class="wpf-user-dashboard-loader"></div>
                            <div class="wpf-user-dashboard-table__header">
                                <div style="flex: 2" class="wpf-user-dashboard-table__column"><?php echo esc_html__('Plan', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Billing Time', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Status', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column"><?php echo esc_html__('Interval', 'wp-payment-form') ?></div>
                                <div class="wpf-user-dashboard-table__column" style="text-align: right;" ><?php echo esc_html__('Action', 'wp-payment-form') ?></div>
                            </div>
                            <div class="wpf-user-dashboard-table__rows">
                                <?php
                                $wppayform_i = 1000;
                                foreach (Arr::get($donationItems, 'subscriptions', []) as $wppayform_donation_key => $wppayform_donation_item):
                                    $wppayform_i++;
                                    ?>
                                    <div class=" wpf-user-dashboard-table__row">
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
                                                        $wppayform_cancellable_sub = $wppayform_cancel_subscription == 'yes' && ($wppayform_payment_method == 'stripe' ||  $wppayform_payment_method == 'square' ||  $wppayform_payment_method == 'paypal');
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
                                            <div id="<?php echo esc_attr('wpf_toal_amount_cancel_modal' . $wppayform_i) ?>"
                                                class="wpf-dashboard-modal wpf-confirmation-modal">
                                                <!-- Modal content -->
                                                <div class="modal-content">
                                                    <div class="modal-title">
                                                        <p class="title"><?php echo esc_html__('Confirm subscription cancellation', 'wp-payment-form') ?></p>
                                                        <span class="wpf-close">&times;</span>
                                                    </div>
                                                    <div class="modal-body">
                                                        <span class="dashicons dashicons-info-outline wpf-info-icon"></span>
                                                        <h4><?php echo esc_html__('Are you sure to cancel this subscription ?', 'wp-payment-form') ?></h4>
                                                        <p><?php echo esc_html__('This will also cancel the subscription at', 'wp-payment-form') ?> <?php echo  esc_html(Arr::get($wppayform_donation_item, 'submission.submission.payment_method', '')) ?> <?php echo esc_html__('dashboard', 'wp-payment-form') ?>.
                                                        <?php echo esc_html__('So no further payment will be processed.', 'wp-payment-form') ?></p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button class="modal-btn wpf-cancel"><?php echo esc_html__('Dismiss', 'wp-payment-form') ?></button>
                                                        <button
                                                            class="modal-btn wpf-success wpf-confirm-subscription-cancel"
                                                            data-form_id="<?php echo esc_attr($wppayform_donation_item['form_id']) ?>"
                                                            data-submission_hash="<?php echo  esc_attr(Arr::get($wppayform_donation_item, 'submission.submission.submission_hash', '')) ?>"
                                                            data-subscription_id="<?php echo esc_attr($wppayform_donation_item['id']) ?>"><?php echo esc_html__('Yes, Cancel this Subscription', 'wp-payment-form') ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="wpf-subscription-action-btn">
                                                <?php if ($wppayform_cancellable_sub): ?>
                                                    <div class="wpf-cancel-subscription">
                                                        <svg
                                                            class="wpf-cancel-subscription-btn <?php echo esc_html(in_array(Arr::get($wppayform_donation_item, 'status', ''), ['active', 'trialing']) ? 'active' : '') ?>"
                                                            xmlns="http://www.w3.org/2000/svg"
                                                            viewBox="0 0 24 24" fill="currentColor"><path d="M0 0h24v24H0V0z" fill="none"
                                                            ></path>
                                                            <path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z">
                                                            </path>
                                                        </svg>
                                                        <button data-modal_id="<?php echo esc_attr('wpf_toal_amount_cancel_modal' . $wppayform_i) ?>" class="wpf-cancel-confirm-button"><?php echo esc_html__('Cancel', 'wp-payment-form') ?></button>
                                                    </div>
                                                <?php endif ?>
                                                <span class="wpf-sub-id wpf_toal_amount_btn"
                                                    data-modal_id="<?php echo esc_attr('wpf_toal_amount_modal' . $wppayform_i) ?>">
                                                    <span>View</span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="wpf-user-dashboard_empty"> No Subscriptions Found </div>
                <?php endif; ?>
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