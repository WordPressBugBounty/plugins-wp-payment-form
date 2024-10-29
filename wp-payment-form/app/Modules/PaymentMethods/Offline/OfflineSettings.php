<?php

namespace WPPayForm\App\Modules\PaymentMethods\Offline;

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Services\AccessControl;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class OfflineSettings
{
    /**
     * @function mapperSettings, To map key => value before store
     * @function validateSettings, To validate before save settings
     */
    public function init()
    {
        add_filter('wppayform_payment_method_settings_mapper_offline', array($this, 'mapperSettings'));
        add_filter('wppayform_payment_method_settings_validation_offline', array($this, 'validateSettings'), 10, 2);
    }
    /**
     * @return Array of global fields
     */
    public function globalFields() {
        return array(
            'payment_mode' => array(
                'value' => 'test',
                'label' => __('Payment Mode', 'wp-payment-form'),
                'options' => array(
                    'test' => __('Test Mode', 'wp-payment-form'),
                    'live' => __('Live Mode', 'wp-payment-form')
                ),
                'type' => 'payment_mode'
            ),

        );
    }
     /**
     * @return Array of default fields
     */
    public static function settingsKeys()
    {
        return array(
            'payment_mode' => 'test',
        );
    }

     /**
     * @return Array of global_payments settings fields
     */
    public function getPaymentSettings()
    {
        $settings = $this->mapper(
            $this->globalFields(), 
            static::getSettings()
        );

        return array(
            'settings' => $settings,
        );
    }

     /**
     * @return Array of settings for checkout
     * Set defaults fields
     */
    public static function getSettings()
    {
        $settings = get_option('wppayform_payment_settings_offline', []);
        return wp_parse_args($settings, static::settingsKeys());
    }

    public function mapperSettings ($settings) {
        return $this->mapper(
            static::settingsKeys(), 
            $settings, 
            false
        );
    }

    public function savePaymentMethodSettings($request, $method)
    {
        $settings = $request->settings;
        $settings = apply_filters('wppayform_payment_method_settings_mapper_' . $method, $settings);
        $validationErrors = apply_filters('wppayform_payment_method_settings_validation_' . $method, [], $settings);

        if ($validationErrors) {
            wp_send_json_error([
                'message' => __('Failed to save settings', 'wp-payment-form'),
                'errors'  => $validationErrors
            ], 423);
        }

        $settings = apply_filters('wppayform_payment_method_settings_save_' . $method, $settings);

        update_option('wppayform_payment_settings_' . $method, $settings, 'yes');

        do_action('wppayform/before_save_payment_settings_' . $method, $settings);

        return array(
            'message' => __('Settings successfully updated', 'wp-payment-form')
        );
    }

     /**
     * This method will set key value pair for dynamic bindings
     * @return Default values for save Settings
     */
    public function mapper($defaults, $settings = [], $get = true) 
    {
        foreach ($defaults as $key => $value) {
            if ($get) {
                if (isset($settings[$key])) {
                    $defaults[$key]['value'] = $settings[$key];
                }
            } else {
                if (isset($settings[$key])) {
                    $defaults[$key] = $settings[$key]['value'];
                }
            }
        }

        return $defaults;
    }


    public function validateSettings($errors, $settings)
    {
        AccessControl::checkAndPresponseError('set_payment_settings', 'global');
        $mode = Arr::get($settings, 'payment_mode');
        if (empty($mode)) {
            $errors['payment_mode'] = __('Please Select a payment mode.', 'wp-payment-form');
        }
        return $errors;
    }
}
