<?php

namespace WPPayForm\App\Modules\FormComponents\Container;

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Modules\FormComponents\BaseComponent;

if (!defined('ABSPATH')) {
    exit;
}

class TwoColumnContainer extends BaseComponent
{
    public function __construct()
    {
        parent::__construct('container', 19);
    }

    public function component()
    {
        return array(
            'type' => 'container',
            'quick_checkout_form' => false,
            'conditional_hide' => true,
            'editor_title' => 'Two Column Container',
            'is_pro' => 'no',
            'group' => 'general',
            'is_markup' => 'no',
            'postion_group' => 'general',
            'isNumberic' => 'no',
            'editor_elements' => array(
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
                // 'conditional_render' => array(
                //     'type' => 'conditional_render',
                //     'group' => 'advanced',
                //     'label' => 'Conditional render',
                //     'selection_type' => 'Conditional logic',
                //     'conditional_logic' => array(
                //         'yes' => 'Yes',
                //         'no' => 'No'
                //     ),
                //     'conditional_type' => array(
                //         'any' => 'Any',
                //         'all' => 'All'
                //     ),
                // ),
                'columns' => array (
                    ['width' => '50%', 'left' => '', 'fields' => []],
                    ['width' => '50%', 'left' => '', 'fields' => []],
                )
            ),
            'field_options' => array(
                'columns' => array (
                    array('width' => '50', 'left' => '', 'fields' => []),
                    array('width' => '50', 'left' => '', 'fields' => []),
                ),
                // 'conditional_logic_option' => array(
                //     'conditional_logic' => 'no',
                //     'conditional_type'  => 'any',
                //     'options' => array(
                //         array(
                //             'target_field' => 'wdwedfwed',
                //             'condition' => '',
                //             'value' => ''
                //         )
                //     ),
                // ),
            )
        );
    }

    public function render($element, $form, $elements)
    {
        $columns = Arr::get($element, 'field_options.columns', []);
        ?>
            <div class="wpf-container">
                <div class="wpf-row" style="display: flex; gap: 20px;">
                    <?php
                    foreach ($columns as $column) {
                        ?>
                        <div class="wpf-col" style="width: <?php echo esc_attr($column['width']) . "%"; ?>">
                            <?php
                            foreach ($column['fields'] as $field) {
                                do_action('wppayform/render_component_' . $field['type'], $field, $form, $elements);
                            }
                            ?>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
        <?php
    }
}
