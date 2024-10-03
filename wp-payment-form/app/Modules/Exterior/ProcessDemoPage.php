<?php

namespace WPPayForm\App\Modules\Exterior;

use WPPayForm\App\App;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Services\AccessControl;

class ProcessDemoPage
{
    public function handleExteriorPages()
    {
        if (isset($_GET['wp_paymentform_preview']) && sanitize_text_field(wp_unslash($_GET['wp_paymentform_preview']))) {
            $hasDemoAccess = AccessControl::hasTopLevelMenuPermission();
            $hasDemoAccess = apply_filters('wppayform/can_see_demo_form', $hasDemoAccess);
            $onlyPreviewPage = "no";
            if (isset($_GET['template']) && sanitize_text_field(wp_unslash($_GET['template']))) {
                $onlyPreviewPage = $_GET['template'] == "yes" ? "yes" : "no";
            }
            if (!current_user_can($hasDemoAccess)) {
                $accessStatus = AccessControl::giveCustomAccess();
                $hasDemoAccess = $accessStatus['has_access'];
            }

            if ($hasDemoAccess) {
                $formId = intval(sanitize_text_field(wp_unslash($_GET['wp_paymentform_preview'])));
                if ($onlyPreviewPage == "yes") {
                    $formId = sanitize_text_field(wp_unslash($_GET['wp_paymentform_preview']));
                }
                wp_enqueue_style('dashicons');
                $this->loadDefaultPageTemplate();
                $this->renderPreview($formId, $onlyPreviewPage);
            }
        }
    }

    public function renderPreview($formId, $onlyPreviewPage)
    {
        $form = Form::getForm($formId);
        if ($onlyPreviewPage == "yes") {
            App::make('view')->render('admin.template_preview', [
                'form_id' => $formId,
            ]);
            exit();
        }
        else if ($form) {
            App::make('view')->render('admin.show_review', [
                'form_id' => $formId,
                'form' => $form,
                'only_preview_page' => $onlyPreviewPage,
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

    // do not call this migration admin notice, as it is not needed now.
    // But it can be used in future for reference.
    // Note: to call an admin notice do not use 'wp' hook, use 'admin_notices' hook in the admin context only.
    public function injectAgreement()
    {
        add_action('wp_ajax_paymattic_pro_version_update_notice_dismiss', function () {
            // do something if needed
        });

        add_action('admin_notices', function () {
            ?>
            <style>
                .wpf_migration_notice {
                    margin: 10px 0px;
                    /* border-radius: 8px; */
                    border: 1px solid #c3c4c7;
                    border-left-width: 4px;
                    border-left-color: #db2d17;
                    background: #fff; 
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
                    flex-wrap: wrap;
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
                .paymattic_notice_dismiss:before{
                    background: none;
                    color: #787c82;
                    content: "\f153";
                    display: block;
                    font: normal 16px / 20px dashicons;
                    speak: never;
                    height: 20px;
                    text-align: center;
                    width: 20px;
                    -webkit-font-smoothing: antialiased;
                }
                .screen-reader-text {
                    clip-path: rect(1px, 1px, 1px, 1px);
                    position: absolute !important;
                    height: 1px;
                    width: 1px;
                    overflow: hidden;
                }
            </style>
            <div class='wpf_migration_notice'>
                 <div class="wpf_notice_title">
                    <h3 class="wpf_title">Looks like you are using an  <strong>Outdated version of Paymattic Pro!</strong></h3>
                 </div>
                <button  class="paymattic_notice_dismiss paymattic_notice_dismiss_close">
                     <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
                <p style="color: #525866; font-size: 14px; font-weight: 400; margin: 8px 0px 0px 0px">
                Please update to the latest version to avoid any issue.
                </p>
                <!-- <br> -->
            </div>
            <script>
                jQuery(document).ready(function () {
                    jQuery('.paymattic_notice_dismiss').click(function () {
                        jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            action: 'paymattic_pro_version_update_notice_dismiss',
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
