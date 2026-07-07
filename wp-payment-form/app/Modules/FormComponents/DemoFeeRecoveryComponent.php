<?php

namespace WPPayForm\App\Modules\FormComponents;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lite stub for the Fee Recovery field (fee_recovery_item).
 *
 * Registers the component metadata so the field appears in the form builder's
 * "Add more field" dropdown for Lite users. Clicking the field triggers the
 * standard pro-upgrade modal (is_pro = 'yes').
 *
 * When Paymattic Pro is active this class is NOT instantiated — Pro's own
 * FeeRecoveryComponent takes over via the same wppayform/form_components filter.
 * InitComponents::__init() guards instantiation with !defined('WPPAYFORMPRO_VERSION').
 *
 * @package WPPayForm\App\Modules\FormComponents
 * @since   4.6.22
 */
class DemoFeeRecoveryComponent extends BaseComponent
{
    public function __construct()
    {
        parent::__construct('fee_recovery_item', 9);
    }

    public function component()
    {
        return array(
            'type'             => 'fee_recovery_item',
            'is_pro'           => 'yes',
            'editor_title'     => 'Fee Recovery',
            'group'            => 'payment',
            'postion_group'    => 'payment',
            'single_only'      => true,
            'is_system_field'  => true,
            'is_payment_field' => true,
            'quick_checkout_form' => true,
            'editor_elements'  => array(
                'fee_recovery_settings' => array(
                    'type'  => 'fee_recovery_settings',
                    'group' => 'general',
                    'label' => 'Fee Recovery Settings',
                ),
                'admin_label'        => array(
                    'label' => 'Admin Label',
                    'type'  => 'text',
                    'group' => 'advanced',
                ),
                'wrapper_class'      => array(
                    'label' => 'Field Wrapper CSS Class',
                    'type'  => 'text',
                    'group' => 'advanced',
                ),
                'element_class'      => array(
                    'label' => 'Input Element CSS Class',
                    'type'  => 'text',
                    'group' => 'advanced',
                ),
                'conditional_render' => array(
                    'type'              => 'conditional_render',
                    'group'             => 'advanced',
                    'label'             => 'Conditional Render',
                    'selection_type'    => 'Conditional logic',
                    'conditional_logic' => array('yes' => 'Yes', 'no' => 'No'),
                    'conditional_type'  => array('any' => 'Any', 'all' => 'All'),
                ),
            ),
            'field_options' => array(
                'disable'      => false,
                'label'        => 'I\'d like to cover the {fee_amount} transaction fee.',
                'rate_type'    => 'global_rate',
                'donor_opt_in' => false,
                'global_rate'  => array('percent' => 2.9, 'fixed' => 30),
                'gateway_rates' => array(),
                'conditional_logic_option' => array(
                    'conditional_logic' => 'no',
                    'conditional_type'  => 'any',
                    'options'           => array(
                        array('target_field' => '', 'condition' => '', 'value' => ''),
                    ),
                ),
            ),
        );
    }

    public function render($element, $form, $elements)
    {
        return;
    }
}
