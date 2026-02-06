<div id="wpf-feedback" class="wpf-feedback-container" style="display: none;">
    <div class="wpf-feedback-form">
        <div class="header">
            <img class="logo" src=" <?php echo esc_url($logo); ?> " alt="logo">
            <h1 style="padding: 10px 0px;">
                <?php echo esc_html__('Your feedback is precious', 'wp-payment-form'); ?>
            </h1>
        </div>
        <p class="wpf-feed-description">
            <?php echo esc_html__('Please let us know why you are deactivating Paymattic. All submissions are anonymous and we only use this feedback to improve Paymattic.', 'wp-payment-form'); ?>
        </p>
        <form style="margin-bottom: 0px">
            <div class="form-body">
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="reason" value="I'm only deactivating temporarily">
                        <?php echo esc_html__('I\'m only deactivating temporarily', 'wp-payment-form'); ?>
                    </label>
                    <label>
                        <input id="wpf_feature_missing" type="checkbox" name="reason" value="Doesn’t have the feature I need">
                        <?php echo esc_html__('Doesn’t have the feature I need', 'wp-payment-form'); ?>
                    </label>
                    <div class="wpf_feature_missing wpf-hide">
                        <label> <?php echo esc_html__('What is the name of feature ? ', 'wp-payment-form'); ?> </label>
                        <input type="text" name="wpf_feature_missing" placeholder="Type the feature name">
                    </div>
                    <label>
                        <input type="checkbox" name="reason" value="I no longer need the plugin">
                        <?php echo esc_html__('I no longer need the plugin', 'wp-payment-form'); ?>
                    </label>
                    <label>
                        <input id="wpf_better_plugin_name" type="checkbox" name="reason" value="I found a better plugin">
                         <?php echo esc_html__('I found a better plugin', 'wp-payment-form'); ?>
                    </label>
                    <div class="wpf_better_plugin_name wpf-hide">
                        <label> <?php echo esc_html__('What is the name of plugin ? ', 'wp-payment-form'); ?> </label>
                        <input type="text" name="wpf_better_plugin_name" placeholder="Type the plugin name">
                    </div>
                    <label>
                        <input type="checkbox" name="reason" value="I only needed the plugin for a short period">
                        <?php echo esc_html__('I only needed the plugin for a short period', 'wp-payment-form'); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="reason" value="The plugin broke my site">
                        <?php echo esc_html__('The plugin broke my site', 'wp-payment-form'); ?>
                    </label>
                    <label>
                        <input type="checkbox" name="reason" value="This plugin stopped working">
                         <?php echo esc_html__('This plugin stopped working', 'wp-payment-form'); ?>
                    </label>
                    <label>
                        <input id="wpf_other_option" type="checkbox" name="reason" value="other">
                        <?php echo esc_html__('Other', 'wp-payment-form'); ?>
                    </label>
                </div>
                <span class="validation-message"> <?php echo esc_html__('Please select at least one reason for deactivating the plugin.', 'wp-payment-form'); ?></span>
                <span class="server-error-message">
                    <?php echo esc_html__('Sorry, Too many request from same site or IP address. Try again 24 hours later.', 'wp-payment-form'); ?>
                </span>
                <div class="wpf_other_message wpf-hide" style="margin-top: 10px">
                    <label>
                        <?php echo esc_html__('Would you like to tell us more?', 'wp-payment-form'); ?>
                    </label>
                    <textarea id="wpf-feedback-description" rows="2" placeholder="Message"></textarea>
                </div>
                <div class="wpf_support_link">
                    <a target="_blank" href="https://wpmanageninja.com/support-tickets/">
                        <?php echo esc_html__('Need help? Get in touch with our support team', 'wp-payment-form'); ?>
                    </a>
                </div>
            </div>
            <input type="hidden" name="paymattic_deactivation_nonce" value="<?php echo esc_attr( $nonce ) ?> ">
            <div class="btn-container">
                <a id="wpf-skip-deactivate" class="skip-btn" href="https://paymattic.com">
                    <?php echo esc_html__('Skip And Deactivate', 'wp-payment-form'); ?>
                </a>
                <div>
                    <button type="button" class="btn close" id="wpf-btn-close">
                        <?php echo esc_html__('Close', 'wp-payment-form'); ?>
                    </button>
                    <button id="wpf-feedback-submit" style="margin-right: 10px;" type="submit" class="btn">
                        <?php echo esc_html__('Deactivate', 'wp-payment-form'); ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>