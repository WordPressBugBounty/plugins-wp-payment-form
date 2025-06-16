<?php

namespace WPPayForm\App\Modules\PaymentMethods\Offline;

use WPPayForm\App\Modules\FormComponents\BaseComponent;
use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

class OfflineElement extends BaseComponent
{
    public $gateWayName = 'offline';

    public function __construct()
    {
        parent::__construct('offline_gateway_element', 10);
        add_action('wppayform/payment_method_choose_element_render_offline', array($this, 'renderForMultiple'), 10, 3);
        add_filter('wppayform/available_payment_methods', array($this, 'pushPaymentMethod'), 3, 1);
    }

    public function pushPaymentMethod($methods)
    {
        $methods['offline'] = array(
            'label' => __('Offline/Cheque Payment', 'wp-payment-form'),
            'isActive' => true,
            'logo' => WPPAYFORM_URL . 'assets/images/gateways/offline.svg',
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Payment Method Title',
                    'type' => 'text',
                    'default' => 'Direct bank transfer'
                ),
                'description' => array(
                    'label' => 'Payment instruction (will be shown on the form)',
                    'type' => 'html',
                    'default' => 'Make your payment directly into our bank account. Please use your Order ID as the payment reference. The payment will be marked as paid once the amount is deposited'
                )
            )
        );
        return $methods;
    }


    public function component()
    {
        return array(
            'type' => 'offline_gateway_element',
            'editor_title' => 'Offline/Cheque Payment',
            'editor_icon' => '',
            'conditional_hide' => true,
            'group' => 'payment_method_element',
            'method_handler' => $this->gateWayName,
            'postion_group' => 'payment_method',
            'single_only' => true,
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Payment Method Title',
                    'type' => 'text'
                ),
                'description' => array(
                    'label' => 'Payment instruction (will be shown on the form)',
                    'type' => 'html'
                )
            ),
            'field_options' => array(
                'label' => __('Direct bank transfer', 'wp-payment-form'),
                'description' => 'Make your payment directly into our bank account. Please use your Order ID as the payment reference. The payment will be marked as paid once the amount is deposited'
            )
        );
    }

    public function render($element, $form, $elements)
    {
        $value = Arr::get($element, 'field_options.label', 'offline');
        $controlClass = $this->elementControlClass($element);

        $controlAttributes = array(
            'id' => 'wpf_' . esc_attr($this->elementName),
            'data-element_type' => esc_attr($this->elementName),
            'class' => esc_attr($controlClass)
        );
        $fieldOptions = Arr::get($element, 'field_options'); ?>
        <?php unset($fieldOptions['label']); ?>
        <div <?php echo $this->builtAttributes($controlAttributes); ?>>
            <?php $this->buildLabel($fieldOptions, $form); ?><?php echo wp_kses(Arr::get($element, 'field_options.description'), wp_kses_allowed_html('post')); ?>
            
        </div>
        <?php
        echo '<input data-wpf_payment_method="offline" type="hidden" name="__offline_payment_gateway" value="' . esc_attr($value) . '" />';
    }

    public function renderForMultiple($paymentSettings, $form, $elements)
    {
        $component = $this->component();
        $component['id'] = 'offline_gateway_element';
        $component['field_options'] = $paymentSettings;
        $this->render($component, $form, $elements);
    }
}
