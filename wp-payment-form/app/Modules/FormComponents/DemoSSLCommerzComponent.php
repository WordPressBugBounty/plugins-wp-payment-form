<?php

namespace WPPayForm\App\Modules\FormComponents;

if (!defined('ABSPATH')) {
    exit;
}

class DemoSSLCommerzComponent extends BaseComponent
{
    protected $componentName = 'sslcommerz_gateway_element';

    public function __construct()
    {
        parent::__construct($this->componentName, 600);
    }

    public function component()
    {
        return array(
            'type' => 'sslcommerz_gateway_element',
            'editor_title' => 'SSLcommerz Payment',
            'editor_icon' => '',
            'is_pro' => 'yes',
            'disable' => 'yes',
            'group' => 'payment_method_element',
            'postion_group' => 'payment_method',
            'editor_elements' => array(),
            'field_options' => array(
                'disable' => false,
            )
        );
    }

    public function render($element, $form, $elements)
    {
        return;
    }
}