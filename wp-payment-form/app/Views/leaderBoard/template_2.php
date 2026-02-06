<?php
use WPPayForm\App\Modules\LeaderBoard\Render;
$wppayform_asset_url = WPPAYFORM_URL . 'assets/images/global';
$wppayform_nodonor_data = WPPAYFORM_URL . 'assets/images/empty-cart.svg';
$wppayform_top_donor_badge = WPPAYFORM_URL . 'assets/images/global/serial-bg.svg';
?>
<div class="wpf-leaderboard-temp-one wpf-bg-white wpf-template-wrapper" data-show-total="<?php echo $show_total == 'true' ? 'true' : 'false';?>"   data-show-name="<?php echo $show_name == 'true' ? 'true' : 'false';?>" data-show-avatar="<?php echo $show_avatar == 'true' ? 'true' : 'false';?>">
    <div class="wpf-leaderboard">
        <div class="wpf-user-column">
            <!-- Top 3 donor section start -->
            <div class="wpf-top-donor-card-wrapper">
                <div class="wpf-top-donor-cards">
                    <?php $wppayform_top = 0; ?>
                    <?php foreach ($topThreeDonars as $wppayform_key => $wppayform_top_three_donar) :
                        $wppayform_top = $wppayform_top + 1;
                        $wppayform_class = "card-" . $wppayform_top;
                        $wppayform_badge_class = "wpf-user-serial-badge-" . $wppayform_top;
                    ?>
                        <div class="wpf-top-donor-card <?php echo esc_attr($wppayform_class) ?>">
                            <div class="wpf_top_badge_wrapper">
                                <div class="wpf-user-serial <?php echo esc_attr($wppayform_badge_class) ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 38 38" fill="none">
                                        <path d="M29.8885 6.08418C30.2684 7.00296 30.9976 7.73328 31.9158 8.11456L35.1355 9.44821C36.0544 9.82881 36.7844 10.5588 37.165 11.4777C37.5456 12.3966 37.5456 13.429 37.165 14.3478L35.8323 17.5652C35.4516 18.4845 35.451 19.518 35.8335 20.4368L37.1639 23.6532C37.3526 24.1083 37.4498 24.5961 37.4498 25.0888C37.4499 25.5815 37.3529 26.0693 37.1644 26.5245C36.9758 26.9796 36.6995 27.3932 36.3511 27.7415C36.0026 28.0898 35.589 28.366 35.1338 28.5544L31.9164 29.8871C30.9976 30.267 30.2673 30.9962 29.886 31.9144L28.5523 35.1342C28.1717 36.053 27.4417 36.783 26.5229 37.1636C25.604 37.5442 24.5716 37.5442 23.6527 37.1636L20.4353 35.8309C19.5164 35.4513 18.4844 35.4521 17.5661 35.8331L14.3464 37.1648C13.428 37.5446 12.3965 37.5442 11.4784 37.1639C10.5603 36.7837 9.83067 36.0545 9.4498 35.1366L8.11575 31.9159C7.73585 30.9972 7.00664 30.2668 6.08843 29.8856L2.86871 28.5519C1.95025 28.1715 1.22044 27.4419 0.839697 26.5236C0.458955 25.6053 0.458438 24.5733 0.838262 23.6546L2.17096 20.4372C2.55062 19.5183 2.54985 18.4863 2.1688 17.568L0.83802 14.3459C0.649341 13.8908 0.552187 13.403 0.552108 12.9104C0.552029 12.4177 0.649027 11.9299 0.83756 11.4747C1.02609 11.0195 1.30246 10.606 1.65088 10.2577C1.9993 9.90938 2.41294 9.63314 2.86816 9.44475L6.08557 8.11206C7.00355 7.73249 7.73343 7.00419 8.11499 6.08704L9.44864 2.86732C9.82924 1.94846 10.5593 1.21843 11.4781 0.837832C12.397 0.45723 13.4294 0.45723 14.3483 0.837832L17.5657 2.17053C18.4845 2.55019 19.5166 2.54942 20.4349 2.16837L23.656 0.839899C24.5747 0.45951 25.6069 0.459588 26.5256 0.840115C27.4442 1.22064 28.1742 1.95046 28.5548 2.86908L29.8889 6.08975L29.8885 6.08418Z" fill="currentColor"/>
                                    </svg>
                                    <span class="wpf-user-serial-text"><?php echo esc_html($wppayform_top) ?></span>
                                </div>
                            </div>
                            <div class="info">
                                <?php if ($show_avatar == 'true') : ?>
                                    <div class="wpf-user-avatar">
                                        <?php echo get_avatar($wppayform_top_three_donar['customer_email'], 96); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($show_name == 'true') : ?>
                                    <div class="wpf-user-name">
                                        <span class="wpf-user-name-text"><?php echo esc_html($wppayform_top_three_donar['customer_name']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($show_total == 'true') : ?>
                                    <div class="wpf-user-amount">
                                        <p class="wpf-user-amount-text"><?php esc_html_e('Amount Donated', 'wp-payment-form') ?></p>
                                        <div>
                                            <span class="wpf-text-currency"><?php echo esc_html($wppayform_top_three_donar['currency'])  ?></span>
                                            <span class="wpf-text-amount"><?php echo esc_html($wppayform_top_three_donar['grand_total']) ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <!-- <div class="wpf-top-donor-card card-two">
                        <div class="wpf-user-serial">
                            <span class="wpf-user-serial-text">1</span>
                        </div>
                        <div class="info">
                            <div class="wpf-user-avatar">
                                <img src="https://secure.gravatar.com/avatar/0d1b0b6b6b6b6b6b6b6b6b6b6b6b6b6b?s=96&amp;d=mm&amp;r=g" alt="User Avatar">
                            </div>
                            <div class="wpf-user-name">
                                <span class="wpf-user-name-text">Json Roy Kobi</span>
                            </div>
                            <div class="wpf-user-amount">
                                <span class="wpf-text-amount">$1000</span>
                            </div>
                        </div>
                    </div>
                    <div class="wpf-top-donor-card card-three">
                        <div class="wpf-user-serial">
                            <span class="wpf-user-serial-text">3</span>
                        </div>
                        <div class="info">
                            <div class="wpf-user-avatar">
                                <img src="https://secure.gravatar.com/avatar/0d1b0b6b6b6b6b6b6b6b6b6b6b6b6b6b?s=96&amp;d=mm&amp;r=g" alt="User Avatar">
                            </div>
                            <div class="wpf-user-name">
                                <span class="wpf-user-name-text">Json Roy Kobi</span>
                            </div>
                            <div class="wpf-user-amount">
                                <span class="wpf-text-amount">$1000</span>
                            </div>
                        </div>
                    </div> -->
                </div>
            </div>
            <!-- donor filter section -->
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
             <div class="all_donor_section">
                <div class="wpf-donor-filter-section">
                    <div class="wpf-search-section">
                        <input type="text" class="wpf-search-input" placeholder="<?php esc_html_e('Search', 'wp-payment-form') ?>" donor>
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
                <div class="wpf_donor_table_header <?php echo !$form_id ? 'wpf-all-forms-user' : ''; ?>">
                    <div class="wpf-donor-table-header-cell wpf-table-header-serial">
                        <span class="wpf-table-header-text"><?php esc_html_e('Rank', 'wp-payment-form') ?></span>
                    </div>
                    <div class="wpf-donor-table-header-cell">
                        <span class="wpf-table-header-text"><?php esc_html_e('Donor', 'wp-payment-form') ?></span>
                    </div>
                    <div class="wpf-donor-table-header-cell">
                        <span class="wpf-table-header-text"><?php esc_html_e('Last Donation', 'wp-payment-form') ?></span>
                    </div>
                    <?php if ($form_id): ?>
                    <div class="wpf-donor-table-header-cell wpf-user-donations">
                        <span class="wpf-table-header-text"><?php esc_html_e('Donations', 'wp-payment-form') ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="wpf-donor-table-header-cell">
                        <span class="wpf-table-header-text"><?php esc_html_e('Amount', 'wp-payment-form') ?></span>
                    </div>
                </div>
                <!-- donor list section -->
                <div class="wpf-user" data-per-page="<?php echo esc_attr($per_page) ?>" data-orderby="<?php echo esc_attr($orderby) ?>" data-form_id="<?php echo esc_attr($form_id) ?>">

                    <?php
                    $wppayform_donar_index = 0;
                    foreach ($donars as $wppayform_key => $wppayform_donor) :

                    ?>
                        <div class="wpf-user-row <?php echo !$form_id ? 'wpf-all-forms-user' : ''; ?>">
                            <div class="wpf-user-serial">
                                <span class="wpf-user-serial-text"><?php echo  esc_html(++$wppayform_donar_index) ?></span>
                            </div>
                            <?php if ($show_avatar == 'true' || $show_name == 'true') : ?>
                            <div class="wpf-user-avatar-name">
                                <?php if ($show_avatar == 'true') : ?>
                                    <div class="wpf-user-avatar">
                                        <?php echo get_avatar($wppayform_donor['customer_email'], 96); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($show_name == 'true') : ?>
                                    <div class="wpf-user-name">
                                        <span class="wpf-user-name-text"><?php echo esc_html($wppayform_donor['customer_name']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($show_name == 'true') : ?>
                                <div class="wpf-user-name">
                                    <span class="wpf-user-name-text"><?php
                                    $wppayform_original_date = esc_html($wppayform_donor['created_at']);
                                    $wppayform_date = new DateTime($wppayform_original_date);
                                    $wppayform_formatted_date = $wppayform_date->format('d M, Y');
                                    echo esc_html($wppayform_formatted_date)  ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($form_id): ?>
                                <span class="wpf-user-donations"><?php echo esc_html($wppayform_donor['donations_count']) ?> <?php esc_html_e('Donations', 'wp-payment-form') ?></span>
                            <?php endif; ?>
                            <?php if ($show_total == 'true') : ?>
                                <div class="wpf-user-amount">
                                    <span class="wpf-user-amount">
                                        <span class="wpf-text-currency"><?php echo esc_html($wppayform_donor['currency'])  ?></span>
                                        <span class="wpf-text-amount"><?php echo esc_html($wppayform_donor['grand_total']) ?></span>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($total_raised_amount) && is_numeric($total_raised_amount) && $total_raised_amount <= 0) : ?>
                    <div class="wpf-no-donor-found">
                        <img src="<?php echo esc_url($wppayform_nodonor_data) ?>" alt="No Donor Found" class="wpf-no-donor-found-image" style="width: 280px">
                        <p style="background: inherit; color: #000; size: 20px;"><?php esc_html_e('No donor found yet!', 'wp-payment-form') ?></p>
                    </div>

                <?php endif; ?>
                <div class="wpf-leaderboard-loader">
                    <span class="loader hide"></span>
                </div>
                <?php if ($total > 0) : ?>
                    <div class="wpf-leaderboard-load-more-wrapper" >
                        <button class="wpf-load-more <?php echo $has_more_data == false ? 'disabled' : '' ?>"><?php esc_html_e('Load More', 'wp-payment-form') ?></button>
                    </div>
                <?php endif; ?>
             </div>
        </div>
    </div>
</div>