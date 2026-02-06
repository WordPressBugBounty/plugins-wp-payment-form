<?php
use WPPayForm\App\Modules\LeaderBoard\Render;
$wppayform_donor_info_class = $show_avatar == 'true' ? "wpf-donor-info temp-three" : "wpf-donor-info temp-three wpf-info-flex";
$wppayform_asset_url = WPPAYFORM_URL . 'assets/images/global';
$wppayform_nodonor_data = WPPAYFORM_URL . 'assets/images/empty-cart.svg';
?>

<div class="wpf-leaderboard-temp-three wpf-template-wrapper"
    data-show-total="<?php echo $show_total == 'true' ? 'true' : 'false'; ?>"
    data-show-name="<?php echo $show_name == 'true' ? 'true' : 'false'; ?>"
    data-show-avatar="<?php echo $show_avatar == 'true' ? 'true' : 'false'; ?>">
    <?php if ($total != 0): ?>
        <div class="wpf-donor-filter-section">
            <div class="wpf-search-section">
                <input type="text" class="wpf-search-input" placeholder="<?php esc_html_e('Search donor', 'wp-payment-form') ?>">
                <span class="dashicons dashicons-search wpf-search-icon"></span>
            </div>
            <div class="wpf-filter-section">
                <div class="filter-radio-button">
                    <div class="wpf-radio-button" data-sort-key="created_at" key_value="true">
                        <span class="dashicons dashicons-arrow-up-alt wpf-filter-icon"></span>
                        <input type="radio" id="newest" name="wpf_donation_temp_1" value="newest">
                        <label for="newest"><?php esc_html_e('Newest', 'wp-payment-form') ?></label>
                    </div>
                    <div class="wpf-radio-button" data-sort-key="created_at" key_value="">
                        <span class="dashicons dashicons-arrow-down-alt wpf-filter-icon"></span>
                        <input type="radio" id="oldest" name="wpf_donation_temp_1" value="oldest">
                        <label for="oldest"><?php esc_html_e('Oldest', 'wp-payment-form') ?></label>
                    </div>
                    <div class="wpf-radio-button" data-sort-key="grand_total" key_value="true">
                        <span class="dashicons dashicons-businessperson wpf-filter-icon"></span>
                        <input type="radio" id="top_donar" name="wpf_donation_temp_1" value="top_donar">
                        <label for="top_donar"><?php esc_html_e('Top Donor', 'wp-payment-form') ?></label>
                    </div>
                </div>
            </div>
        </div>
        <?php  
        if (!empty($form_id)): ?>  
            <?php if ($show_statistic === 'yes' && $progress_bar === 'yes'): ?>  
                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo Render::displayDonationStats($total_raised_amount, esc_html($total_donations), esc_html($donation_goal), esc_html($percent)); ?>
            <?php endif; ?>
        <?php else: ?>
        <div class="wpf_total_raised_amount">  
            <p><?php echo esc_html_e('Total Raised Amount', 'wp-payment-form'); ?>:</p>  
            <p class="wpf_amount"><?php echo esc_html($total_raised_amount); ?></p>  
        </div> 
        <?php endif; ?> 
        <div class="wpf-donor-card-wrapper wpf-user" data-template="<?php echo esc_attr($template_id) ?>"
            data-per-page="<?php echo esc_attr($per_page) ?>" data-orderby="<?php echo esc_attr($orderby) ?>"
            data-form_id="<?php echo esc_attr($form_id) ?>">
            <?php $wppayform_top = 0;
            foreach ($donars as $wppayform_key => $wppayform_donor):
                $wppayform_top = $wppayform_top + 1;
                ?>
                <div class="wpf-donor-list three">
                    <?php if ($show_avatar == 'true'): ?>
                        <div class="wpf-donor-image">
                            <?php echo get_avatar($wppayform_donor['customer_email'], 96); ?>
                        </div>
                    <?php endif; ?>
                    <div class="<?php echo esc_attr($wppayform_donor_info_class) ?>">
                        <?php if ($show_name == 'true'): ?>
                            <div class="wpf-donor-name three">
                                <h3>
                                    <?php echo esc_html($wppayform_donor['customer_name']) ?>
                                </h3>
                            </div>
                        <?php endif; ?>

                        <?php if ($show_total == 'true'): ?>
                            <div class="wpf-donor-amount three">
                                <p><?php esc_html_e('Donation Amount', 'wp-payment-form') ?></p>
                                <span>
                                    <?php echo esc_html($wppayform_donor['currency']) ?>
                                    <?php echo esc_html($wppayform_donor['grand_total']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
            <div class="wpf-no-donor-found">
                <img src="<?php echo esc_url($wppayform_nodonor_data) ?>" alt="No Donor Found" class="wpf-no-donor-found-image" style="width: 280px">
                <p style="background: inherit; color: #000; size: 20px;"><?php esc_html_e('No donor found yet!', 'wp-payment-form') ?></p>
            </div>
        <?php endif; ?>

        <div class="wpf-leaderboard-loader">
            <span class="loader hide"></span>
        </div>
        <?php if ($total > 0) : ?>
            <div class="wpf-leaderboard-load-more-wrapper">
                <button class="wpf-load-more <?php echo $has_more_data == false ? 'disabled' : '' ?>"><?php esc_html_e('Load More', 'wp-payment-form') ?></button>
            </div>
        <?php endif; ?>
</div>