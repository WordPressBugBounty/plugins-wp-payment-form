<?php

namespace WPPayForm\App\Modules\Exterior;

use WPPayForm\App\App;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Services\AccessControl;

class ProcessDemoPage
{
    public function handleExteriorPages()
    {
        if (isset($_GET['wp_paymentform_preview']) && $_GET['wp_paymentform_preview']) {
            $hasDemoAccess = AccessControl::hasTopLevelMenuPermission();
            $hasDemoAccess = apply_filters('wppayform/can_see_demo_form', $hasDemoAccess);

            if (!current_user_can($hasDemoAccess)) {
                $accessStatus = AccessControl::giveCustomAccess();
                $hasDemoAccess = $accessStatus['has_access'];
            }

            if ($hasDemoAccess) {
                $formId = intval($_GET['wp_paymentform_preview']);
                wp_enqueue_style('dashicons');
                $this->loadDefaultPageTemplate();
                $this->renderPreview($formId);
            }
        }
    }

    public function renderPreview($formId)
    {
        $form = Form::getForm($formId);
        if ($form) {
            App::make('view')->render('admin.show_review', [
                'form_id' => $formId,
                'form' => $form
            ]);
            exit();
        }
    }

    private function loadDefaultPageTemplate()
    {
        add_filter('template_include', function ($original) {
            return locate_template(array('page.php', 'single.php', 'index.php'));
        }, 999);
    }

    /**
     * Set the posts to one
     *
     * @param WP_Query $query
     *
     * @return void
     */
    public function preGetPosts($query)
    {
        if ($query->is_main_query()) {
            $query->set('posts_per_page', 1);
            $query->set('ignore_sticky_posts', true);
        }
    }

    public function injectAgreement()
    {

        add_action('wp_ajax_paymattic_update_notice_dismiss', function() {
            $next_period = current_time('timestamp') + WEEK_IN_SECONDS;
            update_option('paymattic_ui_update_notice', $next_period);
        });

        add_action('admin_notices', function () {
            ?>
            <style>
                .wpf_migration_notice {
                    margin: 10px 0px;
                    border-radius: 8px;
                    border: 1px solid #E1E4EA;
                    background: #ffffff;
                    padding: 12px;
                    position: relative;
                    width: calc(100% - 25px);
                    margin-left: -10px;
                    line-height: 10px;
                }
                .paymattic_notice_dismiss_close {
                    float: right;
                    cursor: pointer;
                    border: none;
                    background: none;
                    font-size: 18px;
                    position: absolute;
                    top: 10px;
                    right: 10px;
                }
                .wpf_notice_title {
                    display: flex;
                    align-items: center;
                    gap: 4px;
                }
                .wpf_title {
                    margin: 0;
                    font-family: Inter;
                    font-size: 14px;
                    font-style: normal;
                    font-weight: 500;
                    line-height: 20px;
                    letter-spacing: -0.084px;
                    color: #0E121B;
                }
            </style>
            <div class='wpf_migration_notice'>
                <!-- <img width="360" src="<?php echo sanitize_url(WPPAYFORM_URL . 'assets/images/migration.png'); ?>" alt="Payform Migrated to Paymattic"> -->
                 <div class="wpf_notice_title">
                 <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                    <path d="M14.3434 1.8642C14.3932 1.70763 14.3794 1.53771 14.3049 1.39124C14.2304 1.24477 14.1013 1.13352 13.9454 1.08159C13.7895 1.02966 13.6194 1.04122 13.4719 1.11376C13.3245 1.18631 13.2115 1.314 13.1575 1.4692L12.5325 3.34337C12.4827 3.49995 12.4965 3.66987 12.571 3.81634C12.6455 3.96281 12.7747 4.07405 12.9306 4.12598C13.0865 4.17792 13.2566 4.16636 13.404 4.09381C13.5514 4.02127 13.6644 3.89357 13.7184 3.73837L14.3434 1.8642ZM18.15 1.85004C18.2671 1.96723 18.3328 2.12608 18.3328 2.2917C18.3328 2.45733 18.2671 2.61618 18.15 2.73337L16.0667 4.8167C16.0095 4.87811 15.9405 4.92736 15.8638 4.96152C15.7872 4.99568 15.7044 5.01405 15.6205 5.01553C15.5366 5.01701 15.4532 5.00157 15.3754 4.97014C15.2976 4.93871 15.2269 4.89192 15.1675 4.83257C15.1082 4.77322 15.0614 4.70253 15.0299 4.6247C14.9985 4.54688 14.9831 4.46352 14.9846 4.3796C14.986 4.29568 15.0044 4.21292 15.0386 4.13626C15.0727 4.05959 15.122 3.99059 15.1834 3.93337L17.2667 1.85004C17.3839 1.733 17.5428 1.66725 17.7084 1.66725C17.874 1.66725 18.0329 1.733 18.15 1.85004ZM7.56338 3.59837C7.70011 3.31445 7.90404 3.06816 8.15748 2.88086C8.41091 2.69357 8.70621 2.57092 9.01777 2.52355C9.32932 2.47618 9.64772 2.50552 9.94538 2.60903C10.243 2.71253 10.5109 2.88708 10.7259 3.11754L16.5642 9.37254C16.775 9.59843 16.9287 9.87146 17.0126 10.1688C17.0964 10.4662 17.1079 10.7794 17.046 11.0821C16.9842 11.3848 16.8509 11.6684 16.6572 11.9091C16.4635 12.1498 16.215 12.3407 15.9325 12.4659L13.0825 13.7292C13.4291 14.5355 13.4437 15.4461 13.1232 16.2631C12.8026 17.0801 12.1727 17.7378 11.3702 18.0932C10.5678 18.4487 9.65746 18.4734 8.83694 18.1618C8.01641 17.8503 7.35187 17.2277 6.98755 16.4292L5.80671 16.9525C5.53665 17.0722 5.23726 17.1095 4.9461 17.0597C4.65494 17.0098 4.38497 16.8752 4.17005 16.6725L2.97088 15.5417C2.73903 15.3232 2.58279 15.0365 2.52481 14.7232C2.46682 14.4099 2.5101 14.0863 2.64838 13.7992L7.56338 3.59837ZM8.13171 15.9225C8.36696 16.4092 8.78215 16.7854 9.28958 16.9716C9.79702 17.1579 10.357 17.1396 10.8512 16.9206C11.3454 16.7017 11.7352 16.2993 11.9382 15.7983C12.1412 15.2973 12.1416 14.7371 11.9392 14.2359L8.13171 15.9225ZM9.81171 3.97087C9.73548 3.88918 9.64049 3.82731 9.53496 3.7906C9.42943 3.75389 9.31654 3.74346 9.20607 3.7602C9.0956 3.77695 8.99087 3.82036 8.90096 3.88669C8.81104 3.95302 8.73865 4.04026 8.69005 4.14087L3.77505 14.3417C3.75185 14.3896 3.74452 14.4435 3.75411 14.4959C3.7637 14.5482 3.78972 14.596 3.82838 14.6325L5.02755 15.7625C5.06334 15.7963 5.10829 15.8187 5.15676 15.827C5.20523 15.8353 5.25507 15.8291 5.30005 15.8092L15.4259 11.3234C15.5262 11.279 15.6144 11.2114 15.6832 11.126C15.752 11.0406 15.7994 10.9401 15.8214 10.8326C15.8434 10.7252 15.8394 10.6141 15.8098 10.5086C15.7801 10.403 15.7256 10.3061 15.6509 10.2259L9.81171 3.97087ZM15.8334 6.8742C15.8334 6.70844 15.8992 6.54947 16.0164 6.43226C16.1336 6.31505 16.2926 6.2492 16.4584 6.2492H18.125C18.2908 6.2492 18.4498 6.31505 18.567 6.43226C18.6842 6.54947 18.75 6.70844 18.75 6.8742C18.75 7.03996 18.6842 7.19894 18.567 7.31615C18.4498 7.43336 18.2908 7.4992 18.125 7.4992H16.4584C16.2926 7.4992 16.1336 7.43336 16.0164 7.31615C15.8992 7.19894 15.8334 7.03996 15.8334 6.8742Z" fill="#2F3448"/>
                </svg>
                    <h3 class="wpf_title">Exciting News: A better Paymattic UI is coming 🥳</h3>
                 </div>
                <button  class="paymattic_notice_dismiss paymattic_notice_dismiss_close">
                    x
                </button>
                <p style="color: #525866; font-size: 14px; font-weight: 400; margin-bottom: 0px">
                    This is the news we’ve been waiting to announce for seven months. Our team has been working tirelessly to develop a new user interface. The main goal is a better user experience for you. This time, we are making things simpler. And the multi-column containers are coming too.
                </p>
                <br>
                <p style="color: #525866; font-size: 14px; font-weight: 400; margin: 0px">
                    We’ll roll out this new UI in the next update. Get ready for a smoother, easier, and better UX with Paymattic.
                </p>
                <br>
            </div>
            <script>
                jQuery(document).ready(function () {
                    jQuery('.paymattic_notice_dismiss').click(function () {
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'paymattic_update_notice_dismiss',
                        }).then( (res) => {
                            jQuery('.wpf_migration_notice').remove();
                        });
                    });
                });
            </script>
            <?php
        });
    }
}
