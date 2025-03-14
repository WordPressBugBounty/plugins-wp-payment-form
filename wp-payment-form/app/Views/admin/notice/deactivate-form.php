<div id="wpf-feedback" class="wpf-feedback-container" style="display: none;">
    <div class="wpf-feedback-form">
        <div class="header">
            <img class="logo" src=" <?php echo esc_url($logo); ?> " alt="logo">
            <h1 style="padding: 10px 0px;"> Your feedback is precious</h1>
        </div>
        <p class="wpf-feed-description">
            Please let us know why you are deactivating Paymattic. All submissions are anonymous and we only use this feedback to improve Paymattic.
        </p>
        <form style="margin-bottom: 0px">
            <div class="form-body">
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="reason" value="I'm only deactivating temporarily">
                        I'm only deactivating temporarily
                    </label>
                    <label>
                        <input id="wpf_feature_missing" type="checkbox" name="reason" value="Doesn’t have the feature I need">
                        Doesn’t have the feature I need
                    </label>
                    <div class="wpf_feature_missing wpf-hide">
                        <label>What is the name of feature ? </label>
                        <input type="text" name="wpf_feature_missing" placeholder="Type the feature name">
                    </div>
                    <label>
                        <input type="checkbox" name="reason" value="I no longer need the plugin">
                        I no longer need the plugin
                    </label>
                    <label>
                        <input id="wpf_better_plugin_name" type="checkbox" name="reason" value="I found a better plugin">
                        I found a better plugin
                    </label>
                    <div class="wpf_better_plugin_name wpf-hide">
                        <label>What is the name of plugin ? </label>
                        <input type="text" name="wpf_better_plugin_name" placeholder="Type the plugin name">
                    </div>
                    <label>
                        <input type="checkbox" name="reason" value="I only needed the plugin for a short period">
                        I only needed the plugin for a short period
                    </label>
                    <label>
                        <input type="checkbox" name="reason" value="The plugin broke my site">
                        The plugin broke my site
                    </label>
                    <label>
                        <input type="checkbox" name="reason" value="This plugin stopped working">
                        This plugin stopped working
                    </label>
                    <label>
                        <input id="wpf_other_option" type="checkbox" name="reason" value="other">
                        other
                    </label>
                </div>
                <span class="validation-message">Please select at least one reason for deactivating the plugin.</span>
                <span class="server-error-message">Sorry, Too many request from same site or IP address. Try again 24 hours later</span>
                <div class="wpf_other_message wpf-hide" style="margin-top: 10px">
                    <label>Would you like to tell us more?</label>
                    <textarea id="wpf-feedback-description" rows="2" placeholder="Message"></textarea>
                </div>
                <div class="wpf_support_link">
                    <a target="_blank" href="https://wpmanageninja.com/support-tickets/">Need help? Get in touch with our support team</a>
                </div>
            </div>
            <input type="hidden" name="paymattic_deactivation_nonce" value="<?php echo esc_attr( $nonce ) ?> ">
            <div class="btn-container">
                <a id="wpf-skip-deactivate" class="skip-btn" href="https://paymattic.com">Skip And Deactivate</a>
                <div>
                    <button type="button" class="btn close" id="wpf-btn-close">Close</button>
                    <button id="wpf-feedback-submit" style="margin-right: 10px;" type="submit" class="btn">Deactivate</button>
                </div>
            </div>
        </form>
    </div>
</div>