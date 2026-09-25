<?php

namespace WPPayForm\App\Modules\FormComponents;

use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

class ItemQuantityComponent extends BaseComponent
{
    public function __construct()
    {
        parent::__construct('item_quantity', 5);
        add_filter('wppayform/validate_component_on_save_item_quantity', array($this, 'validateOnSave'), 1, 3);
        add_filter('wppayform/validate_data_on_submission_item_quantity', array($this, 'validateOnSubmission'), 1, 4);
    }

    public function component()
    {
        return array(
            'type' => 'item_quantity',
            'is_pro' => 'no',
            'editor_title' => 'Item Quantity',
            'group' => 'item_quantity',
            'postion_group' => 'payment',
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
                'default_value' => array(
                    'label' => 'Default Quantity',
                    'type' => 'text',
                    'group' => 'general'
                ),
                'target_product' => array(
                    'label' => 'Target Payment Item',
                    'type' => 'product_selector',
                    'group' => 'general',
                    'info' => 'Please select the product in where the quantity will be applied'
                ),
                'min_value' => array(
                    'label' => 'Minimum Quantity',
                    'type' => 'number',
                    'group' => 'general',
                    'min' => 0
                ),
                'max_value' => array(
                    'label' => 'Maximum Quantity',
                    'type' => 'number',
                    'group' => 'general',
                    'min' => 0
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
                'conditional_logic_option' => array(
                    'conditional_logic' => 'no',
                    'conditional_type'  => 'any',
                    'options' => array(
                        array(
                            'target_field' => '',
                            'condition' => '',
                            'value' => ''
                        )
                    ),
                ),
                'disable' => false,
                'label' => 'Quantity',
                'placeholder' => 'Provide Quantity',
                'required' => 'yes',
                'min_value' => 1,
                'target_product' => ''
            )
        );
    }

    public function validateOnSave($error, $element, $formId)
    {
        $disable = Arr::get($element, 'field_options.disable', false);

        if ($disable) {
            return;
        }

        if (!Arr::get($element, 'field_options.target_product')) {
            $error = __('Target Product is required for item:', 'wp-payment-form') . ' ' . Arr::get($element, 'field_options.label');
        }

        $minValue = Arr::get($element, 'field_options.min_value');
        $maxValue = Arr::get($element, 'field_options.max_value');
        $label    = Arr::get($element, 'field_options.label');

        if ($minValue !== null && $minValue !== '' && (int) $minValue < 0) {
            return __('Minimum Quantity must be 0 or greater for item:', 'wp-payment-form') . ' ' . $label;
        }

        if ($maxValue !== null && $maxValue !== '' && (int) $maxValue < 0) {
            return __('Maximum Quantity must be 0 or greater for item:', 'wp-payment-form') . ' ' . $label;
        }

        if (
            $minValue !== null && $minValue !== '' &&
            $maxValue !== null && $maxValue !== '' &&
            (int) $maxValue < (int) $minValue
        ) {
            return __('Maximum Quantity must be greater than or equal to Minimum Quantity for item:', 'wp-payment-form') . ' ' . $label;
        }

        return $error;
    }

    public function validateOnSubmission($error, $elementId, $element, $form_data)
    {
        $disable = Arr::get($element, 'options.disable', false);
        if ($disable) {
            return;
        }

        if ($error) {
            return $error;
        }

        $itemValue = Arr::get($form_data, $elementId);
        $formId    = Arr::get($form_data, '__wpf_form_id');
        $minValue  = Arr::get($element, 'options.min_value');
        $maxValue  = Arr::get($element, 'options.max_value');
        $hasMin    = $minValue !== null && $minValue !== '';

        // Empty string, null, or explicit "0" all mean "field not filled / optional item skipped".
        // Explicit rather than !$itemValue — '0' is falsy in PHP, so relying on that is fragile.
        if ($itemValue === '' || $itemValue === null || $itemValue === '0') {
            if ($hasMin && (int) $minValue > 0) {
                return $this->getErrorLabel(
                    $element,
                    $formId,
                    /* translators: %d: minimum quantity value */
                    sprintf(__('Minimum quantity is %d', 'wp-payment-form'), (int) $minValue)
                );
            }
            return $error;
        }

        // At this point $itemValue is a non-empty, non-zero string. Require a valid integer ≥ 1.
        $intValue = filter_var($itemValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($intValue === false) {
            return $this->getErrorLabel($element, $formId, __('Quantity must be a valid positive whole number.', 'wp-payment-form'));
        }

        if ($hasMin && $intValue < (int) $minValue) {
            return $this->getErrorLabel(
                $element,
                $formId,
                /* translators: %d: minimum quantity value */
                sprintf(__('Minimum quantity is %d', 'wp-payment-form'), (int) $minValue)
            );
        }

        if ($maxValue !== null && $maxValue !== '' && $intValue > (int) $maxValue) {
            return $this->getErrorLabel(
                $element,
                $formId,
                /* translators: %d: maximum quantity value */
                sprintf(__('Maximum quantity is %d', 'wp-payment-form'), (int) $maxValue)
            );
        }
        return $error;
    }

    public function render($element, $form, $elements)
    {
        $fieldOptions = Arr::get($element, 'field_options', false);
        $disable = Arr::get($fieldOptions, 'disable', false);
        $hidden_attr = Arr::get($element, 'field_options.conditional_logic_option.conditional_logic')  === 'yes' ? 'none' : 'block';


        if (!$fieldOptions || $disable) {
            return;
        }
        $controlClass = $this->elementControlClass($element);
        $inputClass = $this->elementInputClass($element);
        $inputId = 'wpf_input_' . $form->ID . '_' . $element['id'];

        $defaultValue = '';
        if (isset($fieldOptions['default_value'])) {
            $defaultValue = $fieldOptions['default_value'];
        }

        $defaultValue = apply_filters('wppayform/input_default_value', $defaultValue, $element, $form);

        $attributes = array(
            'data-required' => Arr::get($fieldOptions, 'required'),
            'data-type' => 'input',
            'name' => $element['id'],
            'placeholder' => Arr::get($fieldOptions, 'placeholder'),
            'value' => $defaultValue,
            'type' => 'number',
            'min' => Arr::get($fieldOptions, 'min_value', '0'),
            'max' => Arr::get($fieldOptions, 'max_value'),
            'class' => $inputClass . ' wpf_item_qty',
            'data-target_product' => Arr::get($fieldOptions, 'target_product'),
            'id' => $inputId,
            'autocomplete' => 'off'
        );

        if (Arr::get($fieldOptions, 'required') == 'yes') {
            $attributes['required'] = true;
        }

        ?>
        <div style = "display : <?php echo esc_attr($hidden_attr); ?>" data-element_type="<?php echo esc_attr($this->elementName); ?>"
             class="<?php echo esc_attr($controlClass); ?>">
            <?php $this->buildLabel($fieldOptions, $form, array('for' => $inputId)); ?>
            <div class="wpf_input_content">
                <input <?php $this->printAttributes($attributes); ?> />
            </div>
        </div>
        <?php
    }
}
