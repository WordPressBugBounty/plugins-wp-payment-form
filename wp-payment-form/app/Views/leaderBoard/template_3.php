<?php
use WPPayForm\App\Modules\LeaderBoard\Render;
$donorInfoClass = $show_avatar == 'true' ? "wpf-donor-info temp-three" : "wpf-donor-info temp-three wpf-info-flex";
$assetUrl = WPPAYFORM_URL . 'assets/images/global'; 
$nodonorData = WPPAYFORM_URL . 'assets/images/empty-cart.svg';
?>

<div class="wpf-leaderboard-temp-three wpf-template-wrapper"
    data-show-total="<?php echo $show_total == 'true' ? 'true' : 'false'; ?>"
    data-show-name="<?php echo $show_name == 'true' ? 'true' : 'false'; ?>"
    data-show-avatar="<?php echo $show_avatar == 'true' ? 'true' : 'false'; ?>">
    <?php if ($total != 0): ?>
        <div class="wpf-donor-filter-section">
            <div class="wpf-search-section">
                <input type="text" class="wpf-search-input" placeholder="Search donor">
                <span class="dashicons dashicons-search wpf-search-icon"></span>
            </div>
            <div class="wpf-filter-section">
                <div class="filter-radio-button">
                    <div class="wpf-radio-button" data-sort-key="created_at" key_value="true">
                        <span class="dashicons dashicons-arrow-up-alt wpf-filter-icon"></span>
                        <input type="radio" id="newest" name="wpf_donation_temp_1" value="newest">
                        <label for="newest">Newest</label>
                    </div>
                    <div class="wpf-radio-button" data-sort-key="created_at" key_value="">
                        <span class="dashicons dashicons-arrow-down-alt wpf-filter-icon"></span>
                        <input type="radio" id="oldest" name="wpf_donation_temp_1" value="oldest">
                        <label for="oldest">Oldest</label>
                    </div>
                    <div class="wpf-radio-button" data-sort-key="grand_total" key_value="true">
                        <span class="dashicons dashicons-businessperson wpf-filter-icon"></span>
                        <input type="radio" id="top_donar" name="wpf_donation_temp_1" value="top_donar">
                        <label for="top_donar">Top Donor</label>
                    </div>
                </div>
            </div>
        </div>
        <?php  
        if (!empty($form_id)): ?>  
            <?php if ($show_statistic === 'yes' && $progress_bar === 'yes'): ?>  
                <?php echo Render::displayDonationStats($total_raised_amount, esc_html($total_donations), esc_html($donation_goal), esc_html($percent)); ?>
            <?php endif; ?>
        <?php else: ?>
        <div class="wpf_total_raised_amount">  
            <p><?php echo esc_html__('Total Raised Amount', 'wp-payment-form'); ?>:</p>  
            <p class="wpf_amount"><?php echo esc_html($total_raised_amount); ?></p>  
        </div> 
        <?php endif; ?> 
        <div class="wpf-donor-card-wrapper wpf-user" data-template="<?php echo esc_attr($template_id) ?>"
            data-per-page="<?php echo esc_attr($per_page) ?>" data-orderby="<?php echo esc_attr($orderby) ?>"
            data-form_id="<?php echo esc_attr($form_id) ?>">
            <?php $top = 0;
            foreach ($donars as $key => $donor):
                $top = $top + 1;
                ?>
                <div class="wpf-donor-list three">
                    <?php if ($show_avatar == 'true'): ?>
                        <div class="wpf-donor-image">
                            <?php echo get_avatar($donor['customer_email'], 96); ?>
                        </div>
                    <?php endif; ?>
                    <div class="<?php echo esc_attr($donorInfoClass) ?>">
                        <?php if ($show_name == 'true'): ?>
                            <div class="wpf-donor-name three">
                                <h3>
                                    <?php echo esc_html($donor['customer_name']) ?>
                                </h3>
                            </div>
                        <?php endif; ?>

                        <?php if ($show_total == 'true'): ?>
                            <div class="wpf-donor-amount three">
                                <p>Donation Amount</p>
                                <span>
                                    <?php echo esc_html($donor['currency']) ?>
                                    <?php echo esc_html($donor['grand_total']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    
        <?php else: ?>
            <div class="wpf-no-donor-found">
                <img src="<?php echo esc_url($nodonorData) ?>" alt="No Donor Found" class="wpf-no-donor-found-image" style="width: 280px">
                <p style="background: inherit; color: #000; size: 20px;">No donor found yet!</p>
            </div>
        <?php endif; ?>

        <div class="wpf-leaderboard-loader">
            <span class="loader hide"></span>
        </div>
        <?php if ($total > 0) : ?>
            <div class="wpf-leaderboard-load-more-wrapper">
                <button class="wpf-load-more <?php echo $has_more_data == false ? 'disabled' : '' ?>">Load More</button>
            </div>
        <?php endif; ?>
</div>