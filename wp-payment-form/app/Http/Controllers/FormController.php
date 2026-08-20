<?php

namespace WPPayForm\App\Http\Controllers;

use WPPayForm\App\Models\Form;
use WPPayForm\App\Services\AccessControl;
use WPPayForm\App\Services\FormPlaceholders;
use WPPayForm\App\Services\GeneralSettings;
use WPPayForm\App\Services\GlobalTools;
use WPPayForm\App\Models\Meta;
use WPPayForm\App\Modules\Builder\Render;

class FormController extends Controller
{
    public function index(Form $form, $formId)
    {
        $formId = absint($formId);
        try {
            return $form->getFormInfo($formId);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('Failed to load form data. Please try again.', 'wp-payment-form')
            ], 423);
        }
    }

    public function store(Form $form, $formId)
    {
        $formId = absint($formId);
        try {
            $builderSettings = $this->request->get('builder_settings');
            $form->saveForm($formId, $builderSettings, $this->request->get('submit_button_settings'));
            return (array(
                'message' => __('Settings successfully updated', 'wp-payment-form')
            ));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 423);
        }
    }


    public function remove($formId)
    {
        $formId = absint($formId);
        try {
            Form::deleteForm($formId);
            return array(
                'message' => __('Selected form successfully deleted', 'wp-payment-form')
            );
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 423);
        }
    }

    public function editors($formId)
    {
        $formId = absint($formId);
        $builderSettings = Form::getBuilderSettings($formId);
        $allComponents = GeneralSettings::getComponents();

        return array(
            'builder_settings' => $builderSettings,
            'components' => $allComponents,
            'form_button_settings' => Form::getButtonSettings($formId),
            'currency_settings' => Form::getCurrencyAndLocale($formId)
        );
    }


    public function saveIntegration(Meta $meta, $formId)
    {
        $formId = absint($formId);
        try {
            $insertId = $meta->saveIntegration($this->request->all(), $formId);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 423);
        }

        return [
            'message' => __('Settings has been saved.', 'wp-payment-form'),
            'settings' => json_decode($this->request->get('value'), true),
            'id' => $insertId
        ];
    }

    public function getIntegration(Meta $meta, $formId)
    {
        $formId = absint($formId);
        try {
            return $this->sendSuccess($meta->getIntegration($formId));
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 423);
        }
    }

    public function update(Form $form, $formId)
    {
        $formId = absint($formId);
        $request_data = $this->request->all();
        try {
            $form->updateForm($formId, $request_data);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 423);
        }

        return array(
            'message' => __('Form successfully updated', 'wp-payment-form')
        );
    }

    public function designSettings($formId)
    {
        $formId = absint($formId);
        return array(
            'layout_settings' => Form::getDesignSettings($formId)
        );
    }

    public function updateDesignSettings($formId)
    {
        $formId = absint($formId);
        $layoutSettings = wp_unslash($this->request->layout_settings);
        update_post_meta($formId, 'wppayform_form_design_settings', $layoutSettings);
        return array(
            'message' => __('Settings successfully updated', 'wp-payment-form')
        );
    }

    public function settings(Form $form, $formId)
    {
        $formId = absint($formId);
        $allPages = $form->getAllPages();
        $allPosts = $form->getAllPosts();

        return array(
            'confirmation_settings' => Form::getConfirmationSettings($formId),
            'receipt_settings' => Form::getReceiptSettings($formId),
            'currency_settings' => Form::getCurrencySettings($formId),
            'editor_shortcodes' => FormPlaceholders::getAllPlaceholders($formId),
            'currencies' => GeneralSettings::getCurrencies(),
            'locales' => GeneralSettings::getLocales(),
            'pages' => $allPages,
            'posts' => $allPosts,
            'recaptcha_settings' => GeneralSettings::getRecaptchaSettings(),
            'form_recaptcha_status' => get_post_meta($formId, '_recaptcha_status', true),
            'turnstile_settings' => GeneralSettings::getTurnstileSettings(),
            'form_turnstile_status' => get_post_meta($formId, '_turnstile_status', true),
        );
    }

    public function saveSettings(Form $form, $formId)
    {
        $formId = absint($formId);
        $request_data = $this->request->all();
        try {
            return $form->saveSettings($request_data, $formId);
        } catch (\Exception $e) {
            return $this->sendError(
                ['message' => $e->getMessage()],
                423
            );
        }
    }

    public function duplicateForm(GlobalTools $globalTools, $formId)
    {
        $formId = absint($formId);
        $oldForm = $globalTools->getForm($formId);

        if (!$oldForm) {
            return $this->sendError([
                'message' => __('No form found when duplicating the form', 'wp-payment-form')
            ], 423);
        }

        $oldForm['post_title'] = '(Duplicate) ' . $oldForm['post_title'];
        $oldForm = apply_filters('wppayform/form_duplicate', $oldForm);

        $newForm = $globalTools->createFormFromData($oldForm);
        return array(
            'message' => __('Form successfully duplicated', 'wp-payment-form'),
            'form' => $newForm
        );
    }

    public function export($formId)
    {
        $formId = absint($formId);
        $globalTools = new GlobalTools();
        $globalTools->exportFormJson($formId);
    }

    public function giftAidExport($formId)
    {
        if (!(defined('WPPAYFORMHASPRO') && WPPAYFORMHASPRO)) {
            wp_send_json_error(
                ['message' => __('Gift Aid export requires the Paymattic Pro plugin.', 'wp-payment-form')],
                403
            );
        }
        $formId      = absint($formId);

        $form = get_post($formId);
        if (!$form || $form->post_type !== 'wp_payform') {
            wp_send_json_error(
                ['message' => __('Form not found.', 'wp-payment-form')],
                404
            );
        }

        $userId     = get_current_user_id();
        $hasGrand   = AccessControl::hasGrandAccess();
        $canViewAll = $hasGrand || current_user_can('wpf_can_view_all_entries');
        $canViewOwn = current_user_can('wpf_can_view_entries_of_own_created_forms');

        if (!$canViewAll && !($canViewOwn && (int) $form->post_author === $userId)) {
            wp_send_json_error(
                ['message' => __('You do not have permission to export entries for this form.', 'wp-payment-form')],
                403
            );
        }

        $charityName = sanitize_text_field($this->request->get('charity_name', ''));
        $hmrcRef     = sanitize_text_field($this->request->get('hmrc_ref', ''));
        $dateFrom    = sanitize_text_field($this->request->get('date_from', ''));
        $dateTo      = sanitize_text_field($this->request->get('date_to', ''));

        if (empty($charityName) || empty($hmrcRef)) {
            wp_send_json_error(
                ['message' => __('Charity name and HMRC reference are required.', 'wp-payment-form')],
                400
            );
        }

        try {
            \WPPayForm\App\Services\GiftAidExporter::export($formId, [
                'charity_name' => $charityName,
                'hmrc_ref'     => $hmrcRef,
                'date_from'    => $dateFrom,
                'date_to'      => $dateTo,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => __('Export failed. Please try again.', 'wp-payment-form')], 500);
        }
    }

    // get currency rates
    public function getCurrencyRates($baseCurrency, $apiKey, $cachingInterVal, $formId)
    {
        $formId = absint($formId);
        $baseCurrency = sanitize_text_field($baseCurrency);
        $apiKey = sanitize_text_field($apiKey);
        $cachingInterVal = sanitize_text_field($cachingInterVal);
        $builderSettings = Form::getBuilderSettings($formId);
        $container_elements = (new Render)->getContainerElements($builderSettings);
        $builderSettings = array_merge($builderSettings, $container_elements);
        $ratesRequire = false;
        foreach ($builderSettings as $key => $value) {
            if ('donation_item' === $value['type'] ||  'currency_switcher' === $value['type']) {
                $ratesRequire = true;
            }
        }
        if ($ratesRequire) {
            $data = $this->getUpdatedCurrencyRates($baseCurrency, $cachingInterVal, $apiKey, $formId);
            return $data['rates'];
        }
        return [];
    }


    public function getUpdatedCurrencyRates($baseCurrency, $cachingInterVal, $apiKey, $formId)
    {
        $key = 'currency_convertion_from_' . $baseCurrency;
        $meta = new Meta();
        $data = $meta->getCurrencyMeta($key);
        $ratesValue = $data ? wppayform_safeUnserialize($data->meta_value) : [];

        if (!$data || empty($ratesValue)) {
            $rates = $this->getRatesFromApi($baseCurrency, $apiKey, $formId);
            $meta->updateCurrencyRates($rates, $key);
            return [
                'rates' => $rates
            ];
        }

        $updatedAt = new \DateTime($data->updated_at); // Convert $data->updated_at to a DateTime object
        // Calculate the difference in hours between the current time and $updatedAt
        $hoursDifference = (new \DateTime(current_time('mysql')))->diff($updatedAt)->h;
        if ($hoursDifference >= absint($cachingInterVal)) {
            $rates = $this->getRatesFromApi($baseCurrency, $apiKey, $formId);
            if (!empty($rates)) {
                $meta->updateCurrencyRates($rates, $key);
            }

            return [
                'rates' => $rates
            ];
        }

        return [
            'rates' => $ratesValue
        ];
    }

    public function getRatesFromApi($baseCurrency, $apiKey, $formId)
    {
        $url = 'https://api.currencyapi.com/v3/latest';
        $url = add_query_arg(array(
            'base_currency' => $baseCurrency,
            'apikey' => $apiKey,
        ), $url);

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return [];
        }
        $body = wp_remote_retrieve_body($response);

        $rates = [];
        $jsonData = json_decode($body, true);
        if (isset($jsonData['data'])) {
            $rates = $jsonData['data'];
        } else {
            do_action('wppayform_log_data', [
                'form_id' => $formId,
                'submission_id' => '',
                'type' => 'failed',
                'created_by' => 'Paymattic BOT',
                'title' => 'Currencyapi',
                'content' => $jsonData['message'] . ' - CurrecnyAPI(Check your API credentials, limitations of your current Currencyapi plan! )'
            ]);
        }
        return $rates;
    }
}
