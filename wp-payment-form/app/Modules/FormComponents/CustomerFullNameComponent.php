<?php

namespace WPPayForm\App\Modules\FormComponents;

use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

class CustomerFullNameComponent extends BaseComponent
{
    protected $componentName = 'customer_full_name';

    public function __construct()
    {
        parent::__construct($this->componentName, 12);
        add_filter('wppayform/submitted_value_' . $this->componentName, array($this, 'formatValue'), 10, 1);
    }

    public function component()
    {
        return [
            'type' => $this->componentName,
            'active_page' => 0,
            'is_pro' => 'no',
            'quick_checkout_form' => true,
            'editor_title' => 'Full Name',
            'disable' => false,
            'group' => 'input',
            'postion_group' => 'general',
            'isNumberic' => 'no',
            'editor_elements' => [
                'label' => [
                    'label' => 'Field Label',
                    'type' => 'text',
                    'group' => 'general'
                ],
                'required' => [
                    'label' => 'Required',
                    'type' => 'switch',
                    'group' => 'general'
                ],
                'name_layout' => [
                    'label' => 'Name Layout',
                    'type' => 'checkbox',
                    'options' => [
                        'first_name' => 'First Name',
                        'middle_name' => 'Middle Name',
                        'last_name' => 'Last Name'
                    ],
                    'group' => 'general'
                ],
                'default_first_name' => [
                    'label' => 'Default First Name',
                    'type' => 'text',
                    'group' => 'general'
                ],
                'default_middle_name' => [
                    'label' => 'Default Middle Name',
                    'type' => 'text',
                    'group' => 'general'
                ],
                'default_last_name' => [
                    'label' => 'Default Last Name',
                    'type' => 'text',
                    'group' => 'general'
                ],
                'admin_label' => [
                    'label' => 'Admin Label',
                    'type' => 'text',
                    'group' => 'advanced'
                ],
                'wrapper_class' => [
                    'label' => 'Field Wrapper CSS Class',
                    'type' => 'text',
                    'group' => 'advanced'
                ],
                'element_class' => [
                    'label' => 'Input Element CSS Class',
                    'type' => 'text',
                    'group' => 'advanced'
                ],
                'conditional_render' => [
                    'type' => 'conditional_render',
                    'group' => 'advanced',
                    'label' => 'Conditional render',
                    'selection_type' => 'Conditional logic',
                    'conditional_logic' => [
                        'yes' => 'Yes',
                        'no' => 'No'
                    ],
                    'conditional_type' => [
                        'any' => 'Any',
                        'all' => 'All'
                    ],
                ],
            ],
            'field_options' => [
                'label' => 'Full Name',
                'required' => 'yes',
                'disable' => false,
                'name_layout' => ['first_name', 'last_name'],
                'default_first_name' => '',
                'default_middle_name' => '',
                'default_last_name' => '',
                'name_layout_labels' => [
                    'first_name' => 'First Name',
                    'middle_name' => 'Middle Name',
                    'last_name' => 'Last Name'
                ],
                'name_layout_placeholders' => [
                    'first_name' => 'First Name',
                    'middle_name' => 'Middle Name',
                    'last_name' => 'Last Name'
                ],
                'conditional_logic_option' => [
                    'conditional_logic' => 'no',
                    'conditional_type'  => 'any',
                    'options' => [
                        [
                            'target_field' => '',
                            'condition' => '',
                            'value' => ''
                        ]
                    ],
                ],
            ]
        ];
    }

    public function render($element, $form, $elements)
    {
        $nameLayout = Arr::get($element, 'field_options.name_layout', ['first_name', 'last_name']);
        $nameLayout = array_filter($nameLayout);

        // Ensure fields are in the correct order
        $orderedLayout = [];
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (in_array($field, $nameLayout)) {
                $orderedLayout[] = $field;
            }
        }
        $nameLayout = $orderedLayout;

        // Early return if disabled
        if (Arr::get($element, 'field_options.disable')) {
            return;
        }

        $controlClass = $this->elementControlClass($element);
        $baseElementId = $element['id'];
        $inputClass = $this->elementInputClass($element);

        // Check for conditional logic
        $hasCondition = Arr::get($element, 'field_options.conditional_logic_option.conditional_logic') === 'yes';
        $hidden_attr = $hasCondition ? 'none' : 'block';
        $conditionalClass = $hasCondition ? 'wpf_has_condition' : '';

        $attributes = [
            'data-type'     => 'input',
            'condition_id'  => $baseElementId,
            'type'          => 'text',
            'class'         => $inputClass,
        ];

        // Calculate column width based on number of fields
        $columnCount = count($nameLayout);
        $columnWidth = $columnCount > 0 ? (100 / $columnCount) - 2 : 100 / $columnCount;
        
        $this->renderNameFields($element, $form, $nameLayout, $controlClass, $conditionalClass, $hidden_attr, $baseElementId, $attributes, $columnWidth);
    }

    /**
     * Render the name fields
     */
    private function renderNameFields($element, $form, $nameLayout, $controlClass, $conditionalClass, $hidden_attr, $baseElementId, $attributes, $columnWidth)
    {
        ?>
        <div style="display: <?php echo esc_attr($hidden_attr); ?>"
            data-element_type="<?php echo esc_attr($this->elementName); ?>"
            condition_id="<?php echo esc_attr($baseElementId); ?>"
            class="<?php echo esc_attr($controlClass . ' ' . $conditionalClass); ?>">
            <div class="wpf_names_row" style="display: flex; gap: 15px; flex-wrap: wrap;">
                <?php
                foreach ($nameLayout as $namePart) {
                    $nameElement = $element;
                    $nameElement['field_options']['label'] = Arr::get($nameElement, 'field_options.name_layout_labels.' . $namePart, ucfirst(str_replace('_', ' ', $namePart)));
                    $attributes = $this->buildAttributes($attributes, $form, $nameElement, $namePart, $baseElementId);
                    $this->renderNameField($nameElement, $form, $attributes, $columnWidth);
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render an individual name field
     */
    private function renderNameField($nameElement, $form, $attributes, $columnWidth)
    {
        ?>
        <div class="wpf_name_field_column" style="flex: 1; width: <?php echo esc_attr($columnWidth); ?>%;">
            <div class="wpf_label_content">
                <?php $this->buildLabel($nameElement['field_options'], $form, ['for' => $attributes['id']]); ?>
            </div>
            <div class="wpf_input_content">
                <input <?php $this->printAttributes(attributes: $attributes); ?> />
            </div>
        </div>
        <?php
    }

    /**
     * Format the name fields into a single full name
     * 
     * @param array $value
     * @return string
     */
    public function formatValue($value)
    {
        if (is_array($value) && !empty($value)) {
            // Define the order of name parts
            $order = ['first_name', 'middle_name', 'last_name'];
            $formattedParts = [];
            
            // Get each name part in the correct order
            foreach ($order as $part) {
                if (isset($value[$part]) && !empty($value[$part])) {
                    $formattedParts[] = $value[$part];
                }
            }
            
            // Join the parts with spaces
            return implode(' ', $formattedParts);
        }
        
        return $value;
    }

    public function buildAttributes($attributes, $form, $nameElement, $namePart, $baseElementId)
    {
        $attributes['data-required'] = Arr::get($nameElement, 'field_options.required', 'no');
        $attributes['id'] = $baseElementId . '_' . $form->ID . '_' . $namePart;
        $attributes['name'] = $baseElementId . '[' . $namePart . ']';
        $attributes['placeholder'] = Arr::get($nameElement, 'field_options.name_layout_placeholders.' . $namePart, '');
        $defaultKey = 'default_' . $namePart;
        $defaultValue = Arr::get($nameElement, 'field_options.' . $defaultKey, '');
        // Apply filters for default value
        $defaultValue = apply_filters('wppayform/input_default_value', $defaultValue, $nameElement, $form);
        $attributes['value'] = $defaultValue;
        $attributes['data-name-part'] = $namePart;
        $attributes['data-name-group'] = $baseElementId;
        $attributes['data-name-label'] = Arr::get($nameElement, 'field_options.name_layout_labels.' . $namePart, ucfirst(str_replace('_', ' ', $namePart)));
        return $attributes;
    }
}
