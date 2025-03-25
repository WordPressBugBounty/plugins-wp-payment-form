<?php

namespace WPPayForm\App\Modules\FormComponents;

use WPPayForm\App\Modules\FormComponents\BaseComponent;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Form;


if (!defined('ABSPATH')) {
    exit;
}

class DemoDynamicPaymentItemComponent extends BaseComponent
{
    public function __construct()
    {
        parent::__construct('dynamic_payment_item', 6);
    }

    public function component()
    {
        return array(
            'type' => 'dynamic_payment_item',
            'editor_title' => 'Dynamic Payment Item',
            'group' => 'payment',
            'postion_group' => 'payment',
            'is_pro' => 'yes',
            'is_system_field' => true,
            'is_payment_field' => true,
            'isNumberic' => 'yes',
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Field Label',
                    'type' => 'text',
                    'group' => 'general'
                ),
                'placeholder' => array(
                    'label' => 'Placeholder',
                    'type' => 'text',
                    'group' => 'general'
                ),
                'required' => array(
                    'label' => 'Required',
                    'type' => 'switch',
                    'group' => 'general'
                ),
                'numeric_calculation' => array(
                    'label' => 'Enable Calculation',
                    'type' => 'numeric_calculation',
                    'group' => 'general',
                ),
                'admin_label' => array(
                    'label' => 'Admin Label',
                    'type' => 'text',
                    'group' => 'advanced'
                ),
                'wrapper_class' => array(
                    'label' => 'Field Wrapper CSS Class',
                    'type' => 'text',
                    'group' => 'advanced'
                ),
                'element_class' => array(
                    'label' => 'Input Element CSS Class',
                    'type' => 'text',
                    'group' => 'advanced'
                ),
                'conditional_render' => array(
                    'type' => 'conditional_render',
                    'group' => 'advanced',
                    'label' => 'Conditional render',
                    'selection_type' => 'Conditional logic',
                    'conditional_logic' => array(
                        'yes' => 'Yes',
                        'no' => 'No'
                    ),
                    'conditional_type' => array(
                        'any' => 'Any',
                        'all' => 'All'
                    ),
                ),
            ),
            'field_options' => array(
                'disable' => false,
                'label' => 'Dynamic Payment Item',
                'placeholder' => '',
                'required' => 'no',
                'numeric_calculation' => 'yes',
                'calculation_expression' => '',
                'conditional_logic_option' => array(
                    'conditional_logic' => 'no',
                    'conditional_type' => 'any',
                    'options' => array(
                        array(
                            'target_field' => '',
                            'condition' => '',
                            'value' => ''
                        )
                    ),
                ),
            )
        );
    }

    public function render($element, $form, $elements)
    {
        return;
    }
}
?>