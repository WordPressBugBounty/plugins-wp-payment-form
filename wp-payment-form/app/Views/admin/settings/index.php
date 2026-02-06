<?php

use WPPayForm\App\Modules\Builder\Helper;
use WPPayForm\Framework\Support\Arr;

?>
<?php do_action('wppayform_global_menu'); ?>
<?php $wppayform_asset_url = WPPAYFORM_URL . 'assets/images/'; ?>

<div class="payform_admin wpf_content_wrapper_parent_v2">
    <div class="payform_section_header wpf_global_settings_header">
        <h2 class="payform_gateway_title">
            <?php esc_html_e('Paymattic Global Settings', 'wp-payment-form'); ?>
        </h2>
    </div>
    <div class="wpf_content_wrapper_v2 gap-20">
        <?php do_action('wppayform_before_global_settings_wrapper'); ?>
        <div class="wppayform_sidebar_v2 border-radius-8">
            <ul class="wppayform_sidebar_list_v2" style="margin-top: 0px;">
                <li class="wpf_global_setting_component <?php echo esc_attr(Helper::getHtmlElementClass('settings', $currentComponent)); ?>">
                    <a data-hash="settings" href="<?php echo esc_url(Helper::makeMenuUrl('wppayform_settings', [
                        'hash' => 'settings'
                    ])); ?>">
                    <div class="wpf_setting_title">
                        <?php echo '<img src="' . esc_url($wppayform_asset_url) . 'form/settings.svg" />'; ?>
                        <p>
                            <?php echo esc_html__('General Settings', 'wp-payment-form'); ?>
                        </p>
                    </div>
                    <div class="wpf_setting_forward_icon">
                        <?php echo '<img src="' . esc_url($wppayform_asset_url) . 'global/forward.svg" />'; ?>
                    </div>
                    </a>
                </li>
                <?php foreach ($components as $wppayform_component_name => $wppayform_component): ?>
                    <li class="wpf_global_setting_component <?php echo esc_attr(Helper::getHtmlElementClass($wppayform_component['hash'], $currentComponent)); ?> wppayform_item_<?php echo esc_attr($wppayform_component_name); ?>" data-wppayform-settings-list="">
                        <a data-settings_key="<?php echo esc_attr(Arr::get($wppayform_component, 'settings_key')); ?>"
                            data-component="<?php echo esc_attr(Arr::get($wppayform_component, 'component', '')); ?>"
                            data-hash="<?php echo esc_attr(Arr::get($wppayform_component, 'hash', '')); ?>"
                            href="<?php echo esc_url(Helper::makeMenuUrl('wppayform_settings', $wppayform_component)); ?>">
                            <div class="wpf_setting_title">
                                <?php
                                    $wppayform_component_title = strtolower($wppayform_component['title']);
                                    $wppayform_component_img = Arr::get($wppayform_component, 'svg', '') ? Arr::get($wppayform_component, 'svg', '') : '<img class="el-icon-discount" src="' . $wppayform_asset_url . 'integrations/' . $wppayform_component_title . '.svg' . '"/>';
                                    echo wp_kses_post($wppayform_component_img);
                                ?>
                                <p>
                                    <?php echo esc_html($wppayform_component['title']) ?>
                                </p>
                            </div>
                            <div class="wpf_setting_forward_icon">
                            <?php echo '<img src="' . esc_url($wppayform_asset_url) . 'global/forward.svg" />'; ?>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="wppayform_main_content_v2 border-radius-8">
            <?php
            do_action('wppayform_global_settings_component_' . $currentComponent);
            ?>
        </div>
        <?php do_action('wppayform_after_global_settings_wrapper'); ?>
    </div>
</div>

<script>
    var settingsListItems = document.querySelectorAll('[data-wppayform-settings-list]');
    settingsListItems.forEach(function (item) {
        item.addEventListener('click', function () {
            if (typeof jQuery !== 'undefined') {
                jQuery('html, body').animate({
                    scrollTop: 0
                }, 500);
            }
        });
    });
</script>
