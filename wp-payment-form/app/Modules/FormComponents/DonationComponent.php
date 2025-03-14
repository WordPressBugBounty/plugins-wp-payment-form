<?php

namespace WPPayForm\App\Modules\FormComponents;

use WPPayForm\App\Models\Form;
use WPPayForm\App\Services\GeneralSettings;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Submission;

if (!defined('ABSPATH')) {
    exit;
}

class DonationComponent extends BaseComponent
{
    public function __construct()
    {
        parent::__construct('donation_item', 2);
        add_filter('wppayform/validate_component_on_save_payment_item', array($this, 'validateOnSave'), 1, 3);
    }

    public function component()
    {
        return array(
            'type' => 'donation_item',
            'editor_title' => 'Donation Progress Item',
            'group' => 'payment',
            'postion_group' => 'payment',
            'conditional_hide' => true,
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Field Label',
                    'type' => 'text',
                    'group' => 'general'
                ),
                'required' => array(
                    'label' => 'Required',
                    'type' => 'switch',
                    'group' => 'general'
                ),
                'enable_image' => array(
                    'label' => 'Enable Image',
                    'type' => 'switch',
                    'group' => 'general'
                ),
                'payment_options' => array(
                    'type' => 'donation_options',
                    'group' => 'general',
                    'label' => 'Configure Donation Progress Item',
                    'selection_type' => 'Payment Type'
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
            'is_system_field' => true,
            'is_payment_field' => true,
            'field_options' => array(
                'disable' => false,
                'label' => 'Donation Progress Item',
                'required' => 'yes',
                'enable_image' => 'yes',
                'intial_raising_amount' => '0',
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
                'pricing_details' => array(
                    'show_statistic' => 'yes',
                    'donation_goals' => '1000',
                    'progress_bar' => 'yes',
                    'one_time_type' => 'choose_single',
                    'image_url' => array(
                        array(
                            'label' => '',
                            'value' => ''
                        )
                    ),
                    'multiple_pricing' => array(
                        array(
                            'label' => '',
                            'value' => '10'
                        ),
                        array(
                            'label' => '',
                            'value' => '20'
                        )
                    ),
                    'allow_custom_amount' => 'no',
                    'allow_recurring' => 'no',
                    'bill_time_max' => '0',
                    'interval_label' => 'I would like to recurring donation',
                    'interval_text' => 'Bill my every',
                    'intervals' => [__('day', 'wp-payment-form'), __('week', 'wp-payment-form'), __('fortnight', 'wp-payment-form'), __('month', 'wp-payment-form'), __('quarter', 'wp-payment-form'), __('half_year', 'wp-payment-form'), __('year', 'wp-payment-form')],
                    'interval_options' => [__('day', 'wp-payment-form'), __('week', 'wp-payment-form'), __('fortnight', 'wp-payment-form'), __('month', 'wp-payment-form'), __('quarter', 'wp-payment-form'), __('half_year', 'wp-payment-form'), __('year', 'wp-payment-form')],
                    'interval_display_type' => 'dropdown'
                )
            )
        );
    }

    public function validateOnSave($error, $element, $formId)
    {
        $pricingDetails = Arr::get($element, 'field_options.pricing_details', array());
        $paymentType = Arr::get($pricingDetails, 'one_time_type');
        if ($paymentType == 'single') {
            if (!Arr::get($pricingDetails, 'payment_amount')) {
                $error = __('Payment amount is required for item:', 'wp-payment-form') . ' ' . Arr::get($element, 'field_options.label');
            }
        }
        return $error;
    }

    public function render($element, $form, $elements)
    {
        $disable = Arr::get($element, 'field_options.disable', false);
        $hiddenAttr = Arr::get($element, 'field_options.conditional_logic_option.conditional_logic')  === 'yes' ? 'none' : '';
        $pricingDetails = Arr::get($element, 'field_options.pricing_details', array());
        if (!$pricingDetails || $disable) {
            return;
        }

        $element['field_options']['default_value'] = apply_filters('wppayform/input_default_value', Arr::get($element['field_options'], 'default_value'), $element, $form);

        $displayType = Arr::get($pricingDetails, 'prices_display_type', 'radio');
        $this->renderSingleChoice(
            $displayType,
            $element,
            $form,
            Arr::get($pricingDetails, 'multiple_pricing', array())
        );

    }

    public function renderSingleAmount($element, $form, $amount = false)
    {
        $enableImage = Arr::get($element, 'field_options.enable_image') == 'yes';
        $showTitle = Arr::get($element, 'field_options.pricing_details.show_onetime_labels') == 'yes';
        $imageUrl = Arr::get($element, 'field_options.pricing_details.image_url');
        if ($enableImage) {
            foreach ($imageUrl as $item) {
                ?>
                <div class='wpf_donation_image_container' >
                    <div class="wpf_tabular_product_photo">
                        <?php echo $this->renderImage($item['photo']); ?>
                    </div>
                </div>
                <?php
            };
        };
        if ($showTitle) {
            $title = Arr::get($element, 'field_options.label');
            $currencySettings = Form::getCurrencyAndLocale($form->ID);
            $controlAttributes = array(
                'data-element_type' => $this->elementName,
                'class' => $this->elementControlClass($element)
            ); ?>
            <div <?php echo $this->builtAttributes($controlAttributes); ?>>
                <div class="wpf_input_label wpf_single_amount_label">
                    <?php echo esc_html($title) ?>: <span
                        class="wpf_single_amount"><?php echo esc_html(wpPayFormFormattedMoney(wpPayFormConverToCents($amount), $currencySettings)); ?></span>
                </div>
            </div>
            <?php
        }
        echo '<input customname =' . esc_attr($element['editor_title']) . ' name=' . esc_attr($element['id']) . ' type="hidden" class="wpf_payment_item" data-price="' . esc_attr(wpPayFormConverToCents($amount)) . '" value="' . esc_attr($amount) . '" />';
    }


    private function renderImage($image, $lightboxed = false)
    {
        if (!$image) {
            return '';
        }

        $imageFull = Arr::get($image, 'image_full');
        $altText = Arr::get($image, 'alt_text');

        if ($lightboxed) {
            return '<a class="wpf_lightbox" href="' . esc_url($imageFull) . '"><img class="wpf_donation_image_container" src="' . esc_url($imageFull) . '" alt="' . esc_attr($altText) . '" /></a>';
        }
        return '<img class="wpf_donation_image_container" src="' . esc_url($imageFull) . '" alt="' . esc_attr($altText) . '" style="width:100%;"';
    }

    public function renderSingleChoice($type, $element, $form, $prices = array())
    {
        if (!$type || !$prices) {
            return;
        }
        
        $isPro = defined('WPPAYFORMHASPRO');

        $isSimpleDonationForm = strpos($form->post_name, "donation-template-vertical") !== false ? 'wpf_simple_donation_form' : '';
        $enableImage = Arr::get($element, 'field_options.enable_image') == 'yes';
        $hiddenAttr = Arr::get($element, 'field_options.conditional_logic_option.conditional_logic')  === 'yes' ? 'none' : '';
        $pricingDetails = Arr::get($element, 'field_options.pricing_details', array());
        $initialRaisingAmount = intval(Arr::get($element, 'field_options.intial_raising_amount', 0));

        $showProgress = Arr::get($pricingDetails, 'progress_bar') == 'yes';
        $showStatistic = Arr::get($pricingDetails, 'show_statistic') == 'yes';
        $allowCustomAmount = Arr::get($pricingDetails, 'allow_custom_amount') == 'yes';
        $goal = Arr::get($pricingDetails, 'donation_goals');
        $isRecurring = Arr::get($pricingDetails, 'allow_recurring') == 'yes';


        $submission = new Submission();
        if ($showStatistic && isset($goal) && intval($goal) > 0) {
            $raised = $submission->donationTotal($form->ID, 'paid') / 100;
            $donations = $submission->getTotalCount($form->ID, 'paid');
            $raised = $raised + $initialRaisingAmount;
            $percentage = round(($raised / intval($goal)) * 100);
            $percentage = $percentage > 100 ? 100 : $percentage;
            $style = 'width: ' . $percentage . '%;';
        }
        $imageUrl = Arr::get($element, 'field_options.pricing_details.image_url');
        ?>
        <?php

        $fieldOptions = Arr::get($element, 'field_options', false);
        $intervalLabel = Arr::get($fieldOptions, 'pricing_details.interval_label', 'I would like to recurring donation');
        $intervalText = Arr::get($fieldOptions, 'pricing_details.interval_text', 'Bill my every');
        $currencySettings = Form::getCurrencyAndLocale($form->ID);
        $currencySign = Arr::get($currencySettings, 'currency_sign');

        $total_prices = count($prices) - 1;
        $defaultValue = Arr::get($fieldOptions, 'default_value', $total_prices);
        $defaultValue = $defaultValue === null ? $total_prices : $defaultValue;

        $inputId = 'wpf_input_' . $form->ID . '_' . $element['id'];
        $controlAttributes = array(
            'data-element_type' => $this->elementName,
            'data-required_element' => $type,
            'data-required' => Arr::get($fieldOptions, 'required'),
            'data-target_element' => $element['id'],
            'class' => $this->elementControlClass($element)
        );

        $attributes = array(
            'data-required' => Arr::get($fieldOptions, 'required'),
            'data-type' => 'input',
            'name' => $element['id'] . '_custom',
            'placeholder' => '',
            'value' => Arr::get($prices, $defaultValue . '.value'),
            'base-price' => Arr::get($prices, $defaultValue . '.value'),
            'type' => 'number',
            'step' => 'any',
            'data-is_custom_price' => 'yes',
            'min' => 0,
            'data-price' => 0,
            'id' => $inputId,
            'class' => 'wpf_custom_amount wpf_money_amount input-prepend wpf_donation_item',
            'customname' => $element['editor_title']
        );

        if ($allowCustomAmount && $isPro) {
            $prices['custom'] = array(
                'value' => 0,
                'label' => __('Custom Amount', 'wp-payment-form')
            );
        } else {
            $attributes['disabled'] = true;
        }
        ?>

        <div style = "display : <?php echo esc_attr($hiddenAttr); ?>" <?php echo $this->builtAttributes($controlAttributes); ?> >

        <?php
            if ($enableImage) {
                foreach ($imageUrl as $item) {
                    if(!empty($item['photo']['image_thumb'])) {
                    ?>
                    <div class='wpf_donation_image_container'>
                        <div class="wpf_donation_photo">
                            <?php echo $this->renderImage($item['photo']); ?>
                        </div>
                    </div>
                    </div>
                    <?php
                    }
                };
            };
        $name_raised = "wpf_form_id_". $form->ID . "_raised_donation";
        $goal_amount = "wpf_form_id_". $form->ID . "_goal_amount";
        $bar_name = "wpf_form_id_". $form->ID . "_bar";

        ?>
        <div class="donation-wrapper <?php echo esc_attr($isSimpleDonationForm) ?>">
        <?php
        if ($showStatistic && isset($goal) && intval($goal) >= 0) : ?>
            <div class="wpf_donation_status">
                <div class="raised_amount">
                    <div class="number" name="<?php echo esc_attr($name_raised) ?>" data-raised="<?php echo esc_attr($raised * 100) ?>">
                        <?php echo  esc_html(wpPayFormFormattedMoney($raised * 100, $currencySettings)) ; ?>
                    </div>
                    <div class="text"><?php echo esc_html__('Raised', 'wp-payment-form'); ?></div>
                </div>
                <div class="count_amount">
                    <div class="number">
                        <?php echo esc_html($donations); ?>
                    </div>

                    <div class="text"><?php echo esc_html__('Donations', 'wp-payment-form'); ?></div>
                </div>
                <div class="goal_amount">
                    <div class="number" name="<?php echo esc_attr($goal_amount) ?>" data-goal="<?php echo esc_attr($goal * 100) ?>">
                        <?php echo esc_html(wpPayFormFormattedMoney($goal * 100, $currencySettings)) ; ?>
                    </div>
                    <div class="text"><?php echo esc_html__('Goal', 'wp-payment-form'); ?></div>
                </div>
            </div>
        <?php
            if ($showProgress) :
        ?>
            <div class="wpf_donation_bar_wrapper">
                <div class="wpf_donation_fields_bar">
                    <div
                        class="wpf_bar"
                        style="<?php echo esc_attr($style); ?>"
                        name="<?php echo esc_attr($bar_name) ?>"
                        data-percentage="<?php echo esc_attr($percentage) ?>"
                    >
                        <?php echo esc_html($percentage) . '%'; ?>
                    </div>
                </div>
            </div>
        <?php
            endif;
        endif; ?>

        <div class="wpf_donation_fields_wrapper" >
            <div class="wpf_input_content wpf_donation_controls_custom">
                <?php $this->buildLabel($fieldOptions, $form, array('for' => $inputId)); ?>
                <div class="wpf_form_item_group">
                    <div class="wpf_input-group-prepend">
                        <div class="wpf_input-group-text wpf_input-group-text-prepend"><?php echo esc_html($currencySign); ?></div>
                    </div>
                    <input <?php echo $this->builtAttributes($attributes); ?> />
                    <?php if (Arr::get($attributes, 'disabled') == true) { ?>
                        <?php unset($attributes['disabled']) ?>
                        <input <?php echo $this->builtAttributes($attributes); ?> style="display: none" />
                    <?php } ?>
                </div>
            </div>
            <br/>
            <div class="wpf_input_content wpf_donation_controls_radio">
                <?php
                foreach ($prices as $index => $price) :
                    $optionId = $element['id'] . '_' . $index . '_' . $form->ID;
                    $attributesRadio = array(
                        'class' => 'form-check-input wpf_payment_item' . ' wpf_donation_item_' . $index,
                        'type' => 'radio',
                        'data-price' => wpPayFormConverToCents($price['value']),
                        'base-price' => wpPayFormConverToCents($price['value']),
                        'name' => $element['id'],
                        'id' => $optionId,
                        'value' => $index,
                        'customname' => $element['editor_title']
                    );
                    
                    if ($index === $defaultValue) {
                        $attributesRadio['checked'] = 'true';
                    }

                    // if($index == 'custom') {
                    //     dd($attributesRadio);
                    // }

                    // echo $index; echo $defaultValue; echo Arr::get($attributesRadio, 'checked');
                ?>
                    <div class="form-check">
                        <input <?php echo $this->builtAttributes($attributesRadio); ?>>
                        <label class="form-check-label" for="<?php echo esc_attr($optionId); ?>">
                            <span class="wpf_price_option_name"
                                    itemprop="description">
                                    <?php
                                    echo !empty($price['label']) ? esc_html($price['label']) :  esc_html(wpPayFormFormattedMoney(wpPayFormConverToCents($price['value']), $currencySettings));
                                    ?>
                                </span>
                            <meta itemprop="price" content="<?php echo esc_attr($price['value']); ?>">
                        </label>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php if ($isRecurring && $isPro) :?>
            <div class="wpf_form_group wpf_donation_recurring_controller">
                <div class="form-check wpf_t_c_checks">
                    <input type="checkbox" name="donation_is_recurring"
                        class="wpf_donation_recurring"
                        customname= <?php echo esc_attr($element['editor_title']) ?>
                        id="<?php echo esc_attr($inputId) . '_recurring' ?>">
                    <label class="form-check-label" for="<?php echo esc_attr($inputId) . '_recurring' ?>"
                        style="font-style: italic; cursor:pointer;">
	                    <?php echo esc_html__($intervalLabel, 'wp-payment-form'); ?>
                    </label>
                </div>
            </div>
            <div class="wpf_input_content wpf_donation_recurring_infos" style="display:none; align-items: center" data-display-type="<?php echo esc_attr(Arr::get($pricingDetails, 'interval_display_type')); ?>">
                <label class="form-check-label">
                    <?php echo esc_html__($intervalText, 'wp-payment-form'); ?>
                </label>

                <?php if(Arr::get($pricingDetails, 'interval_display_type') === 'dropdown'): ?>
                <select type="select" name="donation_recurring_interval"
                style="outline: none;cursor:pointer;"  customname= <?php echo esc_attr($element['editor_title']) ?>>
                    <?php
                    foreach ($pricingDetails['intervals'] as $index => $plan): ?>
                        <option><?php echo esc_attr($plan); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                    <?php foreach ($pricingDetails['intervals'] as $plan): ?>
                    <div class="wpf_donation_interval_button" data-donation_interval_button="">
                        <input type="radio" class="donation_recurring_interval" name="donation_recurring_interval" value="<?php echo esc_attr($plan); ?>" customname="<?php echo esc_attr($element['editor_title']); ?>">
                        <label for="<?php echo esc_attr($plan); ?>"><?php echo esc_attr($plan); ?></label>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        </div>
        <?php
    }
}
