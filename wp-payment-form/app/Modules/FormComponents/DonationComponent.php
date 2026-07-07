<?php

namespace WPPayForm\App\Modules\FormComponents;

use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Meta;
use WPPayForm\App\Services\GeneralSettings;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Submission;

if (!defined('ABSPATH')) {
    exit;
}

class DonationComponent extends BaseComponent
{
    /** @var array<int, string[]> Gift Aid HTML fragments deferred per form ID. */
    private static $giftAidQueue = [];

    /**
     * Payment summary HTML captured via output buffering when Gift Aid is active.
     * Keyed by form ID. Flushed at priority 7 (after Gift Aid at 5, before payment method at 10).
     *
     * @var array<int, string[]>
     */
    private static $paymentSummaryQueue = [];

    /**
     * Payment method HTML captured via output buffering when Gift Aid is active.
     * Keyed by form ID. Initialized to [] for a form ID once capture hooks are registered
     * so the array_key_exists guard prevents duplicate hook registration.
     *
     * @var array<int, string[]>
     */
    private static $paymentMethodQueue = [];

    public function __construct()
    {
        parent::__construct('donation_item', 2);
        add_filter('wppayform/validate_component_on_save_payment_item', array($this, 'validateOnSave'), 1, 3);
        if (defined('WPPAYFORMHASPRO') && WPPAYFORMHASPRO) {
            add_action('wppayform/wpf_before_submission_data_insert', array($this, 'validateGiftAid'), 10, 4);
            add_action('wppayform/after_submission_data_insert', array($this, 'saveGiftAidMeta'), 10, 4);
            add_action('wppayform/form_render_before_submit_button', array($this, 'renderDeferredGiftAid'), 5, 1);
        }
        add_action('wppayform/form_render_before_submit_button', array($this, 'renderDeferredPaymentSummary'), 7, 1);
        add_action('wppayform/form_render_before_submit_button', array($this, 'renderDeferredPaymentMethod'), 10, 1);
    }

    public function component()
    {
        return array(
            'type' => 'donation_item',
            'editor_title' => 'Donation Progress Item',
            'group' => 'payment',
            'postion_group' => 'payment',
            'conditional_hide' => false,
            'isNumberic' => 'yes',
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
                'enable_gift_aid'      => 'no',
                'gift_aid_title'       => __('Boost your donation with Gift Aid', 'wp-payment-form'),
                'gift_aid_declaration' => __('I am a UK taxpayer and want this donation to qualify for Gift Aid. I understand that the charity will reclaim 25p for every £1 I donate and that I must have paid at least that amount in UK Income Tax and/or Capital Gains Tax during the tax year.', 'wp-payment-form'),
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
                    'interval_text' => 'Bill me every',
                    'intervals' => ['day', 'week', 'fortnight', 'month', 'quarter', 'half_year', 'year'],
                    'interval_options' => [__('day', 'wp-payment-form'), __('week', 'wp-payment-form'), __('fortnight', 'wp-payment-form'), __('month', 'wp-payment-form'), __('quarter', 'wp-payment-form'), __('half_year', 'wp-payment-form'), __('year', 'wp-payment-form')],
                    'interval_custom_labels' => [
                        'day'       => '',
                        'week'      => '',
                        'fortnight' => '',
                        'month'     => '',
                        'quarter'   => '',
                        'half_year' => '',
                        'year'      => '',
                    ],
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
                        <?php //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
            <?php //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
        $initialRaisingAmount = absint(Arr::get($element, 'field_options.intial_raising_amount', 0));

        $showProgress = Arr::get($pricingDetails, 'progress_bar') == 'yes';
        $showStatistic = Arr::get($pricingDetails, 'show_statistic') == 'yes';
        $allowCustomAmount = Arr::get($pricingDetails, 'allow_custom_amount') == 'yes';
        $goal = Arr::get($pricingDetails, 'donation_goals');
        $isRecurring = Arr::get($pricingDetails, 'allow_recurring') == 'yes';


        $raised     = 0;
        $donations  = 0;
        $percentage = 0;
        $style      = 'width: 0%;';

        $submission = new Submission();
        if ($showStatistic && isset($goal) && absint($goal) > 0) {
            $raised     = $submission->donationTotal($form->ID, 'paid') / 100;
            $donations  = $submission->getTotalCount($form->ID, 'paid');
            $raised     = $raised + $initialRaisingAmount;
            $percentage = round(($raised / absint($goal)) * 100);
            $percentage = $percentage > 100 ? 100 : $percentage;
            $style      = 'width: ' . $percentage . '%;';
        }
        $imageUrl = Arr::get($element, 'field_options.pricing_details.image_url');
        ?>
        <?php

        $fieldOptions = Arr::get($element, 'field_options', false);
        $intervalLabel = Arr::get($fieldOptions, 'pricing_details.interval_label', 'I would like to recurring donation');
        $intervalText = Arr::get($fieldOptions, 'pricing_details.interval_text', 'Bill me every');
        $currencySettings = Form::getCurrencyAndLocale($form->ID);
        $currencySign = Arr::get($currencySettings, 'currency_sign');

        $total_prices = count($prices) - 1;
        $defaultValue = Arr::get($fieldOptions, 'default_value', $total_prices);
        $defaultValue = $defaultValue === null ? $total_prices : $defaultValue;
        $wppayform_interval_labels = [
            'day'       => __('Day', 'wp-payment-form'),
            'week'      => __('Week', 'wp-payment-form'),
            'fortnight' => __('Fortnight', 'wp-payment-form'),
            'month'     => __('Month', 'wp-payment-form'),
            'quarter'   => __('Quarter', 'wp-payment-form'),
            'half_year' => __('Half Year', 'wp-payment-form'),
            'year'      => __('Year', 'wp-payment-form'),
        ];

        $intervalCustomLabels = Arr::get($pricingDetails, 'interval_custom_labels', []);
        if (is_array($intervalCustomLabels)) {
            foreach ($intervalCustomLabels as $intervalKey => $customLabel) {
                $customLabel = is_string($customLabel) ? trim($customLabel) : '';
                if ($customLabel !== '' && isset($wppayform_interval_labels[$intervalKey])) {
                    $wppayform_interval_labels[$intervalKey] = $customLabel;
                }
            }
        }

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
            'base-currency-price' => Arr::get($prices, $defaultValue . '.value'),
            'type' => 'number',
            'step' => 'any',
            'data-is_custom_price' => 'yes',
            'min' => 0,
            'data-price' => 0,
            'id' => $inputId,
            'class' => 'wpf_donation_custom_amount wpf_money_amount input-prepend wpf_donation_item',
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

        <?php //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <div style = "display : <?php echo esc_attr($hiddenAttr); ?>" <?php echo $this->builtAttributes($controlAttributes); ?> >

        <?php
            if ($enableImage) {
                foreach ($imageUrl as $item) {
                    if(!empty($item['photo']['image_thumb'])) {
                    ?>
                    <div class='wpf_donation_image_container'>
                        <div class="wpf_donation_photo">
                            <?php //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
                    <?php //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <input <?php echo $this->builtAttributes($attributes); ?> />
                    <?php if (Arr::get($attributes, 'disabled') == true) { ?>
                        <?php unset($attributes['disabled']) ?>
                        <?php //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <input <?php echo $this->builtAttributes($attributes); ?> style="display: none" />
                    <?php } ?>
                </div>
            </div>
            <br/>
            <div class="wpf_input_content wpf_donation_controls_radio">
                <?php
                foreach ($prices as $index => $price) :
                    if (empty($price) || !isset($price['value'])) { continue; }
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


                ?>
                    <div class="form-check">
                        <?php //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
	                    <?php echo esc_html($intervalLabel); ?>
                    </label>
                </div>
            </div>
            <div class="wpf_input_content wpf_donation_recurring_infos" style="display:none; align-items: center" data-display-type="<?php echo esc_attr(Arr::get($pricingDetails, 'interval_display_type')); ?>">
                <label class="form-check-label">
                    <?php echo esc_html($intervalText); ?>
                </label>

                <?php if(Arr::get($pricingDetails, 'interval_display_type') === 'dropdown'): ?>
                <select type="select" name="donation_recurring_interval"
                style="outline: none;cursor:pointer;"  customname= <?php echo esc_attr($element['editor_title']) ?>>
                    <?php
                    foreach ($pricingDetails['intervals'] as $index => $plan): ?>
                        <option value="<?php echo esc_attr($plan); ?>"><?php echo esc_html(isset($wppayform_interval_labels[$plan]) ? $wppayform_interval_labels[$plan] : $plan); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                    <?php foreach ($pricingDetails['intervals'] as $plan): ?>
                    <div class="wpf_donation_interval_button" data-donation_interval_button="">
                        <input type="radio" class="donation_recurring_interval" name="donation_recurring_interval" value="<?php echo esc_attr($plan); ?>" customname="<?php echo esc_attr($element['editor_title']); ?>">
                        <label for="<?php echo esc_attr($plan); ?>"><?php echo esc_html(isset($wppayform_interval_labels[$plan]) ? $wppayform_interval_labels[$plan] : $plan); ?></label>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        <?php
        $enableGiftAid = Arr::get($fieldOptions, 'enable_gift_aid') === 'yes'
            && defined('WPPAYFORMHASPRO') && WPPAYFORMHASPRO;
        if ($enableGiftAid) :
            $declarationText   = mb_substr((string) Arr::get($fieldOptions, 'gift_aid_declaration', ''), 0, 2000);
            $giftAidTitle      = mb_substr((string) Arr::get($fieldOptions, 'gift_aid_title', __('Boost your donation with Gift Aid', 'wp-payment-form')), 0, 255);
            $giftAidCheckboxId = $inputId . '_gift_aid';
            $giftAidElementId  = $element['id'];
            ob_start();
        ?>
        <div class="wpf_form_group wpf_item_donation_item wpf_gift_aid_wrapper">
            <div class="wpf_gift_aid_card">
                <div class="wpf_gift_aid_card__header">
                    <input type="checkbox"
                           name="<?php echo esc_attr($giftAidElementId); ?>_gift_aid"
                           id="<?php echo esc_attr($giftAidCheckboxId); ?>"
                           class="form-check-input wpf_gift_aid_toggle"
                           value="1" />
                    <div class="wpf_gift_aid_card__body">
                        <label class="wpf_gift_aid_card__title" for="<?php echo esc_attr($giftAidCheckboxId); ?>"><?php echo esc_html($giftAidTitle); ?></label>
                        <p class="wpf_gift_aid_card__declaration"><?php echo esc_html($declarationText); ?></p>
                    </div>
                </div>
                <div class="wpf_gift_aid_address" style="display:none;">
                    <p class="wpf_gift_aid_address__label"><?php echo esc_html__('Your address for Gift Aid', 'wp-payment-form'); ?></p>
                    <input type="text"
                           name="<?php echo esc_attr($giftAidElementId); ?>_gift_aid_address"
                           class="wpf_input"
                           placeholder="<?php echo esc_attr__('House name or number', 'wp-payment-form'); ?>" />
                    <input type="text"
                           name="<?php echo esc_attr($giftAidElementId); ?>_gift_aid_address2"
                           class="wpf_input"
                           placeholder="<?php echo esc_attr__('Address line 2 (optional)', 'wp-payment-form'); ?>" />
                    <div class="wpf_gift_aid_address__row">
                        <input type="text"
                               name="<?php echo esc_attr($giftAidElementId); ?>_gift_aid_city"
                               class="wpf_input"
                               placeholder="<?php echo esc_attr__('Town or city', 'wp-payment-form'); ?>" />
                        <input type="text"
                               name="<?php echo esc_attr($giftAidElementId); ?>_gift_aid_postcode"
                               class="wpf_input wpf_gift_aid_postcode"
                               placeholder="<?php echo esc_attr__('Postcode', 'wp-payment-form'); ?>" />
                    </div>
                </div>
            </div>
        </div>
        <?php
            $wpfFormId = absint($form->ID);
            self::$giftAidQueue[$wpfFormId][] = ob_get_clean();

            // Defer payment_summary and choose_payment_method to render after Gift Aid.
            // Pattern: ob_start() at priority 1 (before real component at priority 10),
            // ob_get_clean() at PHP_INT_MAX (after). Guard prevents duplicate registration.
            if (!array_key_exists($wpfFormId, self::$paymentSummaryQueue)) {
                self::$paymentSummaryQueue[$wpfFormId] = [];
                add_action(
                    'wppayform/render_component_payment_summary',
                    static function ($_, $fm) use ($wpfFormId) {
                        if (absint($fm->ID) === $wpfFormId) {
                            ob_start();
                        }
                    },
                    1, 3
                );
                add_action(
                    'wppayform/render_component_payment_summary',
                    static function ($_, $fm) use ($wpfFormId) {
                        if (absint($fm->ID) === $wpfFormId && ob_get_level() > 0) {
                            self::$paymentSummaryQueue[$wpfFormId][] = ob_get_clean();
                        }
                    },
                    PHP_INT_MAX, 3
                );
            }

            if (!array_key_exists($wpfFormId, self::$paymentMethodQueue)) {
                self::$paymentMethodQueue[$wpfFormId] = [];
                add_action(
                    'wppayform/render_component_choose_payment_method',
                    static function ($_, $fm) use ($wpfFormId) {
                        if (absint($fm->ID) === $wpfFormId) {
                            ob_start();
                        }
                    },
                    1, 3
                );
                add_action(
                    'wppayform/render_component_choose_payment_method',
                    static function ($_, $fm) use ($wpfFormId) {
                        if (absint($fm->ID) === $wpfFormId && ob_get_level() > 0) {
                            self::$paymentMethodQueue[$wpfFormId][] = ob_get_clean();
                        }
                    },
                    PHP_INT_MAX, 3
                );
            }
        endif; ?>

        </div>

        </div>
        <?php
    }

    /**
     * Flush queued Gift Aid HTML for this form before the submit button.
     *
     * Hooked on 'wppayform/form_render_before_submit_button' (priority 5).
     * Outputs and clears fragments deferred from renderSingleChoice() so the
     * Gift Aid section always appears below every other field regardless of
     * where donation_item sits in the builder order.
     *
     * @param object $form WP_Post object for the current form.
     */
    public function renderDeferredGiftAid(object $form)
    {
        $formId = absint($form->ID);

        if (empty(self::$giftAidQueue[$formId])) {
            return;
        }

        foreach (self::$giftAidQueue[$formId] as $html) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in renderSingleChoice
            echo $html;
        }

        unset(self::$giftAidQueue[$formId]);
    }

    /**
     * Flush deferred payment summary HTML after Gift Aid (priority 7).
     *
     * @param object $form WP_Post object for the current form.
     */
    public function renderDeferredPaymentSummary(object $form): void
    {
        $formId = absint($form->ID);

        if (empty(self::$paymentSummaryQueue[$formId])) {
            return;
        }

        foreach (self::$paymentSummaryQueue[$formId] as $html) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- captured from trusted component render
            echo $html;
        }

        unset(self::$paymentSummaryQueue[$formId]);
    }

    /**
     * Flush deferred payment method HTML after Gift Aid (priority 10 > Gift Aid priority 5).
     *
     * Hooked on 'wppayform/form_render_before_submit_button'.
     * Only fires for forms where Gift Aid was active — other forms have no queue entry.
     *
     * @param object $form WP_Post object for the current form.
     */
    public function renderDeferredPaymentMethod(object $form): void
    {
        $formId = absint($form->ID);

        if (empty(self::$paymentMethodQueue[$formId])) {
            return;
        }

        foreach (self::$paymentMethodQueue[$formId] as $html) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- captured from trusted component render
            echo $html;
        }

        unset(self::$paymentMethodQueue[$formId]);
    }

    /**
     * Hooked on 'wppayform/wpf_before_submission_data_insert' (SubmissionHandler.php:296).
     * Calling wp_send_json_error() + exit here is the correct contract — SubmissionHandler
     * itself uses wp_send_json_error() + exit for all field-level validation failures
     * (e.g. lines 45, 62, 238 of SubmissionHandler.php). No return value is used.
     */
    public function validateGiftAid($submission, $form_data, $paymentItems, $subscriptionItems)
    {
        if (!(defined('WPPAYFORMHASPRO') && WPPAYFORMHASPRO)) {
            return;
        }
        $formId = absint($submission['form_id'] ?? 0);
        if (!$formId) {
            return;
        }

        $formattedElements = Form::getFormattedElements($formId);

        foreach ($formattedElements['payment'] as $elementId => $element) {
            if (Arr::get($element, 'type') !== 'donation_item') {
                continue;
            }
            if (Arr::get($element, 'options.enable_gift_aid') !== 'yes') {
                continue;
            }
            if (Arr::get($form_data, $elementId . '_gift_aid') !== '1') {
                continue;
            }
            $address  = sanitize_text_field(Arr::get($form_data, $elementId . '_gift_aid_address', ''));
            $postcode = sanitize_text_field(Arr::get($form_data, $elementId . '_gift_aid_postcode', ''));
            $errors   = [];

            if (empty($address)) {
                $errors[$elementId . '_gift_aid_address'] = __('House name or number is required for Gift Aid.', 'wp-payment-form');
            }
            if (empty($postcode)) {
                $errors[$elementId . '_gift_aid_postcode'] = __('Postcode is required for Gift Aid.', 'wp-payment-form');
            }
            if (!empty($errors)) {
                wp_send_json_error(array(
                    'message' => __('Form Validation failed', 'wp-payment-form'),
                    'errors'  => $errors,
                ), 423);
                exit;
            }
        }
    }

    public function saveGiftAidMeta($submissionId, $formId, $form_data, $formattedElements)
    {
        if (!(defined('WPPAYFORMHASPRO') && WPPAYFORMHASPRO)) {
            return;
        }
        $submissionId = absint($submissionId);
        $formId       = absint($formId);

        if (empty($formattedElements['payment']) || !is_array($formattedElements['payment'])) {
            return;
        }

        $metaModel = new Meta();

        foreach ($formattedElements['payment'] as $elementId => $element) {
            if (Arr::get($element, 'type') !== 'donation_item') {
                continue;
            }
            if (Arr::get($element, 'options.enable_gift_aid') !== 'yes') {
                continue;
            }
            if (Arr::get($form_data, $elementId . '_gift_aid') !== '1') {
                continue;
            }

            $metaModel->updateOrderMeta('wpf_submissions', $submissionId, 'gift_aid_opted_in', '1', $formId);

            $address = sanitize_text_field(Arr::get($form_data, $elementId . '_gift_aid_address', ''));
            if ($address !== '') {
                $metaModel->updateOrderMeta('wpf_submissions', $submissionId, 'gift_aid_address', $address, $formId);
            }

            $address2 = sanitize_text_field(Arr::get($form_data, $elementId . '_gift_aid_address2', ''));
            if ($address2 !== '') {
                $metaModel->updateOrderMeta('wpf_submissions', $submissionId, 'gift_aid_address_line2', $address2, $formId);
            }

            $city = sanitize_text_field(Arr::get($form_data, $elementId . '_gift_aid_city', ''));
            if ($city !== '') {
                $metaModel->updateOrderMeta('wpf_submissions', $submissionId, 'gift_aid_city', $city, $formId);
            }

            $postcode = strtoupper(sanitize_text_field(Arr::get($form_data, $elementId . '_gift_aid_postcode', '')));
            if ($postcode !== '') {
                $metaModel->updateOrderMeta('wpf_submissions', $submissionId, 'gift_aid_postcode', $postcode, $formId);
            }

            break; // Only one donation_item with gift_aid per form.
        }
    }
}
