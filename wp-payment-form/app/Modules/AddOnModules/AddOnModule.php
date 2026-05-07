<?php

namespace WPPayForm\App\Modules\AddOnModules;

use WPPayForm\App\Services\GeneralSettings;
use WPPayForm\Framework\Support\Arr;

class AddOnModule
{
    /**
     * Show the add-ons list.
     */
    public static function showAddOns()
    {
        $purchaseUrl = wppayformUpgradeUrl();
        $status = get_option('wppayform_integration_status');

        $addOns = apply_filters('wppayform_global_addons', []);

        $addOns['slack'] = [
            'title' => 'Slack',
            'description' => 'Get realtime notification in slack channel when a new submission will be added.',
            'logo' => WPPAYFORM_URL . '/assets/images/integrations/slack.png',
            'enabled' => GeneralSettings::isModuleEnabled('slack') ? 'yes' : 'no',
            'category' => 'crm'
        ];

        $addOns['zapier'] = [
            'title' => 'Zapier',
            'description' => 'Get realtime notification in zapier channel when a new submission will be added.',
            'logo' => WPPAYFORM_URL . '/assets/images/integrations/zapier.png',
            'config_url' => admin_url('admin.php?page=wppayform.php#/integrations/zapier'),
            'is_configured' =>  'no',
            'enabled' => GeneralSettings::isModuleEnabled('zapier') ? 'yes' : 'no',
            'purchase_url' => $purchaseUrl,
            'category' => 'crm',
            'btnTxt'       => 'Upgrade To Pro'
        ];
        $addOns['webhook'] = [
            'title' => 'Webhook',
            'description' => 'Broadcast your Paymattic Submission to any web api endpoint with the powerful webhook module.',
            'logo' => WPPAYFORM_URL . '/assets/images/integrations/webhook.png',
            'enabled' => GeneralSettings::isModuleEnabled('webhook') ? 'yes' : 'no',
            'config_url' => admin_url('admin.php?page=wppayform.php#/integrations/webhook'),
            'is_configured' =>  'no',
            'purchase_url' => $purchaseUrl,
            'category' => 'crm',
            'btnTxt'       => 'Upgrade To Pro'
        ];

        if (!defined('WPPAYFORMHASPRO')) {
            $addOns = array_merge($addOns, self::getPremiumAddOns());
        }
        if (!defined('FLUENTCRM')) {
            $addOns = array_merge($addOns, self::getFluentCrm());
        }

        if (!defined('FLUENT_SUPPORT_VERSION')) {
            $addOns = array_merge($addOns, self::getFluentSupport());
        }

        $addOns = apply_filters('wppayform_global_addons', $addOns);

        return array(
            'status' => $status,
            'addOns' => $addOns
        );
    }

    public function updateAddOnsStatus($request)
    {
        $addons = wp_unslash(Arr::get($request, 'addons'));
        update_option('wppayform_global_modules_status', $addons, 'no');

        return [
            'message' => 'Status successfully updated'
        ];
    }


    public static function getPremiumAddOns()
    {
        $purchaseUrl = wppayformUpgradeUrl();
        return array(
            'activecampaign'    => array(
                'title'        => 'ActiveCampaign',
                'description'  => 'Paymattic ActiveCampaign Module allows you to create ActiveCampaign list signup forms in WordPress, so you can grow your email list.',
                'logo'         => WPPAYFORM_URL . 'assets/images/integrations/activecampaign.png',
                'enabled'      => 'no',
                'purchase_url' => $purchaseUrl,
                'category'     => 'crm',
                'btnTxt'       => 'Upgrade To Pro'
            ),
            'UserRegistration'  => array(
                'title'        => 'User Registration',
                'description'  => 'Create WordPress user when a form is submitted.',
                'logo'         => WPPAYFORM_URL . 'assets/images/integrations/user_registration.png',
                'enabled'      => 'no',
                'purchase_url' => $purchaseUrl,
                'category'     => 'wp_core',
                'btnTxt'       => 'Upgrade To Pro',
            ),
            // 'webhook' => array(
            //     'title' => 'Webhook',
            //     'description' => 'Broadcast your Paymattic Submission to any web api endpoint with the powerful webhook module.',
            //     'logo' => WPPAYFORM_URL . '/assets/images/integrations/webhook.png',
            //     'enabled' => 'no',
            //     'config_url' => '',
            //     'category' => 'crm',
            //     'purchase_url' => $purchaseUrl,
            //     'btnTxt'       => 'Upgrade To Pro',
            // ),
            'sms_notification' => array(
                'title' => 'Twilio',
                'description' => 'Send SMS in real time when a form is submitted with Twilio.',
                'logo' => WPPAYFORM_URL . 'assets/images/integrations/twilio.png',
                'enabled' => 'no',
                'category' => 'crm',
                'purchase_url' => $purchaseUrl,
                'btnTxt'       => 'Upgrade To Pro',
            ),
            'telegram' => array(
                'title' => 'Telegram Messenger',
                'description' => 'Send notification to Telegram channel or group when a form is submitted',
                'logo' => WPPAYFORM_URL . 'assets/images/integrations/telegram.png',
                'enabled' => 'no',
                'category' => 'crm',
                'purchase_url' => $purchaseUrl,
                'btnTxt'       => 'Upgrade To Pro',
            ),
            'googlesheets'    => array(
                'title'        => 'Google Sheets',
                'description'  => 'Add Paymattic Forms Submission to Google sheets when a form is submitted.',
                'logo'         => WPPAYFORM_URL . 'assets/images/integrations/google-sheets.png',
                'enabled'      => 'no',
                'purchase_url' => $purchaseUrl,
                'category'     => 'crm',
                'btnTxt'       => 'Upgrade To Pro'
            ),
            'learndash'   => array(
                'title'        => 'LearnDash',
                'description'  => 'Connect LearnDash with Paymattic and subscribe a contact when a form is submitted.',
                'logo'         =>  WPPAYFORM_URL . 'assets/images/integrations/learndash.png',
                'enabled'      => 'no',
                'purchase_url' => $purchaseUrl,
                'category'     => 'lms',
                'btnTxt'       => 'Upgrade To Pro'
            ),
            'lifterlms'   => array(
                'title'        => 'LifterLMS',
                'description'  => 'Connect LifterLMS with Paymattic and subscribe a contact when a form is submitted.',
                'logo'         =>  WPPAYFORM_URL . 'assets/images/integrations/lifterlms.png',
                'enabled'      => 'no',
                'purchase_url' => $purchaseUrl,
                'category'     => 'lms',
                'btnTxt'       => 'Upgrade To Pro'
            ),
            'tutorlms'   => array(
                'title'        => 'TutorLMS',
                'description'  => 'Connect TutorLMS with Paymattic and subscribe a contact when a form is submitted.',
                'logo'         =>  WPPAYFORM_URL . 'assets/images/integrations/tutorlms.png',
                'enabled'      => 'no',
                'purchase_url' => $purchaseUrl,
                'category'     => 'lms',
                'btnTxt'       => 'Upgrade To Pro'
            ),
        );
    }

    public static function getFluentCrm()
    {
        return array(
            'fluent-crm'   => array(
                'title'        => 'Fluent CRM',
                'description'  => 'Connect FluentCRM with Paymattic and subscribe a contact when a form is submitted',
                'logo'         =>  WPPAYFORM_URL . 'assets/images/integrations/fluentcrm-logo.png',
                'enabled'      => 'no',
                'purchase_url' => 'https://wordpress.org/plugins/fluent-crm/',
                'category'     => 'crm',
                'btnTxt'       => 'Install & Activate'
            ),
        );
    }

    public static function getFluentSupport()
    {
        return array(
            'fluent-support'   => array(
                'title'        => 'Fluent Support',
                'description'  => 'Paymattic\'s connection with Fluent Support enables you to take payments from users in return of services.',
                'logo'         =>  WPPAYFORM_URL . 'assets/images/integrations/fluentsupport.svg',
                'enabled'      => 'no',
                'purchase_url' => 'https://wordpress.org/plugins/fluent-support/',
                'category'     => 'crm',
                'btnTxt'       => 'Install & Activate'
            ),
        );
    }


    public function installFluentPdf()
    {
        if (!current_user_can('install_plugins')) {
            wp_send_json_error(
                ['message' => __('You do not have permission to install plugins.', 'wp-payment-form')],
                403
            );
        }

        $slug        = 'fluentforms-pdf';
        $title       = 'Fluent Forms PDF';
        $pluginFile  = $slug . '/' . $slug . '.php';
        $redirectUrl = self::getFluentPdfDashboardUrl();

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (is_plugin_active($pluginFile)) {
            wp_send_json_success([
                'message'      => sprintf(
                    /* translators: %s: add-on plugin name */
                    __('%s is already active.', 'wp-payment-form'),
                    $title
                ),
                'redirect_url' => $redirectUrl,
            ], 200);
            return;
        }

        $plugins = get_plugins();

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!isset($plugins[$pluginFile])) {
            (new \WPPayForm\App\Services\BackgroundInstaller())->install([
                'name'      => $title,
                'repo-slug' => $slug,
                'file'      => $slug . '.php',
            ]);

            wp_clean_plugins_cache();

            if (!file_exists(WP_PLUGIN_DIR . '/' . $pluginFile)) {
                wp_send_json_error([
                    'message' => __(
                        'Installation failed. Please install Fluent Forms PDF manually from wordpress.org.',
                        'wp-payment-form'
                    ),
                ], 423);
            }

            if (!is_plugin_active($pluginFile)) {
                $result = activate_plugin($pluginFile);
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()], 423);
                }
            }
        } else {
            $result = activate_plugin($pluginFile);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 423);
            }
        }

        wp_send_json_success([
            'message'      => sprintf(
                /* translators: %s: add-on plugin name */
                __('Successfully installed %s', 'wp-payment-form'),
                $title
            ),
            'redirect_url' => $redirectUrl,
        ], 200);
    }

    // this is not right place to put this function, but for now we are keeping it here, we will move it to a better place later
    public function getFluentPdfInfo()
    {
        $downloadableFontFiles = [];
        $fluentPdfUpdateAvailable = 'no';
        $fluentPdfActive = 'no';
        $fluentPdfUrl = 'https://wordpress.org/plugins/fluentforms-pdf/';
        $fluentPdfLatestUrl = $fluentPdfUrl;
        $fluentPdfDashboardUrl = self::getFluentPdfDashboardUrl();
        $fluentPdfVersion = '';
        $fluentPdfBelowMinSupported = false;
        $minSupportedFluentPdfVersion = '2.0.0';

        if (defined('FLUENT_PDF')) {
            $fluentPdfActive = 'yes';
            $fluentPdfVersion = defined('FLUENT_PDF_VERSION') ? FLUENT_PDF_VERSION : '';
            if ($fluentPdfVersion && version_compare($fluentPdfVersion, $minSupportedFluentPdfVersion, '<')) {
                $fluentPdfBelowMinSupported = true;
            }
            $downloadableFontFiles = (new \FluentPdf\Classes\Controller\FontDownloader())->getDownloadableFonts();
            $pdfConfig = new \FluentPdf\Classes\Controller\GlobalPdfConfig();
            if (method_exists($pdfConfig, 'checkForUpdate')) {
                $result = $pdfConfig->checkForUpdate('fluent-pdf');
                $fluentPdfUpdateAvailable = $result['available'];
                $fluentPdfLatestUrl = $result['url'] ? $result['url'] : $fluentPdfUrl;
            }
        }

        wp_send_json_success([
            'fluent_pdf_update_available'     => $fluentPdfUpdateAvailable,
            'fluent_pdf_active'               => $fluentPdfActive,
            'fluent_pdf_url'                  => $fluentPdfLatestUrl,
            'downloadable_font_files'         => $downloadableFontFiles,
            'fluent_pdf_fonts_ready'          => self::hasUsableFonts() ? 'yes' : 'no',
            'fluent_pdf_dashboard_url'        => $fluentPdfDashboardUrl,
            'fluent_pdf_version'              => $fluentPdfVersion,
            'fluent_pdf_below_min_supported'  => $fluentPdfBelowMinSupported,
            'fluent_pdf_min_supported'        => $minSupportedFluentPdfVersion,
            'fluent_pdf_plugins_url'          => admin_url('plugins.php'),
        ]);
    }

    public static function hasUsableFonts()
    {
        if (!defined('FLUENT_PDF')) {
            return false;
        }

        if (!class_exists('\FluentPdf\Classes\Controller\FontDownloader')) {
            return false;
        }

        $fontDownloader = new \FluentPdf\Classes\Controller\FontDownloader();

        if (method_exists($fontDownloader, 'isBaselineMissing')) {
            return !$fontDownloader->isBaselineMissing();
        }

        if (!class_exists('\FluentPdf\Classes\Controller\AvailableOptions')) {
            return false;
        }

        $dirs = \FluentPdf\Classes\Controller\AvailableOptions::getDirStructure();
        $fontDir = isset($dirs['fontDir']) ? rtrim($dirs['fontDir'], '/') . '/' : '';
        if ($fontDir === '') {
            return false;
        }

        foreach (['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'] as $font) {
            if (!file_exists($fontDir . $font)) {
                return false;
            }
        }

        return true;
    }

    public static function isLegacyFluentPdf()
    {
        return defined('FLUENT_PDF_VERSION')
            && version_compare(FLUENT_PDF_VERSION, '2.0.0', '<');
    }

    public static function getFluentPdfDashboardUrl()
    {
        $default = admin_url('options-general.php?page=fluent_pdf_settings');

        if (self::isLegacyFluentPdf()) {
            $default = admin_url('admin.php?page=fluent_pdf.php');
        }

        return apply_filters('fluent_pdf/global_settings_url', $default);
    }
}
