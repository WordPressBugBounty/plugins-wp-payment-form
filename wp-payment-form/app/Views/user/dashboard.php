<?php
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Services\GlobalTools;

$current_user = wp_get_current_user();
$user_email = '';
$user_name = '';
$user_from = '';
// dd($current_user);
if ($current_user) {
    $user_email = $current_user->data->user_email;
    $user_name = $current_user->data->display_name;
    $user_from = $current_user->data->user_registered;
    $dateTime = new DateTime($user_from);
    // Get the user from date with Day Month Year format
    $user_from = $dateTime->format('l F Y');
}

$read_entry = Arr::get($permissions, 'read_entry');
$read_subscription_entry = Arr::get($permissions, 'read_subscription_entry');
$can_sync_subscription_billings = Arr::get($permissions, 'can_sync_subscription_billings');
$cancel_subscription = Arr::get($permissions, 'cancel_subscription');
?>

<div class="wpf-user-dashboard">
    <div class="wpf-user-profile">
        <div class="wpf-user-avatar">
            <?php echo get_avatar($user_email, 96); ?>
        </div>
        <div class="wpf-user-info">
            <div class="wpf-user-name">
                <p>
                    <?php echo esc_html($user_name) ?>
                </p>
            </div>
            <div class="wpf-sub-info">
                <div class="wpf-info-item">
                    <span class="dashicons dashicons-email"></span>
                    <span>
                        <?php echo esc_html($user_email) ?>
                    </span>
                </div>
                <div class="wpf-info-item">
                    <span class="dashicons dashicons-calendar"></span>
                    <span>
                        <?php echo __('Registered since', 'wp-payment-form') ?> - <?php echo esc_html($user_from) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php if ($read_entry == 'yes' || $read_subscription_entry == 'yes') { ?>
        <div class="wpf-user-content">
            <div class="wpf-menu">
                <div class="wpf-menu-item" id="wpf-user-dashboard">
                    <span class="dashicons dashicons-admin-home"></span>
                    <span><?php echo __('Dashboard', 'wp-payment-form'); ?></span>
                </div>
                <?php if ($read_subscription_entry == 'yes'): ?>
                    <div class="wpf-menu-item" id="wpf-subscription">
                        <span class="dashicons dashicons-list-view"></span>
                        <span><?php echo __('Manage Subscription', 'wp-payment-form'); ?></span>
                    </div>
                <?php endif ?>
                <div class="wpf-logout-btn" id="wpf-logout">
                    <span class="dashicons dashicons-upload"></span>
                    <a href="<?php echo wp_logout_url(); ?>"><?php echo __('Logout', 'wp-payment-form'); ?></a>
                </div>
            </div>
            <div class="wpf-content wpf-dashboard" id="content-wpf-user-dashboard">
                <div class="wpf-user-stats wpf-dashboard-card">
                    <div class="wpf-stats-head">
                        <span class="dashicons dashicons-analytics"></span>
                        <span><?php echo __('Your Submission Stats', 'wp-payment-form'); ?></span>
                    </div>
                    <div class="wpf-stats-card">
                        <div class="overview-card">
                            <div id="wpf_toal_amount_modal" class="wpf-dashboard-modal">
                                <!-- Modal content -->
                                <div class="modal-content max-width-340">
                                    <span class="wpf-close">&times;</span>
                                    <?php foreach ($payment_total as $total_key => $payment_total_amount): ?>
                                        <p>
                                            <?php echo esc_html($payment_total_amount / 100) ?>
                                            <?php echo esc_html($total_key) ?>
                                        </p>
                                    <?php endforeach ?>
                                </div>
                            </div>
                            <div class="info">
                                <h4 class="h4">
                                    <?php echo esc_html(Arr::get(array_values($payment_total), '0')/ 100) ?>
                                    <?php echo key($payment_total); ?>
                                </h4>
                                <p class="wpf_toal_amount_btn" data-modal_id="wpf_toal_amount_modal">Expend All</p>
                                <span data-v-5e7a3b24=""> <?php echo __('Total Spend', 'wp-payment-form') ?></span>
                            </div>
                            <div data-v-5e7a3b24="" class="icon">
                                <img class="spent" src="<?php echo esc_attr(WPPAYFORM_URL . "assets/images/dashboard/spent.svg") ?>"
                                    alt="total-spent" />
                            </div>
                        </div>
                        <?php if ($read_entry == 'yes'): ?>
                            <div class="overview-card">
                                <div class="info">
                                    <h4 class="h4">
                                        <?php echo esc_html(count(Arr::get($donationItems, 'orders', []))) ?>
                                    </h4>
                                    <span data-v-5e7a3b24=""><?php echo __('Total Orders', 'wp-payment-form') ?></span>
                                </div>
                                <div data-v-5e7a3b24="" class="icon">
                                    <img class="order" src="<?php echo esc_attr(WPPAYFORM_URL . "assets/images/dashboard/order.svg") ?>"
                                        alt="order" />
                                </div>
                            </div>
                        <?php endif ?>
                        <?php if ($read_subscription_entry == 'yes'): ?>
                            <div class="overview-card">
                                <div class="info">
                                    <h4 class="h4">
                                        <?php echo esc_html(count(Arr::get($donationItems, 'subscriptions', []))) ?>
                                    </h4>
                                    <span data-v-5e7a3b24=""><?php echo __('Total Subscription', 'wp-payment-form') ?></span>
                                </div>
                                <div data-v-5e7a3b24="" class="icon">
                                    <img class="subscription"
                                        src="<?php echo esc_attr(WPPAYFORM_URL . "assets/images/dashboard/subscription.svg") ?>"
                                        alt="subscription" />
                                </div>
                            </div>
                        <?php endif ?>
                    </div>
                </div>
                <div class="wpf-submission-table wpf-dashboard-card">
                    <div class="wpf-submission-head">
                        <span class="dashicons dashicons-calendar"></span>
                        <?php echo __('Your Submissions', 'wp-payment-form')?>
                    </div>
                    <div class="wpf-user-dashboard-table">
                        <div class="wpf-user-dashboard-loader"></div>
                        <div class="wpf-user-dashboard-table__header">
                            <div class="wpf-user-dashboard-table__column"><?php echo __('ID', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Amount', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Date', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Status', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Payment Method', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Action', 'wp-payment-form') ?></div>
                        </div>
                        <div class="wpf-user-dashboard-table__rows">
                            <?php
                            $i = 0;
                            foreach (Arr::get($donationItems, 'entries', []) as $donationIndex => $donationItem):
                                $paymentTotal = Arr::get($donationItem, 'payment_total', 0);
                                $i++;
                                ?>
                                <div class=" wpf-user-dashboard-table__row">
                                    <div id="<?php echo esc_attr('wpf_toal_amount_modal' . $i) ?>" class="wpf-dashboard-modal">
                                        <!-- Modal content -->
                                        <div class="modal-content">
                                            <div class="submission-modal">    
                                                <span class="wpf-close">&times;</span>
                                                <?php
                                                $receiptHandler = new \WPPayForm\App\Modules\Builder\PaymentReceipt();
                                                echo $receiptHandler->render($donationItem['id']);
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class=" wpf-user-dashboard-table__column">
                                        #
                                        <?php echo esc_html(Arr::get($donationItem, 'id', '')) ?>
                                    </div>
                                    <div class=" wpf-user-dashboard-table__column">
                                        <?php echo esc_html($paymentTotal / 100) ?>
                                        <?php echo esc_html(Arr::get($donationItem, 'currency', '')) ?>
                                    </div>
                                    <div class="wpf-user-dashboard-table__column">
                                        <?php echo esc_html(GlobalTools::convertStringToDate(Arr::get($donationItem, 'created_at', ''))) ?>
                                    </div>
                                    <div class="wpf-user-dashboard-table__column">
                                        <span
                                            class="wpf-payment-status <?php echo Arr::get($donationItem, 'payment_status', '') ?>">
                                            <?php echo esc_html(Arr::get($donationItem, 'payment_status', '')) ?>
                                        </span>
                                    </div>
                                    <div class="wpf-user-dashboard-table__column">
                                        <?php echo esc_html(Arr::get($donationItem, 'payment_method', '')) ?>
                                    </div>
                                    <div class="wpf-user-dashboard-table__column">
                                        <span class="wpf-sub-id wpf_toal_amount_btn"
                                            data-modal_id="<?php echo esc_attr('wpf_toal_amount_modal' . $i) ?>">
                                            <?php echo __('View Receipt', 'wp-payment-form') ?> <span class="dashicons dashicons-arrow-right-alt"></span>
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
                <div class="wpf-submission-table wpf-dashboard-card">
                    <div class="wpf-submission-head">
                        <span class="dashicons dashicons-calendar"></span>
                        <?php echo __('Your Subscription', 'wp-payment-form') ?>
                    </div>
                    <div class="wpf-user-dashboard-table">
                        <div class="wpf-user-dashboard-loader"></div>
                        <div class="wpf-user-dashboard-table__header">
                            <div style="flex: 2" class="wpf-user-dashboard-table__column"><?php echo __('Plan', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Billing Time', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Status', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Interval', 'wp-payment-form') ?></div>
                            <div class="wpf-user-dashboard-table__column"><?php echo __('Action', 'wp-payment-form') ?></div>
                        </div>
                        <div class="wpf-user-dashboard-table__rows">
                            <?php
                            $i = 1000;
                            foreach (Arr::get($donationItems, 'subscriptions', []) as $donationKey => $donationItem):
                                $i++;
                                ?>
                                <div class=" wpf-user-dashboard-table__row">
                                    <div id="<?php echo esc_attr('wpf_toal_amount_modal' . $i) ?>" class="wpf-dashboard-modal">
                                        <!-- Modal content -->
                                        <div class="modal-content">
                                            <div class="submission-modal">
                                                <span class="wpf-close">&times;</span>
                                                <div class="wpf-user-dashboard-table-container" style="padding-top: 21px">
                                                    <?php
                                                    $receiptHandler = new \WPPayForm\App\Modules\Builder\SubscriptionEntries();
                                                    $isNotOfflinePayment = Arr::get($donationItem, 'submission.submission.payment_method', '') != 'offline';
                                                    $planName = Arr::get($donationItem, 'plan_name', '');
                                                    $submission_hash = Arr::get($donationItem, 'submission.submission.submission_hash', '');
                                                    // dd($donationItem);
                                                    echo $receiptHandler->render(
                                                        Arr::get($donationItem, 'related_payments', []),
                                                        Arr::get($donationItem, 'status', 'active'),
                                                        Arr::get($donationItem, 'form_id'),
                                                        Arr::get($donationItem, 'submission.submission.submission_hash', ''),
                                                        $can_sync_subscription_billings,
                                                        $isNotOfflinePayment,
                                                        $planName
                                                    );
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div style=" flex: 2" class="wpf-user-dashboard-table__column">
                                        <?php echo esc_html($planName) ?>
                                    </div>
                                    <div class="wpf-user-dashboard-table__column">
                                        <?php echo esc_html(Arr::get($donationItem, 'bill_times', '') == 0 ? 'Infinity' : Arr::get($donationItem, 'bill_times', '')) ?>
                                    </div>
                                    <div class="wpf-user-dashboard-table__column">
                                        <span class="wpf-payment-status <?php echo esc_attr(Arr::get($donationItem, 'status', '')) ?>">
                                            <?php echo esc_html(Arr::get($donationItem, 'status', '')) ?>
                                        </span>
                                    </div>
                                    <div class="wpf-user-dashboard-table__column">
                                        <?php echo esc_html(Arr::get($donationItem, 'billing_interval', '')) ?>
                                    </div>
                                    <div class="wpf-user-dashboard-table__column">
                                        <div id="<?php echo esc_attr('wpf_toal_amount_cancel_modal' . $i) ?>"
                                            class="wpf-dashboard-modal wpf-confirmation-modal">
                                            <!-- Modal content -->
                                            <div class="modal-content">
                                                <div class="modal-title">
                                                    <p class="title"><?php echo __('Confirm subscription cancellation', 'wp-payment-form') ?></p>
                                                    <span class="wpf-close">&times;</span>
                                                </div>
                                                <div class="modal-body">
                                                    <span class="dashicons dashicons-info-outline wpf-info-icon"></span>
                                                    <h4><?php echo __('Are you sure to cancel this subscription ?', 'wp-payment-form') ?></h4>
                                                    <p><?php echo __('This will also cancel the subscription at', 'wp-payment-form') ?> <?php echo  esc_html(Arr::get($donationItem, 'submission.submission.payment_method', '')) ?> <?php echo __('dashboard', 'wp-payment-form') ?>. 
                                                    <?php echo __('So no further payment will be processed.', 'wp-payment-form') ?></p>
                                                </div>
                                                <div class="modal-footer">
                                                    <button class="modal-btn wpf-cancel"><?php echo __('Dismiss', 'wp-payment-form') ?></button>
                                                    <button
                                                        class="modal-btn wpf-success wpf-confirm-subscription-cancel"
                                                        data-form_id="<?php echo esc_attr($donationItem['form_id']) ?>"
                                                        data-submission_hash="<?php echo  esc_attr(Arr::get($donationItem, 'submission.submission.submission_hash', '')) ?>"
                                                        data-subscription_id="<?php echo esc_attr($donationItem['id']) ?>"><?php echo __('Yes, Cancel this Subscription', 'wp-payment-form') ?>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="wpf-subscription-action-btn">
                                            <?php if ($isNotOfflinePayment && $cancel_subscription == 'yes'): ?>
                                                <div class="wpf-cancel-subscription">
                                                    <svg
                                                        class="wpf-cancel-subscription-btn <?php echo esc_html(Arr::get($donationItem, 'status', '') == 'active' ? 'active' : '') ?>"
                                                        xmlns="http://www.w3.org/2000/svg"
                                                        viewBox="0 0 24 24" fill="currentColor"><path d="M0 0h24v24H0V0z" fill="none"
                                                        ></path>
                                                        <path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z">
                                                        </path>
                                                    </svg>
                                                    <button data-modal_id="<?php echo esc_attr('wpf_toal_amount_cancel_modal' . $i) ?>" class="wpf-cancel-confirm-button"><?php echo __('Cancel', 'wp-payment-form') ?></button>
                                                </div>
                                            <?php endif ?>
                                            <span class="wpf-sub-id wpf_toal_amount_btn"
                                                data-modal_id="<?php echo esc_attr('wpf_toal_amount_modal' . $i) ?>">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <div style="padding: 20px;">
            <?php echo __('You have not any access for read your entries from the administration', 'wp-payment-form') ?>
        </div>
    <?php } ?>
</div>