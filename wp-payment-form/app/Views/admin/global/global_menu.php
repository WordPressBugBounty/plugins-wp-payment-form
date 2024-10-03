<?php
if(isset($_GET['page'])) {
    $page = sanitize_text_field(wp_unslash($_GET['page']));
}
if ($is_paymattic_user) {
    return;
}
?>
<div class="wppayform_main_nav">
    <div class="wpf-navbar-content">
    <div class="wpf_navbar_left">
        <img src="<?php echo esc_url($brand_logo); ?>">
        <h2>Paymattic</h2>
    </div>
    <div class="wpf_navbar_menu">
        <a href="<?php echo esc_url(admin_url('admin.php?page=wppayform.php#/')); ?>" class="ninja-tab wpf-route-forms">
            <?php esc_html_e('All Forms', 'wp-payment-form'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wppayform.php#/entries')); ?>" class="ninja-tab wpf-route-entries">
            <?php esc_html_e('Entries', 'wp-payment-form'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wppayform.php#/integrations')); ?>"
            class="ninja-tab wpf-route-integrations">
            <?php esc_html_e('Integrations', 'wp-payment-form'); ?>
        </a>

        <a href="<?php echo esc_url(admin_url('admin.php?page=wppayform.php#/reports')); ?>" class="ninja-tab wpf-route-reports">
            <?php esc_html_e('Reports', 'wp-payment-form'); ?>
        </a>

        <a href="<?php echo esc_url(admin_url('admin.php?page=wppayform.php#/gateways/stripe')); ?>"
            class="ninja-tab wpf-route-gateways">
            <?php esc_html_e('Payment Gateway', 'wp-payment-form'); ?>
        </a>

        <a href="<?php echo esc_url(admin_url('admin.php?page=wppayform_settings')); ?>"
            class="ninja-tab <?php echo ($page == 'wppayform_settings') ? 'ninja-tab-active' : '' ?>">
            <?php esc_html_e('Settings', 'wp-payment-form'); ?>
        </a>

        <!-- <div class="wppayform-fullscreen-main">
            <span id="wpf-contract-btn"
                class="wpf-contract-btn dashicons dashicons-editor-contract" style="font-size: 24px; padding-left: 4px">
            </span>
            <span id="wpf-expand-btn"
                class="wpf-expand-btn el-icon-full-screen" style="font-size: 20px; font-weight: 500; padding-left: 4px">
            </span>
        </div> -->

        <?php do_action('wppayform_after_global_menu'); ?>
        <?php if (!defined('WPPAYFORMHASPRO')): ?>
        <div class="ninja-tab wpf_buy_pro_tab">
            <!-- <span class="dashicons dashicons-cart"></span> -->
            <img src="<?= esc_url(WPPAYFORM_URL . 'assets/images/crown.svg') ?>" alt="No Found" />
            <a target="_blank" rel="noopener" href="<?php echo esc_url(wppayformUpgradeUrl()); ?>" class="wpf_pro_link">
                <?php esc_html_e('Upgrade to Pro', 'wp-payment-form'); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
    </div>
</div>