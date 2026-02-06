<div class="wpf-leaderboard-temp-one wpf-template-wrapper"
    data-show-total="<?php echo $show_total == 'true' ? 'true' : 'false'; ?>"
    data-show-name="<?php echo $show_name == 'true' ? 'true' : 'false'; ?>"
    data-show-avatar="<?php echo $show_avatar == 'true' ? 'true' : 'false'; ?>">
    <div class="wpf-leaderboard">
        <div class="wpf-user-column">
            <!-- Top 3 donor section start -->
            <div class="wpf-top-donor-card-wrapper">
                <div class="wpf-top-donor-cards">
                    <?php $wppayform_top = 0; ?>
                    <?php foreach ($topThreeDonars as $wppayform_key => $wppayform_top_three_donar):
                        $wppayform_top = $wppayform_top + 1;
                        $wppayform_class = "card-" . $wppayform_top;
                        ?>
                        <div class="wpf-top-donor-card <?php echo esc_attr($wppayform_class) ?>">
                            <div class="wpf-user-serial">
                                <span class="wpf-user-serial-text">
                                    <?php echo esc_html($wppayform_top) ?>
                                </span>
                            </div>
                            <div class="info">
                                <?php if ($show_avatar == 'true'): ?>
                                    <div class="wpf-user-avatar">
                                        <?php echo get_avatar($wppayform_top_three_donar['customer_email'], 96); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($show_name == 'true'): ?>
                                    <div class="wpf-user-name">
                                        <span class="wpf-user-name-text">
                                            <?php echo esc_html($wppayform_top_three_donar['customer_name']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($show_total == 'true'): ?>
                                    <div class="wpf-user-amount">
                                        <span class="wpf-text-currency">
                                            <?php echo esc_html($wppayform_top_three_donar['currency']) ?>
                                        </span>
                                        <span class="wpf-text-amount">
                                            <?php echo esc_html($wppayform_top_three_donar['grand_total']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- donor filter section -->
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
            <!-- donor list section -->
            <div class="wpf-user" data-per-page="<?php echo esc_attr($per_page) ?>" data-orderby="<?php echo esc_attr($orderby) ?>"
                data-form_id="<?php echo esc_attr($form_id) ?>">

                <?php
                $wppayform_donar_index = 0;
                foreach ($donars as $wppayform_key => $wppayform_donor):

                    ?>
                    <div class="wpf-user-row">
                        <div class="wpf-user-serial">
                            <span class="wpf-user-serial-text">
                                <?php echo esc_html(++$wppayform_donar_index) ?>
                            </span>
                        </div>
                        <?php if ($show_avatar == 'true'): ?>
                            <div class="wpf-user-avatar">
                                <?php echo get_avatar($wppayform_donor['customer_email'], 96); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($show_name == 'true'): ?>
                            <div class="wpf-user-name">
                                <span class="wpf-user-name-text">
                                    <?php echo esc_html($wppayform_donor['customer_name']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if ($show_total == 'true'): ?>
                            <div class="wpf-user-amount">
                                <span class="wpf-user-amount-text"><?php esc_html_e('Amount Donated', 'wp-payment-form') ?></span>
                                <span class="wpf-user-amount">
                                    <span class="wpf-text-currency">
                                        <?php echo esc_html($wppayform_donor['currency']) ?>
                                    </span>
                                    <span class="wpf-text-amount">
                                        <?php echo esc_html($wppayform_donor['grand_total']) ?>
                                    </span>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($total <= 0) : ?>
                <div class="wpf-no-donor-found">
                    <img src="<?php echo esc_url($nodonorData) ?>" alt="No Donor Found" class="wpf-no-donor-found-image" style="width: 280px">
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
    </div>
</div>