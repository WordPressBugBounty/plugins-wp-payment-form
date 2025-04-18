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


    // this is not right place to put this function, but for now we are keeping it here, we will move it to a better place later
    public function getFluentPdfInfo()
    {
       
        $downloadableFontFiles = [];
        $fluentPdfUpdateAvailable = 'no';
        $fluentPdfActive = 'no';
        $fluentPdfUrl = 'https://api.github.com/repos/WPManageNinja/fluent-pdf/zipball/1.0.2'; // initial version, upon install request, it will fetch the latest version

        if (defined('FLUENT_PDF')) {
            $fluentPdfActive = 'yes';
            $downloadableFontFiles = (new \FluentPdf\Classes\Controller\FontDownloader())->getDownloadableFonts();
            $result = (new \FluentPdf\Classes\Controller\GlobalPdfConfig())->checkForUpdate('fluent-pdf');
            $fluentPdfUpdateAvailable = $result['available'];
            $fluentPdfUrl = $result['url'] ? $result['url'] : $fluentPdfUrl;

            wp_send_json_success([
                'fluent_pdf_update_available' => $fluentPdfUpdateAvailable,
                'fluent_pdf_active' => $fluentPdfActive,
                'fluent_pdf_url' => $fluentPdfUrl,
                'downloadable_font_files' => $downloadableFontFiles,
                'fluent_pdf_dashboard_url' => admin_url('admin.php?page=fluent_pdf.php')
            ]);
        }

        wp_send_json_success([
            'fluent_pdf_update_available' => $fluentPdfUpdateAvailable,
            'fluent_pdf_active' => $fluentPdfActive,
            'fluent_pdf_url' => $fluentPdfUrl,
            'downloadable_font_files' => $downloadableFontFiles,
            'fluent_pdf_dashboard_url' => admin_url('admin.php?page=fluent_pdf.php')
        ]);

    }
}
