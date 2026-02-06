<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>

<head>
    <meta http-equiv="Content-type" content="text/html; charset=utf-8" />
    <meta http-equiv="Imagetoolbar" content="No" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <title><?php esc_html_e('Preview Form', 'wp-payment-form') ?></title>
    <?php
    wp_head();
    ?>
    <style type="text/css">
        .wpf_preview_title {
            display: inline-block;
            font-weight: bold;
            color: black !important;
        }

        .wpf_preview_title ul {
            list-style: none;
        }

        .wpf_preview_title ul {
            display: flex;
        }

        .wpf_preview_title ul li {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 20px;
            margin: 0;
            color: #2F3448;
            font-family: Inter;
            font-size: 15px;
            font-style: normal;
            font-weight: 500;
            line-height: 24px;
        }
        .vertical-line {  
            width: 1px;
            background: #EAECF0;
        } 
        .wpf_preview_title ul li a {
            color: #017EF3;
            font-family: Inter;
            font-size: 15px;
            font-style: normal;
            font-weight: 500;
            line-height: 24px;
            text-decoration-line: underline;
        }

        .wpf_preview_action {
            display: flex;
            padding: 7px 12px;
            justify-content: flex-end;
            align-items: center;
            gap: 5px;
            border-radius: 6px;
            background: #F5F6F7;
            color: #565865;
            text-align: center;
            font-family: "Inter", sans-serif;
            font-size: 13px;
            font-style: normal;
            font-weight: 500;
            line-height: 16px;
        }

        .wpf_preview_action img {
            height: 15px;
        }

        .wpf_preview_body {
            padding: 40px 0px 40px 0px;
            width: 100%;
            background-color: #dedede;
            min-height: 85vh;
        }

        .wpf_preview_header {
            /* top: 0px;
            left: 0;
            right: 0px;
            padding: 0px 20px 0px 0px;
            background-color: #ebedee;
            color: black;
            max-height: 60px;
            font-size: 18px; */
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #fff;
            color: black;
            max-height: 60px;
            font-size: 18px;
            padding: 0px 30px;
        }
        .wpf_preview_action_block {
                display: flex;
                align-items: center;
                gap: 10px;
            }

        .wpf_preview_footer {
            display: block;
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
            padding: 30px 0px;
        }

        html.wpf_go_full {
            padding-top: 0;
        }

        .wpf_go_full body {
            background: white;
        }

        .wpf_go_full .wpf_preview_body {
            background: white;
        }

        .wpf_go_full .wpf_preview_body #wpf_preview_top {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0;
            background: #feffff;
            color: #596075;
            box-shadow: 0px 5px 5px 6px #e6e6e6;
        }

        .wpf-preview-expand {
            border-radius: var(--radius-6, 6px);
            background: #F5F6F7;
            padding: 4px 9px;
        }
        .wpf-preview-contrast {
            border-radius: 6px;
            background: #F5F6F7;
            padding: 4px 9px;
        }
        .wpf_copy_notification {
            display: none;
            position: absolute;
            top: 11px;
            left: 50%;
            background-color: #e8f5f1;
            border-color: #d1eae4;
            color: #189877;
            font-size: 12px;
            line-height: 1;
            border-radius: 8px;
            min-width: 380px;
            transform: translateX(-50%);
            border-width: 1px;
            border-style: solid;
            padding: 10px 20px;
            align-items: center;
            gap: 10px;
        }
        /* .wpf_preview_container_action {
            margin: 10px;
        } */

        /* .wpf_preview_container_action span {
            font-size: 28px;
            color: #605858;
            cursor: pointer;
        } */

        .wpf_hide {
            display: none;
        }

        .wpf_go_full .wpf_preview_footer {
            display: none;
        }

        @media only screen and (max-width: 522px) {
            .wpf_preview_footer {
                padding: 30px;
                font-size: 15px;
            }

            .wpf_preview_title {
                font-size: 15px;
            }

            .wpf_preview_action {
                font-size: 15px;
            }
        }
        /* Step form css start */
        #wpf_svg_form_time {
            height: 15px;
            max-width: 80%;
            margin: 40px auto 20px;
            display: block;
        }

        #wpf_svg_form_time circle,
        #wpf_svg_form_time rect {
            fill: white;
        }

        .wpf_step_button {
            background: #ffffff;
            border-radius: 5px;
            padding: 10px 15px;
            display: inline-block;
            margin-right: 10px;
            font-weight: bold;
            color: #ff7518;
            cursor: pointer;
            border: 1px solid #D6DAE1;
            /* box-shadow: 0px 1px 2px rgb(0 0 0 / 30%); */
        }

        .wpf_step_disabled {
            display: none;
        }
        .wpf_step_section {
            padding: 2rem 0 !important;
        }


        /* Step Form */
        .step-form {
            display: flex;
            justify-content: center;
            text-align: center;
            overflow-x: auto;
        }
        .step-form.justify-start {
            justify-content: flex-start;
        }
        .step-form::-webkit-scrollbar {
            height: 4px;
            border-radius: 10px;
        }
        .step-form::-webkit-scrollbar-thumb {
            height: 4px;
            background: #ffd8c7;
            border-radius: 10px;
        }
        .step-form::-webkit-scrollbar-track {
            height: 4px;
            background: #e4e4e4;
            border-radius: 10px;
        }
        .step-form .step-form-item {
            flex-basis: 16.6667%;
            min-width: 130px;
            margin-bottom: 12px;
        }
        .step-form .step-form-item .step-form-item-content h2 {
            margin: 10px 0 0 0;
            font-size: 22px;
            padding-bottom: 3px;
            color:#d2d2d2
        }
        .step-form .step-form-item .step-form-item-content p {
            font-size: 14px;
            line-height: 20px;
            font-weight: 400;
            margin: 0;
        }
        .step-form .step-form-item .step-form-item-header {
            position: relative;
        }
        .step-form .step-form-item .step-form-item-header:before {
            content: '';
            width: 100%;
            height: 2px;
            position: absolute;
            left: 0;
            top: 50%;
            margin-top: -1px;
            background: #d2d2d2;
        }
        .step-form .step-form-item .step-form-item-header .number {
            position: relative;
            z-index: 1;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            width: 24px;
            height: 24px;
            font-size: 14px;
            background: #fff;
            transition: .15s ease-out;  
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid #d2d2d2;
            font-weight: 500;
            color: #6b6b6b;
        }
        .step-form .step-form-item.success .step-form-item-header .number {
            border-color: #ff7518;
            color: #ff7518;
        }
        .step-form .step-form-item.success .step-form-item-content h2 {
            color: #ff7518;
        }
        .step-form .step-form-item.success .step-form-item-header:before {
            background: #ff7518;
        }
        .step-form .step-form-item.active .step-form-item-header .number {
            border-color: #596075;
            color: #596075;
        }
        .step-form .step-form-item.active .step-form-item-content h2 {
            color: #596075;
        }
        .step-form .step-form-item.active .step-form-item-header:before {
            background: #596075;
        }

    </style>
</head>

<body>
    <div id="wpf_preview_top">
        <div class="wpf_preview_header">
            <div class="wpf_preview_title">
                <ul>
                    <li class="wpf_form_name">
                        <?php echo intval($form->ID) . ' - ' . esc_attr($form->post_title) . ' ( Preview )';  ?>
                    </li>
                    <div class="vertical-line"></div>
                    <li>
                        <img src="<?php echo esc_url(WPPAYFORM_URL . 'assets/'.'images/editor.svg') ?>" alt="" />
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wppayform.php#/edit-form/' . intval($form_id) . '/form-builder')) ?>">Edit Fields</a>
                    </li>
                </ul>
            </div>
            <div class="wpf_preview_action_block">
                <div class="wpf_preview_action">
                    <img src="<?php echo esc_url(WPPAYFORM_URL . 'assets/'.'images/copy-icon.svg') ?>" alt="">
                    [wppayform id="<?php echo intval($form_id); ?>"]
                </div>
                <div class="wpf_preview_container_action">
                    <span class=" wpf_hide wpf-preview-expand">
                        <img src="<?php echo esc_url(WPPAYFORM_URL . 'assets/'.'images/preview-max-min.svg') ?>" alt="">
                    </span>
                    <span class="wpf-preview-contrast">
                        <img src="<?php echo esc_url(WPPAYFORM_URL . 'assets/'.'images/preview-max-min.svg') ?>" alt="">
                    </span>
                </div>
            </div>
            <!-- Notification div -->
            <div id="wpf_copy_notification" class="wpf_copy_notification">
                <img src="<?php echo esc_url(WPPAYFORM_URL . 'assets/'.'images/payment-status/active.svg') ?>" alt="">
                [wppayform id="<?php echo intval($form_id); ?>"] copied successfully!
            </div>

        </div>
        <div class="wpf_preview_body">
            <div class="wpf_form_preview_wrapper">
                <?php echo do_shortcode('[wppayform id="' . intval($form_id) . '"]'); ?>
            </div>
        </div>
        <div class="wpf_preview_footer">
            <p>You are seeing preview version of Paymattic. This form is only accessible for Admin users. Other users
                may not access this page. To use this for in a page please use the following shortcode: [wppayform
                id='<?php echo intval($form_id); ?>']</p>
        </div>
    </div>
    <?php
    wp_footer();
    ?>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            var status = window.localStorage.getItem('wpf_full_screen_preview');
            if (status == 'no') {
                jQuery('html').toggleClass('wpf_go_full');
                $('.wpf-preview-contrast').toggleClass("wpf_hide");
                $('.wpf-preview-expand').toggleClass("wpf_hide");
            }

            $('.wpf-preview-contrast').on('click', function() {
                jQuery('html').toggleClass('wpf_go_full');
                $(this).toggleClass("wpf_hide")
                $('.wpf-preview-expand').toggleClass("wpf_hide");
                window.localStorage.setItem('wpf_full_screen_preview', 'no');
            });

            $('.wpf-preview-expand').on('click', function() {
                jQuery('html').toggleClass('wpf_go_full');
                $(this).toggleClass("wpf_hide")
                $('.wpf-preview-contrast').toggleClass("wpf_hide");
                window.localStorage.setItem('wpf_full_screen_preview', 'yes');
            });
        });
    </script>
        <script>
            lightGallery(document.getElementById('lightgallery'), {
                plugins: [lgZoom, lgThumbnail],
                licenseKey: 'your_license_key',
                speed: 500,
                // ... other settings
            });
        </script>
        <script type="text/javascript">
            jQuery(document).ready(function() {
                jQuery('.wpf_preview_action').click(function() {
                    var textToCopy = jQuery(this).clone()  // Clone the div
                                        .children('img') // Select the img element inside the div
                                        .remove()        // Remove the img element
                                        .end()           // Return to the cloned div
                                        .text().trim();  // Get the text and trim any whitespace

                    // Create a temporary input element to hold the text
                    var tempInput = jQuery('<input>');
                    jQuery('body').append(tempInput);
                    tempInput.val(textToCopy).select();
                    var success = document.execCommand('copy');

                    // Remove the temporary input element
                    tempInput.remove();
                    if (success) {
                        jQuery('#wpf_copy_notification').css("display", "flex").fadeIn(300).delay(1000).fadeOut(300);
                    }
                });
            });
        </script>
</body>

</html>