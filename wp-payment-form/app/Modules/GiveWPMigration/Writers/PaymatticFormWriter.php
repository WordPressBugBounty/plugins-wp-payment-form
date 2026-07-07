<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Writers;

if (!defined('ABSPATH')) {
    exit;
}

use WPPayForm\App\Modules\GiveWPMigration\Enrichers\FFMEnricher;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\EmailNotificationMapper;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\GatewayMapper;
use WPPayForm\App\Modules\GiveWPMigration\Mappers\IntervalMapper;
use WPPayForm\App\Modules\GiveWPMigration\MigrationState;
use WPPayForm\App\Modules\GiveWPMigration\Readers\GiveEmailReader;

/**
 * PaymatticFormWriter — creates a Paymattic wp_payform post for each migrated GiveWP form.
 *
 * Every migrated form is created as a draft ('post_status' = 'draft') so the
 * admin can review and publish it after verifying the field configuration.
 *
 * Form builder settings are stored in two postmeta keys:
 *   - wppayform_paymentform_builder_settings : array of field element objects (JSON-encoded)
 *   - wppayform_paymentform_settings         : form-level settings such as goal (JSON-encoded)
 *
 * Additional rollback metadata:
 *   - give_source_form_id : the original GiveWP give_forms post ID — allows identifying
 *     and deleting migrated forms during a rollback.
 *
 * The donation_item element structure matches what Paymattic's own form builder
 * writes. The field 'id' must begin with 'donation_item' so that
 * Submission::getDonationGoalAndTotal() correctly identifies it when computing totals.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Writers
 * @since   4.6.21
 */
class PaymatticFormWriter
{
    /**
     * Create a Paymattic form post for the given GiveWP form data.
     *
     * When $dryRun is true, all logic executes (for validation/count purposes)
     * but no database writes occur and 0 is returned.
     *
     * @param array $giveForm  Form data array as returned by GiveFormReader::getAll().
     *                         Expected keys: id, title, goal_option, goal_amount,
     *                         goal_format, form_status.
     * @param bool  $dryRun    When true, skip all writes and return 0.
     *
     * @return int New wp_payform post ID, or 0 on dry run / insert failure.
     */
    public static function writeForm(array $giveForm, bool $dryRun = false): int
    {
        $isPro = defined('WPPAYFORMHASPRO');

        // ------------------------------------------------------------------
        // 1. Derive pricing configuration from the source GiveWP form.
        //
        // A. Donation levels → multiple_pricing
        //
        // multiple_pricing values are decimal dollar strings (NOT minor units).
        // Paymattic's DonationComponent::renderSingleChoice() calls
        // wpPayFormConverToCents() at render time, so we store dollars here.
        //
        // When no levels are present: fixed-price forms use _give_set_price as a
        // single option; custom-amount-only forms fall back to three defaults.
        // ------------------------------------------------------------------

        $donationLevels = $giveForm['donation_levels'] ?? [];
        $multiplePricing = [];

        foreach ($donationLevels as $level) {
            if (empty($level['_give_amount'])) {
                continue;
            }

            $multiplePricing[] = [
                'label' => isset($level['_give_text']) ? sanitize_text_field($level['_give_text']) : '',
                'value' => self::normalizeAmount($level['_give_amount']),
            ];
        }

        // When no levels are configured, use the form's fixed set_price if available
        // (price_option = 'set' means one fixed amount). Fall back to three defaults
        // only when even the fixed price is absent/zero.
        if (empty($multiplePricing)) {
            $setPriceDollars = self::normalizeAmount($giveForm['set_price'] ?? '0');
            if ((float) $setPriceDollars > 0) {
                $multiplePricing = [
                    ['label' => '', 'value' => $setPriceDollars],
                ];
            } else {
                $multiplePricing = [
                    ['label' => '', 'value' => '10'],
                    ['label' => '', 'value' => '25'],
                    ['label' => '', 'value' => '50'],
                ];
            }
        }

        // ------------------------------------------------------------------
        // B. Recurring configuration
        //
        // When the GiveWP form has _give_recurring = 'yes', we map the period
        // through IntervalMapper and configure the donation_item accordingly.
        // wpf_has_recurring_field postmeta must also be 'yes' so that Paymattic
        // loads recurring payment UI on the public form.
        // ------------------------------------------------------------------

        $isRecurring = ($giveForm['recurring_enabled'] ?? 'no') === 'yes';

        $billingInterval = 'month'; // safe default, overwritten below when recurring

        if ($isRecurring) {
            $mappedInterval  = IntervalMapper::mapInterval(
                $giveForm['recurring_period'] ?? 'month',
                (int) ($giveForm['recurring_frequency'] ?? 1)
            );
            $billingInterval = $mappedInterval['billing_interval'];
        }

        // ------------------------------------------------------------------
        // 2. Build the donation_item element.
        //
        // The element 'id' intentionally starts with 'donation_item_' so that
        // Paymattic's Submission model recognises it as the donation amount field
        // when querying wpf_order_items by parent_holder.
        //
        // pricing_details structure mirrors the Simple Donation Form demo template:
        // - one_time_type       : 'choose_single' — donor picks from preset amounts or enters custom
        // - multiple_pricing    : levels derived from GiveWP (see above)
        // - allow_custom_amount : mapped from GiveWP's _give_custom_amount meta ('enabled' → 'yes')
        // - allow_recurring     : set to 'yes' when the GiveWP form is recurring
        // - intervals / interval_options: restricted to the GiveWP-configured period
        //                         when recurring; kept as full list otherwise
        // ------------------------------------------------------------------

        $donationItemId = 'donation_item_' . wp_generate_uuid4();

        // ------------------------------------------------------------------
        // Gift Aid settings for the donation_item element.
        //
        // When the source GiveWP form had give_gift_aid_enable_disable =
        // 'enabled' or 'global', we set enable_gift_aid = 'yes' so that
        // Paymattic renders the Gift Aid checkbox and address fields.
        // The declaration text is preserved from the GiveWP form or falls
        // back to the Paymattic default.
        // ------------------------------------------------------------------

        $enableGiftAid     = ($giveForm['gift_aid_enabled'] ?? false) ? 'yes' : 'no';
        $giftAidDeclaration = (string) ($giveForm['gift_aid_declaration'] ?? '');
        if ($giftAidDeclaration === '' || self::isGiveWpDefaultDeclaration($giftAidDeclaration)) {
            $giftAidDeclaration = __('I am a UK taxpayer and want this donation to qualify for Gift Aid. I understand that the charity will reclaim 25p for every £1 I donate and that I must have paid at least that amount in UK Income Tax and/or Capital Gains Tax during the tax year.', 'wp-payment-form');
        }
        $giftAidTitle = __('Boost your donation with Gift Aid', 'wp-payment-form');

        // ------------------------------------------------------------------
        // Donation goal for the donation_item element.
        //
        // Paymattic stores donation_goals in MAJOR units (e.g. 1000 = $1000) —
        // it is rendered directly as "{currency}{donation_goals}" and divided
        // into the raised total to draw the progress bar. It must NOT be
        // converted to minor units here.
        //
        // GiveWP keeps a leftover _give_set_goal value even when the goal is
        // turned off (_give_goal_option = 'disabled'). Only honour the amount
        // when the goal was actually enabled; otherwise fall back to Paymattic's
        // default goal of 1000 so the migrated form never shows a stale/oversized
        // figure in the "Donation goal amount" field.
        // ------------------------------------------------------------------

        $hasGoal = ($giveForm['goal_option'] === 'enabled') && ((float) $giveForm['goal_amount'] > 0);

        $donationGoalValue = $hasGoal
            ? self::normalizeAmount($giveForm['goal_amount'])
            : '1000';

        if ($isPro) {
            $donationItemElement = [
                'type'           => 'donation_item',
                'editor_title'   => 'Donation Item',
                'group'          => 'payment',
                'postion_group'  => 'payment',
                'conditional_hide' => true,
                'is_system_field'  => true,
                'is_payment_field' => true,
                'editor_elements' => [
                    'label' => [
                        'label' => 'Field Label',
                        'type'  => 'text',
                        'group' => 'general',
                    ],
                    'required' => [
                        'label' => 'Required',
                        'type'  => 'switch',
                        'group' => 'general',
                    ],
                    'enable_image' => [
                        'label' => 'Enable Image',
                        'type'  => 'switch',
                        'group' => 'general',
                    ],
                    'payment_options' => [
                        'type'           => 'donation_options',
                        'group'          => 'general',
                        'label'          => 'Configure Donation Item',
                        'selection_type' => 'Payment Type',
                    ],
                ],
                'field_options' => [
                    'disable'                 => false,
                    'label'                   => 'Donation Amount',
                    'required'                => 'yes',
                    'enable_image'            => 'yes',
                    'intial_raising_amount'   => '0',
                    'conditional_logic_option' => [
                        'conditional_logic' => 'no',
                        'conditional_type'  => 'any',
                        'options'           => [
                            ['target_field' => '', 'condition' => '', 'value' => ''],
                        ],
                    ],
                    'pricing_details' => [
                        'show_statistic'      => 'yes',
                        'donation_goals'      => $donationGoalValue,
                        'progress_bar'        => 'yes',
                        'one_time_type'       => 'choose_single',
                        'image_url'           => [
                            [
                                'label' => '',
                                'value' => '',
                                'photo' => [
                                    'alt_text'    => 'donation-thumbnail-horizontal',
                                    'image_full'  => '',
                                    'image_thumb' => '',
                                ],
                            ],
                        ],
                        'multiple_pricing'    => $multiplePricing,
                        'allow_custom_amount' => ($giveForm['custom_amount_enabled'] === 'enabled') ? 'yes' : 'no',
                        'allow_recurring'     => $isRecurring ? 'yes' : 'no',
                        'bill_time_max'       => '0',
                        'intervals'           => $isRecurring ? [$billingInterval] : ['day', 'week', 'month', 'year'],
                        'interval_options'    => $isRecurring ? [$billingInterval] : ['day', 'week', 'month', 'year'],
                    ],
                    'default_value'        => 0,
                    'enable_gift_aid'      => $enableGiftAid,
                    'gift_aid_title'       => $giftAidTitle,
                    'gift_aid_declaration' => $giftAidDeclaration,
                ],
                'id'          => $donationItemId,
                'active_page' => 0,
            ];
        } else {
            $donationItemElement = [
                'type'           => 'donation_item',
                'editor_title'   => 'Donation Item',
                'group'          => 'payment',
                'postion_group'  => 'payment',
                'conditional_hide' => true,
                'is_system_field'  => true,
                'is_payment_field' => true,
                'editor_elements' => [
                    'label' => [
                        'label' => 'Field Label',
                        'type'  => 'text',
                        'group' => 'general',
                    ],
                    'required' => [
                        'label' => 'Required',
                        'type'  => 'switch',
                        'group' => 'general',
                    ],
                    'enable_image' => [
                        'label' => 'Enable Image',
                        'type'  => 'switch',
                        'group' => 'general',
                    ],
                    'payment_options' => [
                        'type'           => 'donation_options',
                        'group'          => 'general',
                        'label'          => 'Configure Donation Item',
                        'selection_type' => 'Payment Type',
                    ],
                ],
                'field_options' => [
                    'disable'                 => false,
                    'label'                   => 'Donation Amount',
                    'required'                => 'yes',
                    'enable_image'            => 'no',
                    'intial_raising_amount'   => '0',
                    'conditional_logic_option' => [
                        'conditional_logic' => 'no',
                        'conditional_type'  => 'any',
                        'options'           => [
                            ['target_field' => '', 'condition' => '', 'value' => ''],
                        ],
                    ],
                    'pricing_details' => [
                        'show_statistic'      => 'no',
                        'donation_goals'      => $donationGoalValue,
                        'progress_bar'        => 'no',
                        'one_time_type'       => 'choose_single',
                        'image_url'           => [
                            ['label' => '', 'value' => '', 'photo' => ['alt_text' => '', 'image_full' => '', 'image_thumb' => '']],
                        ],
                        // Pricing levels migrated from GiveWP donation levels, or the
                        // three default amounts when no levels are configured.
                        'multiple_pricing'    => $multiplePricing,
                        'allow_custom_amount' => ($giveForm['custom_amount_enabled'] === 'enabled') ? 'yes' : 'no',
                        // Recurring: enabled when GiveWP form had _give_recurring = 'yes'.
                        'allow_recurring'     => $isRecurring ? 'yes' : 'no',
                        'bill_time_max'       => '0',
                        // When recurring, restrict to the single mapped interval so the
                        // donor does not see conflicting billing cadence options.
                        // When not recurring, expose all intervals (admin can prune later).
                        'intervals'           => $isRecurring ? [$billingInterval] : ['day', 'week', 'month', 'year'],
                        'interval_options'    => $isRecurring ? [$billingInterval] : ['day', 'week', 'month', 'year'],
                    ],
                    'default_value'        => 0,
                    'enable_gift_aid'      => $enableGiftAid,
                    'gift_aid_title'       => $giftAidTitle,
                    'gift_aid_declaration' => $giftAidDeclaration,
                ],
                'id'          => $donationItemId,
                'active_page' => 0,
            ];
        }

        // ------------------------------------------------------------------
        // 3. Build the full builder settings array.
        //
        // We include a minimal name + email + donation item set, then append any
        // GiveWP Form Field Manager (FFM) custom fields so they appear in the
        // Paymattic form builder and entry detail view.
        // ------------------------------------------------------------------

        if ($isPro) {
            $choosePaymentMethodElement = [
                'type'             => 'choose_payment_method',
                'editor_title'     => 'Choose Payment Method',
                'group'            => 'payment_method_element',
                'conditional_hide' => true,
                'postion_group'    => 'payment_method',
                'single_only'      => true,
                'editor_elements'  => [],
                'field_options'    => [
                    'label'        => 'Select Payment Method',
                    'enable_image' => 'yes',
                    'method_settings' => [
                        'prefered_method'   => '',
                        'payment_settings'  => [
                            'stripe' => [
                                'enabled'                => 'yes',
                                'label'                  => 'Pay with Card (Stripe)',
                                'checkout_display_style' => [
                                    'style'                => 'stripe_checkout',
                                    'require_billing_info' => 'no',
                                ],
                                'verify_zip'             => 'no',
                                'checkKey'               => 'Credit/Debit Card (Stripe)',
                            ],
                            'offline' => [
                                'enabled'     => 'no',
                                'label'       => 'Direct bank transfer',
                                'description' => 'Make your payment directly into our bank account.',
                                'checkKey'    => 'Offline/Cheque Payment',
                            ],
                        ],
                    ],
                    'default_value'            => 'stripe',
                    'conditional_logic_option' => [
                        'conditional_logic' => 'no',
                        'conditional_type'  => 'any',
                        'options'           => [['target_field' => '', 'condition' => '', 'value' => '']],
                    ],
                ],
                'id' => 'choose_payment_method',
            ];

            $builderSettings = [
                // Donation item is first in the horizontal template.
                $donationItemElement,
                // Customer name field with quick_checkout_form flag.
                [
                    'type'                => 'customer_name',
                    'editor_title'        => 'Name',
                    'group'               => 'input',
                    'postion_group'       => 'general',
                    'is_pro'              => 'no',
                    'quick_checkout_form' => true,
                    'editor_elements' => [
                        'label'         => ['label' => 'Field Label',   'type' => 'text',   'group' => 'general'],
                        'placeholder'   => ['label' => 'Placeholder',   'type' => 'text',   'group' => 'general'],
                        'required'      => ['label' => 'Required',       'type' => 'switch', 'group' => 'general'],
                        'default_value' => ['label' => 'Default Value',  'type' => 'text',   'group' => 'general'],
                    ],
                    'field_options' => [
                        'label'       => 'Donor Name',
                        'placeholder' => 'Your Name',
                        'required'    => 'yes',
                    ],
                    'id'          => 'customer_name',
                    'active_page' => 0,
                ],
                // Customer email field with quick_checkout_form flag.
                [
                    'type'                => 'customer_email',
                    'editor_title'        => 'Email',
                    'group'               => 'input',
                    'postion_group'       => 'general',
                    'is_pro'              => 'no',
                    'quick_checkout_form' => true,
                    'editor_elements' => [
                        'label'         => ['label' => 'Field Label',   'type' => 'text',           'group' => 'general'],
                        'placeholder'   => ['label' => 'Placeholder',   'type' => 'text',           'group' => 'general'],
                        'required'      => ['label' => 'Required',       'type' => 'switch',         'group' => 'general'],
                        'confirm_email' => ['label' => 'Enable Confirm Email Field', 'type' => 'confirm_email_switch', 'group' => 'general'],
                        'default_value' => ['label' => 'Default Value',  'type' => 'text',           'group' => 'general'],
                    ],
                    'field_options' => [
                        'disable'             => false,
                        'label'               => 'Email Address',
                        'placeholder'         => 'Email Address',
                        'required'            => 'yes',
                        'confirm_email'       => 'no',
                        'confirm_email_label' => 'Confirm Email',
                        'default_value'       => '',
                    ],
                    'id'          => 'customer_email',
                    'active_page' => 0,
                ],
                // Payment method selector.
                $choosePaymentMethodElement,
            ];
        } else {
            $builderSettings = [
                // Customer name field.
                [
                    'type'           => 'customer_name',
                    'editor_title'   => 'Name',
                    'group'          => 'input',
                    'postion_group'  => 'general',
                    'is_pro'         => 'no',
                    'editor_elements' => [
                        'label'         => ['label' => 'Field Label',   'type' => 'text',   'group' => 'general'],
                        'placeholder'   => ['label' => 'Placeholder',   'type' => 'text',   'group' => 'general'],
                        'required'      => ['label' => 'Required',       'type' => 'switch', 'group' => 'general'],
                        'default_value' => ['label' => 'Default Value',  'type' => 'text',   'group' => 'general'],
                    ],
                    'field_options' => [
                        'label'       => 'Donor Name',
                        'placeholder' => 'Your Name',
                        'required'    => 'yes',
                    ],
                    'id'          => 'customer_name',
                    'active_page' => 0,
                ],
                // Customer email field.
                [
                    'type'           => 'customer_email',
                    'editor_title'   => 'Email',
                    'group'          => 'input',
                    'postion_group'  => 'general',
                    'is_pro'         => 'no',
                    'editor_elements' => [
                        'label'         => ['label' => 'Field Label',   'type' => 'text',           'group' => 'general'],
                        'placeholder'   => ['label' => 'Placeholder',   'type' => 'text',           'group' => 'general'],
                        'required'      => ['label' => 'Required',       'type' => 'switch',         'group' => 'general'],
                        'confirm_email' => ['label' => 'Enable Confirm Email Field', 'type' => 'confirm_email_switch', 'group' => 'general'],
                        'default_value' => ['label' => 'Default Value',  'type' => 'text',           'group' => 'general'],
                    ],
                    'field_options' => [
                        'disable'            => false,
                        'label'              => 'Email Address',
                        'placeholder'        => 'Email Address',
                        'required'           => 'yes',
                        'confirm_email'      => 'no',
                        'confirm_email_label'=> 'Confirm Email',
                        'default_value'      => '',
                    ],
                    'id'          => 'customer_email',
                    'active_page' => 0,
                ],
                // Donation item field (built above).
                $donationItemElement,
            ];
        }

        // ------------------------------------------------------------------
        // 3b. Append GiveWP Form Field Manager (FFM) custom field elements.
        //
        // buildFFMElements() returns [] when the give-form-fields postmeta is
        // absent (no FFM or no custom fields configured), so this is always safe.
        // Elements are appended after the core fields so they appear at the
        // bottom of the form — admin can reorder in the builder if needed.
        // ------------------------------------------------------------------

        $ffmElements = self::buildFFMElements(absint($giveForm['id']));
        if (!empty($ffmElements)) {
            $builderSettings = array_merge($builderSettings, $ffmElements);
        }

        // ------------------------------------------------------------------
        // 3c. Append currency_switcher element (GiveWP Currency Switcher add-on).
        //
        // Only injected when Paymattic Pro is active — the currency_switcher
        // component type is Pro-only. When active and the form had cs_status =
        // 'enabled' or 'global', we build the element from the form's supported
        // currency list (or the site-wide global list for 'global' status).
        //
        // CRITICAL: The element id MUST be the literal string 'currency_switcher'.
        // SubmissionHandler checks $form_data['currency_switcher'] by key to apply
        // the currency override. Any other id silently drops currency switching.
        //
        // The element is always appended AFTER the donation_item so the form has
        // both fields — the SubmissionHandler only applies the currency override
        // when donation_item is also present in $form_data.
        // ------------------------------------------------------------------

        $currencySwitcher    = $giveForm['currency_switcher'] ?? [];
        $csStatus            = $currencySwitcher['status'] ?? (($currencySwitcher['enabled'] ?? false) ? 'enabled' : 'disabled');
        $csActive            = in_array($csStatus, ['enabled', 'global'], true);
        $hasCurrencySwitcher = false;

        if ($isPro && $csActive) {
            $csElement = self::buildCurrencySwitcherElement($currencySwitcher);
            if ($csElement !== null) {
                $builderSettings[]   = $csElement;
                $hasCurrencySwitcher = true;

                // Log when per-currency custom prices were present — data is lost.
                if (!empty($currencySwitcher['has_custom_prices'])) {
                    MigrationState::addError(sprintf(
                        'Form ID %d had _give_cs_custom_prices: per-currency pricing cannot be ' .
                        'migrated to Paymattic. Admin must reconfigure pricing after migration.',
                        absint($giveForm['id'])
                    ));
                }
            }
        }

        // ------------------------------------------------------------------
        // 3d. Append fee_recovery_item element (give-fee-recovery add-on).
        //
        // fee_recovery_item is a pro-only component — skip when Pro is not active.
        // Injected when the source GiveWP form had _form_give_fee_recovery set
        // to 'enabled' or 'global'. The element id is the literal string
        // 'fee_recovery_item' so FeeRecoveryComponent::pushFeeRecoveryItem()
        // locates it by type in the formatted elements array.
        // ------------------------------------------------------------------

        $feeRecoveryData = $giveForm['fee_recovery'] ?? [];

        if ($isPro && !empty($feeRecoveryData['enabled'])) {
            $builderSettings = self::spliceBeforePaymentMethod(
                $builderSettings,
                self::buildFeeRecoveryElement($feeRecoveryData)
            );
        }

        // ------------------------------------------------------------------
        // 4. Build the form-level settings.
        //
        // show_goal / donation_goal / goal_format: present only when the GiveWP
        // form had a goal configured. We convert the goal amount to minor units
        // here so that Paymattic's goal progress calculation works correctly.
        //
        // form_disabled: present only when GiveWP form_status = 'closed', which
        // prevents new donations from being accepted.
        // ------------------------------------------------------------------

        $formSettings = [
            'currency' => '',  // Use site default — GiveWP per-form currency is in the switcher add-on.
        ];

        $formIsClosed = ($giveForm['form_status'] === 'closed');

        if ($hasGoal) {
            // donation_goal mirrors the donation_item element's donation_goals and
            // is stored in MAJOR units (not cents) for consistency.
            $formSettings['show_goal']      = true;
            $formSettings['donation_goal']  = $donationGoalValue;
            $formSettings['goal_format']    = sanitize_text_field($giveForm['goal_format'] ?? 'amount');
        }

        if ($formIsClosed) {
            // form_disabled = true disables submission on the Paymattic form render.
            $formSettings['form_disabled'] = true;
        }

        // ------------------------------------------------------------------
        // 5. Dry-run exit: log what would be created without writing.
        // ------------------------------------------------------------------

        if ($dryRun) {
            return 0;
        }

        $formTitle     = (string) ($giveForm['title'] ?? '');
        $campaignTitle = (string) ($giveForm['campaign_title'] ?? '');

        if ($campaignTitle !== '' && $campaignTitle !== $formTitle) {
            $composedTitle = $formTitle === ''
                ? $campaignTitle
                : sprintf('%s (%s)', $formTitle, $campaignTitle);
        } else {
            $composedTitle = $formTitle;
        }

        $postId = wp_insert_post([
            'post_type'   => 'wp_payform',
            'post_title'  => sanitize_text_field($composedTitle),
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ], true); // Second arg = return WP_Error on failure.

        if (is_wp_error($postId) || !$postId) {
            return 0;
        }

        $postId = absint($postId);

        // ------------------------------------------------------------------
        // 7. Persist form metadata.
        //
        // wppayform_paymentform_builder_settings: the Form model reads this key
        // directly via get_post_meta($formId, 'wppayform_paymentform_builder_settings', true).
        // It must be a PHP array (not JSON string) stored with update_post_meta,
        // which serializes it via WordPress's maybe_serialize.
        //
        // wppayform_paymentform_settings: form-level settings including goal.
        //
        // give_source_form_id: rollback marker — the migrator rollback writer
        // can query for all wp_payform posts where this meta key is set and
        // delete them to undo the form migration phase.
        //
        // wpf_has_payment_field: set to 'yes' so Paymattic knows to load
        // payment-related processing for this form.
        // ------------------------------------------------------------------

        update_post_meta($postId, 'wppayform_paymentform_builder_settings', $builderSettings);
        update_post_meta($postId, 'wppayform_paymentform_settings',          $formSettings);
        update_post_meta($postId, 'give_source_form_id',                     absint($giveForm['id']));
        update_post_meta($postId, 'wpf_has_payment_field',                   'yes');
        update_post_meta($postId, 'wpf_has_recurring_field',                 $isRecurring ? 'yes' : 'no');

        // When a currency_switcher element was injected, the form must inherit
        // currency from the global Paymattic currency settings so the switcher
        // component has a base currency to convert against.
        if ($hasCurrencySwitcher) {
            update_post_meta($postId, 'wppayform_paymentform_currency_settings', [
                'settings_type' => 'global',
            ]);
        }

        if ($isPro) {
            update_post_meta($postId, 'wpf_form_category', 'quick_form');
        }

        $emailNotifications = EmailNotificationMapper::mapNotifications(
            $giveForm['email_notifications'] ?? [],
            $giveForm['email_from_name']      ?? '',
            $giveForm['email_from_email']     ?? ''
        );
        update_post_meta($postId, 'wpf_email_notifications', $emailNotifications);

        // Store a default submit button so the form renders properly out of the box.
        update_post_meta($postId, 'wppayform_submit_button_settings', [
            'button_text'      => __('Donate Now', 'wp-payment-form'),
            'processing_text'  => __('Please Wait...', 'wp-payment-form'),
            'button_style'     => 'wpf_default_btn',
            'css_class'        => '',
        ]);

        return $postId;
    }

    /**
     * Upsert FFM elements on an already-migrated Paymattic form.
     *
     * Removes stale FFM elements (by matching IDs against the current field defs)
     * and appends fresh, properly-structured replacements. Safe to call multiple
     * times: produces the same result regardless of prior state.
     *
     * @param int $giveFormId  Source GiveWP form post ID.
     * @param int $wpfFormId   Target Paymattic wp_payform post ID.
     * @return bool            True when builder settings were updated; false otherwise.
     */
    public static function patchFFMElementsIfMissing(int $giveFormId, int $wpfFormId): bool
    {
        // Fresh elements for fields that have a Paymattic equivalent (skips file_upload etc.).
        $ffmElements = self::buildFFMElements($giveFormId);

        // ALL field defs — used to build the strip-set so stale elements for skipped
        // types (e.g. file_upload) are also removed from the builder settings.
        $allDefs  = FFMEnricher::getAllFieldDefs($giveFormId);
        $allFFMIds = [];
        foreach ($allDefs as $def) {
            $fieldName = sanitize_key($def['name'] ?? '');
            if ($fieldName !== '') {
                $allFFMIds['ffm_' . $fieldName] = true;
            }
        }

        if (empty($allDefs)) {
            return false;
        }

        $current = get_post_meta($wpfFormId, 'wppayform_paymentform_builder_settings', true);
        $current = is_array($current) ? $current : (wppayform_safeUnserialize($current) ?: []);

        // Strip ALL FFM-owned elements (including stale ones for skipped types).
        $coreElements = array_values(array_filter($current, function ($el) use ($allFFMIds) {
            return !isset($allFFMIds[$el['id'] ?? '']);
        }));

        // Append only the fields that have a valid Paymattic mapping.
        $updated = !empty($ffmElements) ? array_merge($coreElements, $ffmElements) : $coreElements;

        if ($updated === $current) {
            return false;
        }

        update_post_meta($wpfFormId, 'wppayform_paymentform_builder_settings', $updated);

        return true;
    }

    /**
     * Upsert wpf_email_notifications on an already-migrated Paymattic form.
     *
     * Safe to call on every rerun: uses update_post_meta, which overwrites any
     * prior value. Bulk-loads give_formmeta to avoid per-notification DB queries.
     *
     * @param int $giveFormId  Source GiveWP form post ID.
     * @param int $wpfFormId   Target Paymattic wp_payform post ID.
     */
    public static function patchEmailNotifications(int $giveFormId, int $wpfFormId): void
    {
        global $wpdb;

        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix (trusted); all values bound via $wpdb->prepare().
        $rawMeta = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->prefix}give_formmeta WHERE form_id = %d",
                $giveFormId
            ),
            ARRAY_A
        );
        // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared

        $meta = [];
        foreach ($rawMeta as $row) {
            $meta[$row['meta_key']] = $row['meta_value'];
        }

        $notifications = GiveEmailReader::getFormEmailNotifications($giveFormId, $meta);
        $fromName      = sanitize_text_field((string) ($meta['_give_from_name']  ?? ''));
        $fromEmail     = sanitize_email((string)       ($meta['_give_from_email'] ?? ''));

        $mapped = EmailNotificationMapper::mapNotifications($notifications, $fromName, $fromEmail);
        update_post_meta($wpfFormId, 'wpf_email_notifications', $mapped);
    }

    /**
     * Upsert Gift Aid settings on an already-migrated Paymattic form's donation_item.
     *
     * Reads give_gift_aid_enable_disable from wp_postmeta on the source form.
     * When enabled (or 'global'), sets enable_gift_aid = 'yes' and writes the
     * declaration text on every donation_item element in the builder settings.
     * No-ops when Gift Aid is not active on the source form or when
     * the target form already has enable_gift_aid = 'yes'.
     *
     * Safe to call on every re-run — idempotent.
     *
     * @param int $giveFormId  Source GiveWP form post ID.
     * @param int $wpfFormId   Target Paymattic wp_payform post ID.
     * @return bool            True when builder settings were updated; false otherwise.
     */
    public static function patchGiftAidSettings(int $giveFormId, int $wpfFormId): bool
    {
        $giftAidStatus = sanitize_text_field((string) get_post_meta($giveFormId, 'give_gift_aid_enable_disable', true));
        $hasGiftAid    = in_array($giftAidStatus, ['enabled', 'global'], true);

        if (!$hasGiftAid) {
            return false;
        }

        $declarationRaw = sanitize_text_field((string) get_post_meta($giveFormId, 'give_gift_aid_agreement', true));
        $declaration    = ($declarationRaw === '' || self::isGiveWpDefaultDeclaration($declarationRaw))
            ? __('I am a UK taxpayer and want this donation to qualify for Gift Aid. I understand that the charity will reclaim 25p for every £1 I donate and that I must have paid at least that amount in UK Income Tax and/or Capital Gains Tax during the tax year.', 'wp-payment-form')
            : $declarationRaw;
        $patchTitle     = __('Boost your donation with Gift Aid', 'wp-payment-form');

        $current = get_post_meta($wpfFormId, 'wppayform_paymentform_builder_settings', true);
        $current = is_array($current) ? $current : (wppayform_safeUnserialize($current) ?: []);

        $changed = false;
        foreach ($current as &$element) {
            if (isset($element['type']) && $element['type'] === 'donation_item') {
                if (($element['field_options']['enable_gift_aid'] ?? '') !== 'yes') {
                    $element['field_options']['enable_gift_aid']      = 'yes';
                    $element['field_options']['gift_aid_title']       = $patchTitle;
                    $element['field_options']['gift_aid_declaration']  = $declaration;
                    $changed = true;
                }
            }
        }
        unset($element);

        if ($changed) {
            update_post_meta($wpfFormId, 'wppayform_paymentform_builder_settings', $current);
        }

        return $changed;
    }

    /**
     * Build a Paymattic currency_switcher element from GiveWP currency switcher data.
     *
     * The element id MUST be the literal string 'currency_switcher' — SubmissionHandler
     * reads $form_data['currency_switcher'] by key. A UUID-based id would silently drop
     * currency switching at submission time.
     *
     * Currency names are resolved via GiveWP's give_get_currencies() (authoritative,
     * 172 ISO 4217 currencies) with a minimal fallback for common codes in case
     * GiveWP functions are unavailable.
     *
     * Returns null when the supported currency list is empty after resolution (meaning
     * nothing can be injected — skip silently).
     *
     * @param array $currencySwitcher  The currency_switcher sub-array from GiveFormReader.
     *
     * @return array|null  Ready-to-use builder element array, or null when unusable.
     */

    /**
     * Upsert a fee_recovery_item element on an already-migrated Paymattic form.
     *
     * Reads fee recovery config directly from give_formmeta for the source form.
     * Appends the element only when fee recovery is active and no fee_recovery_item
     * element is already present. Safe to call on every re-run — idempotent.
     *
     * No-ops when:
     *   - Paymattic Pro is not active (fee_recovery_item is pro-only)
     *   - _form_give_fee_recovery is 'disabled' or absent on the source form
     *   - A fee_recovery_item element already exists in the builder settings
     *
     * @param int $giveFormId  Source GiveWP form post ID.
     * @param int $wpfFormId   Target Paymattic wp_payform post ID.
     * @return bool            True when builder settings were updated; false otherwise.
     */
    public static function patchFeeRecoveryElement(int $giveFormId, int $wpfFormId): bool
    {
        if (!defined('WPPAYFORMHASPRO')) {
            return false;
        }

        global $wpdb;

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from $wpdb->prefix only
        $rawMeta = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->prefix}give_formmeta WHERE form_id = %d",
                $giveFormId
            ),
            ARRAY_A
        );

        $meta = [];
        foreach ($rawMeta as $row) {
            $meta[$row['meta_key']] = $row['meta_value'];
        }

        $feeStatus = sanitize_text_field((string) ($meta['_form_give_fee_recovery'] ?? 'disabled'));
        if (!in_array($feeStatus, ['enabled', 'global'], true)) {
            return false;
        }

        $current = get_post_meta($wpfFormId, 'wppayform_paymentform_builder_settings', true);
        $current = is_array($current) ? $current : (wppayform_safeUnserialize($current) ?: []);

        // Skip if a fee_recovery_item is already present.
        foreach ($current as $element) {
            if (isset($element['type']) && $element['type'] === 'fee_recovery_item') {
                return false;
            }
        }

        // Reconstruct the fee recovery config from the raw meta map.
        $feeRecovery = [
            'enabled'       => true,
            'percent'       => isset($meta['_form_give_fee_percentage'])
                                   ? (float) $meta['_form_give_fee_percentage']
                                   : 2.9,
            'base_amount'   => isset($meta['_form_give_fee_base_amount'])
                                   ? (float) $meta['_form_give_fee_base_amount']
                                   : 0.30,
            'mode'          => sanitize_text_field((string) ($meta['_form_give_fee_mode'] ?? 'donor_opt_in')),
            'configuration' => sanitize_text_field((string) ($meta['_form_give_fee_configuration'] ?? 'all_gateways')),
            'per_gateway'   => [],
        ];

        foreach ($meta as $metaKey => $metaValue) {
            if (strpos($metaKey, '_form_gateway_fee_percentage_') === 0) {
                $gwSlug = sanitize_key(substr($metaKey, strlen('_form_gateway_fee_percentage_')));
                if ($gwSlug !== '') {
                    $enableKey = '_form_gateway_fee_enable_' . $gwSlug;
                    if (!isset($meta[$enableKey]) || $meta[$enableKey] !== 'enabled') {
                        continue;
                    }
                    if (!isset($feeRecovery['per_gateway'][$gwSlug])) {
                        $feeRecovery['per_gateway'][$gwSlug] = ['percent' => 0.0, 'base_amount' => 0.0];
                    }
                    $feeRecovery['per_gateway'][$gwSlug]['percent'] = (float) $metaValue;
                }
            }
            if (strpos($metaKey, '_form_gateway_fee_base_amount_') === 0) {
                $gwSlug = sanitize_key(substr($metaKey, strlen('_form_gateway_fee_base_amount_')));
                if ($gwSlug !== '') {
                    $enableKey = '_form_gateway_fee_enable_' . $gwSlug;
                    if (!isset($meta[$enableKey]) || $meta[$enableKey] !== 'enabled') {
                        continue;
                    }
                    if (!isset($feeRecovery['per_gateway'][$gwSlug])) {
                        $feeRecovery['per_gateway'][$gwSlug] = ['percent' => 0.0, 'base_amount' => 0.0];
                    }
                    $feeRecovery['per_gateway'][$gwSlug]['base_amount'] = (float) $metaValue;
                }
            }
        }

        $current = self::spliceBeforePaymentMethod($current, self::buildFeeRecoveryElement($feeRecovery));
        update_post_meta($wpfFormId, 'wppayform_paymentform_builder_settings', $current);

        return true;
    }

    /**
     * Build a Paymattic fee_recovery_item element from GiveWP fee recovery config.
     *
     * Maps GiveWP fee recovery form meta to the field_options structure expected
     * by FeeRecoveryComponent::component() in wp-payment-form-pro. The element id
     * is the literal string 'fee_recovery_item' — this is also the component type
     * and is used as parent_holder on the order item written at submission time.
     *
     * Mapping:
     *   _form_give_fee_percentage  → global_rate.percent  (float)
     *   _form_give_fee_base_amount → global_rate.fixed    (dollars * 100 → cents integer)
     *   _form_give_fee_mode:
     *     'donor_opt_in'  → donor_opt_in = true  (checkbox shown to donor)
     *     'forced_opt_in' → donor_opt_in = false (fee added silently)
     *   _form_give_fee_configuration:
     *     'all_gateways' → rate_type = 'global_rate'
     *     'per_gateway'  → rate_type = 'per_gateway' + gateway_rates rows
     *
     * For per-gateway rates, GiveWP gateway slugs are mapped through GatewayMapper
     * so the stored gateway keys match Paymattic's gateway identifiers.
     * Duplicate mapped gateways (e.g. both 'stripe' and 'stripe_checkout' map to
     * 'stripe') are deduplicated — last value wins.
     *
     * @param array $feeRecovery  Fee recovery sub-array from GiveFormReader::getAll().
     * @return array              Ready-to-use builder element array.
     */
    private static function buildFeeRecoveryElement(array $feeRecovery): array
    {
        $donorOptIn = ($feeRecovery['mode'] ?? 'donor_opt_in') === 'donor_opt_in';
        $rateType   = (($feeRecovery['configuration'] ?? 'all_gateways') === 'per_gateway')
                      ? 'per_gateway'
                      : 'global_rate';

        $percent    = (float)  ($feeRecovery['percent']     ?? 2.9);
        // base_amount stored as dollars (e.g. 0.30) → convert to cents for Paymattic.
        $fixedCents = absint((int) round((float) ($feeRecovery['base_amount'] ?? 0.30) * 100));

        $gatewayRates = [];
        if ($rateType === 'per_gateway') {
            $deduped = [];
            foreach ($feeRecovery['per_gateway'] ?? [] as $giveGateway => $rates) {
                $wpfGateway = GatewayMapper::mapGateway((string) $giveGateway);
                // Last value wins when multiple GiveWP gateways map to the same
                // Paymattic slug (e.g. 'stripe' + 'stripe_checkout' → 'stripe').
                $deduped[$wpfGateway] = [
                    'gateway' => $wpfGateway,
                    'percent' => (float) ($rates['percent']     ?? 0.0),
                    'fixed'   => absint((int) round((float) ($rates['base_amount'] ?? 0.0) * 100)),
                ];
            }
            $gatewayRates = array_values($deduped);
        }

        return [
            'type'                => 'fee_recovery_item',
            'editor_title'        => 'Fee Recovery',
            'group'               => 'payment',
            'postion_group'       => 'payment',
            'is_pro'              => 'yes',
            'is_system_field'     => true,
            'is_payment_field'    => true,
            'quick_checkout_form' => true,
            'editor_elements'  => [
                'fee_recovery_settings' => [
                    'type'  => 'fee_recovery_settings',
                    'group' => 'general',
                    'label' => 'Fee Recovery Settings',
                ],
                'admin_label'        => ['label' => 'Admin Label',             'type' => 'text', 'group' => 'advanced'],
                'wrapper_class'      => ['label' => 'Field Wrapper CSS Class', 'type' => 'text', 'group' => 'advanced'],
                'element_class'      => ['label' => 'Input Element CSS Class', 'type' => 'text', 'group' => 'advanced'],
                'conditional_render' => [
                    'type'              => 'conditional_render',
                    'group'             => 'advanced',
                    'label'             => 'Conditional Render',
                    'selection_type'    => 'Conditional logic',
                    'conditional_logic' => ['yes' => 'Yes', 'no' => 'No'],
                    'conditional_type'  => ['any' => 'Any', 'all' => 'All'],
                ],
            ],
            'field_options' => [
                'disable'      => false,
                'label'        => __('I\'d like to cover the {fee_amount} transaction fee.', 'wp-payment-form'),
                'rate_type'    => $rateType,
                'donor_opt_in' => $donorOptIn,
                'global_rate'  => ['percent' => $percent, 'fixed' => $fixedCents],
                'gateway_rates' => $gatewayRates,
                'conditional_logic_option' => [
                    'conditional_logic' => 'no',
                    'conditional_type'  => 'any',
                    'options'           => [
                        ['target_field' => '', 'condition' => '', 'value' => ''],
                    ],
                ],
            ],
            // 'fee_recovery_item' is the component type and is used as parent_holder
            // on the order item written at submission time by FeeRecoveryComponent.
            'id' => 'fee_recovery_item',
        ];
    }

    /**
     * Insert $element into $elements immediately before the first element whose
     * group is 'payment_method_element'. Falls back to append when no such element
     * exists (standard forms without a payment-method selector).
     *
     * @param array[] $elements  Existing builder-settings element array.
     * @param array   $element   Element to insert.
     * @return array[]           Updated element array.
     */
    private static function spliceBeforePaymentMethod(array $elements, array $element): array
    {
        foreach ($elements as $i => $el) {
            if (($el['group'] ?? '') === 'payment_method_element') {
                array_splice($elements, $i, 0, [$element]);
                return $elements;
            }
        }
        $elements[] = $element;
        return $elements;
    }

    /**
     * Returns true if $text matches GiveWP's built-in default Gift Aid declaration.
     * Used during migration to replace the GiveWP default with Paymattic's text.
     */
    private static function isGiveWpDefaultDeclaration(string $text): bool
    {
        return strpos($text, 'By ticking the') === 0;
    }

    private static function buildCurrencySwitcherElement(array $currencySwitcher): ?array
    {
        $supported = (array) ($currencySwitcher['supported'] ?? []);

        // 'global' status means the form inherits the site-wide currency list.
        if (empty($supported) && ($currencySwitcher['status'] ?? 'disabled') === 'global') {
            $globalCs  = function_exists('give_get_option') ? give_get_option('cs_supported_currency', []) : [];
            $supported = is_array($globalCs) ? $globalCs : [];
        }

        if (empty($supported)) {
            return null;
        }

        // Build code → name map from GiveWP's canonical currency list (172 currencies).
        $currencyNames = [];
        if (function_exists('give_get_currencies')) {
            $giveCurrencies = give_get_currencies('admin_label');
            if (is_array($giveCurrencies)) {
                foreach ($giveCurrencies as $code => $name) {
                    $currencyNames[strtoupper((string) $code)] = (string) $name;
                }
            }
        }

        // Fallback map for the most common currencies when GiveWP functions are absent.
        $fallback = [
            'USD' => 'US Dollars',         'EUR' => 'Euros',              'GBP' => 'Pounds Sterling',
            'CAD' => 'Canadian Dollars',   'AUD' => 'Australian Dollars', 'JPY' => 'Japanese Yen',
            'CHF' => 'Swiss Franc',        'INR' => 'Indian Rupee',       'MXN' => 'Mexican Peso',
            'BRL' => 'Brazilian Real',     'SEK' => 'Swedish Krona',      'NOK' => 'Norwegian Krone',
            'DKK' => 'Danish Krone',       'NZD' => 'New Zealand Dollar', 'SGD' => 'Singapore Dollar',
            'HKD' => 'Hong Kong Dollar',   'ZAR' => 'South African Rand', 'CNY' => 'Chinese Yuan',
            'KRW' => 'South Korean Won',   'TRY' => 'Turkish Lira',       'RUB' => 'Russian Rubles',
        ];

        $switchOptions = [];
        foreach ($supported as $code) {
            $code = strtoupper(sanitize_text_field((string) $code));
            if (empty($code)) {
                continue;
            }
            $name            = $currencyNames[$code] ?? $fallback[$code] ?? $code;
            $switchOptions[] = ['label' => $name, 'value' => $code];
        }

        if (empty($switchOptions)) {
            return null;
        }

        $defaultValue = sanitize_text_field($currencySwitcher['default_currency'] ?? '');

        return [
            'type'                => 'currency_switcher',
            'editor_title'        => 'Currency Switcher',
            'group'               => 'currency',
            'postion_group'       => 'payment',
            'is_pro'              => 'yes',
            'single_only'         => true,
            'quick_checkout_form' => true,
            'editor_elements'     => [
                'label'              => ['label' => 'Field Label',             'type' => 'text',          'group' => 'general'],
                'required'           => ['label' => 'Required',                'type' => 'switch',         'group' => 'general'],
                'switch_options'     => ['label' => 'Currency Choices',        'type' => 'currency_pair',  'group' => 'general'],
                'admin_label'        => ['label' => 'Admin Label',             'type' => 'text',           'group' => 'advanced'],
                'wrapper_class'      => ['label' => 'Field Wrapper CSS Class', 'type' => 'text',           'group' => 'advanced'],
                'element_class'      => ['label' => 'Input Element CSS Class', 'type' => 'text',           'group' => 'advanced'],
                'conditional_render' => [
                    'type'              => 'conditional_render',
                    'group'             => 'advanced',
                    'label'             => 'Conditional render',
                    'selection_type'    => 'Conditional logic',
                    'conditional_logic' => ['yes' => 'Yes', 'no' => 'No'],
                    'conditional_type'  => ['any' => 'Any', 'all' => 'All'],
                ],
            ],
            'field_options'       => [
                'label'                    => 'Select Currency',
                'required'                 => 'no',
                'primary_currency'         => '',
                'display_type'             => 'select',
                'default_value'            => $defaultValue,
                'switch_options'           => $switchOptions,
                'conditional_logic_option' => [
                    'conditional_logic' => 'no',
                    'conditional_type'  => 'any',
                    'options'           => [['target_field' => '', 'condition' => '', 'value' => '']],
                ],
            ],
            // MUST be the literal string 'currency_switcher' — SubmissionHandler reads
            // $form_data['currency_switcher'] by key to apply the currency override.
            'id'                  => 'currency_switcher',
        ];
    }

    public static function buildFFMElements(int $giveFormId): array
    {
        $defs = FFMEnricher::getAllFieldDefs($giveFormId);

        if (empty($defs)) {
            return [];
        }

        $elements = [];

        foreach ($defs as $field) {
            $inputType     = (string) ($field['input_type'] ?? '');
            $fieldName     = 'ffm_' . sanitize_key($field['name'] ?? '');
            $label         = sanitize_text_field((string) ($field['label'] ?? $field['name'] ?? ''));
            $required      = ($field['required'] ?? 'no') === 'yes' ? 'yes' : 'no';

            if (empty($fieldName)) {
                continue;
            }

            switch ($inputType) {
                case 'textarea':
                case 'repeat':
                    $elements[] = self::buildTextareaElement($fieldName, $label, $required);
                    break;

                case 'select':
                    $elements[] = self::buildChoiceElement($fieldName, $label, $required, $field, 'select');
                    break;

                case 'radio':
                    $elements[] = self::buildChoiceElement($fieldName, $label, $required, $field, 'radio');
                    break;

                case 'multiselect':
                case 'checkbox':
                    $elements[] = self::buildChoiceElement($fieldName, $label, $required, $field, 'checkbox');
                    break;

                case 'consent':
                    $elements[] = self::buildConsentElement($fieldName, $label, $required);
                    break;

                case 'phone':
                    $elements[] = self::buildPhoneElement($fieldName, $label, $required);
                    break;

                case 'hidden':
                    $elements[] = self::buildHiddenElement($fieldName, $label);
                    break;

                case 'file_upload':
                    // No matching Paymattic Lite component — skip.
                    break;

                // text, email, url, date, consent → 'text'.
                default:
                    $elements[] = self::buildTextElement($fieldName, $label, $required);
                    break;
            }
        }

        return $elements;
    }

    /**
     * Normalise a GiveWP decimal money string to a clean Paymattic amount string.
     *
     * GiveWP stores donation levels / prices with six trailing decimals
     * (e.g. "10.000000"). Paymattic renders the donation_item value verbatim in
     * the amount input, so the raw string would display as "10.000000". This
     * strips the redundant decimals: whole amounts become integer strings
     * ("10"), fractional amounts keep two decimals ("10.50").
     *
     * @param mixed $value Raw amount (string or numeric).
     *
     * @return string Clean amount string in major units.
     */
    private static function normalizeAmount($value): string
    {
        $float = (float) $value;

        return (fmod($float, 1.0) === 0.0)
            ? (string) (int) $float
            : (string) round($float, 2);
    }

    /**
     * Returns the standard conditional_logic_option default array used by all
     * renderable components that support conditional logic.
     */
    private static function conditionalLogicDefault(): array
    {
        return [
            'conditional_logic' => 'no',
            'conditional_type'  => 'any',
            'options'           => [
                ['target_field' => '', 'condition' => '', 'value' => ''],
            ],
        ];
    }

    /**
     * Returns the standard advanced editor_elements shared by text-like and
     * choice components (admin_label, wrapper_class, element_class, conditional_render).
     */
    private static function advancedEditorElements(): array
    {
        return [
            'admin_label'        => ['label' => 'Admin Label',               'type' => 'text',   'group' => 'advanced'],
            'wrapper_class'      => ['label' => 'Field Wrapper CSS Class',    'type' => 'text',   'group' => 'advanced'],
            'element_class'      => ['label' => 'Input Element CSS Class',    'type' => 'text',   'group' => 'advanced'],
            'conditional_render' => [
                'type'              => 'conditional_render',
                'group'             => 'advanced',
                'label'             => 'Conditional render',
                'selection_type'    => 'Conditional logic',
                'conditional_logic' => ['yes' => 'Yes', 'no' => 'No'],
                'conditional_type'  => ['any' => 'Any', 'all' => 'All'],
            ],
        ];
    }

    /**
     * Build a Paymattic 'text' (Single Line Text) element.
     * Matches TextComponent::component() field_options and editor_elements exactly.
     */
    private static function buildTextElement(string $id, string $label, string $required): array
    {
        return [
            'type'            => 'text',
            'editor_title'    => 'Single Line Text',
            'group'           => 'input',
            'postion_group'   => 'general',
            'is_pro'          => 'no',
            'quick_checkout_form' => true,
            'editor_elements' => array_merge(
                [
                    'label'         => ['label' => 'Field Label',    'type' => 'text',   'group' => 'general'],
                    'placeholder'   => ['label' => 'Placeholder',    'type' => 'text',   'group' => 'general'],
                    'required'      => ['label' => 'Required',        'type' => 'switch', 'group' => 'general'],
                    'default_value' => ['label' => 'Default Value',   'type' => 'text',   'group' => 'general'],
                ],
                self::advancedEditorElements()
            ),
            'field_options' => [
                'label'                    => $label,
                'placeholder'              => '',
                'required'                 => $required,
                'default_value'            => '',
                'conditional_logic_option' => self::conditionalLogicDefault(),
            ],
            'id' => $id,
        ];
    }

    /**
     * Build a Paymattic 'textarea' (Multi Line Text) element.
     * Matches TextAreaComponent::component() field_options exactly.
     */
    private static function buildTextareaElement(string $id, string $label, string $required): array
    {
        return [
            'type'            => 'textarea',
            'editor_title'    => 'Multi Line Text',
            'group'           => 'input',
            'postion_group'   => 'general',
            'is_pro'          => 'no',
            'quick_checkout_form' => true,
            'editor_elements' => array_merge(
                [
                    'label'       => ['label' => 'Field Label',  'type' => 'text',   'group' => 'general'],
                    'placeholder' => ['label' => 'Placeholder',  'type' => 'text',   'group' => 'general'],
                    'required'    => ['label' => 'Required',      'type' => 'switch', 'group' => 'general'],
                ],
                self::advancedEditorElements()
            ),
            'field_options' => [
                'label'                    => $label,
                'placeholder'              => '',
                'min_height'               => '',
                'required'                 => $required,
                'conditional_logic_option' => self::conditionalLogicDefault(),
            ],
            'id' => $id,
        ];
    }

    /**
     * Build a Paymattic 'terms_conditions' (Consent/T&C) element.
     * Matches ConsentComponent::component() field_options exactly.
     */
    private static function buildConsentElement(string $id, string $label, string $required): array
    {
        return [
            'type'             => 'terms_conditions',
            'editor_title'     => 'Consent/T&C',
            'group'            => 'input',
            'postion_group'    => 'general',
            'is_pro'           => 'no',
            'conditional_hide' => true,
            'editor_elements'  => [
                'label'              => ['label' => 'Terms Text',               'type' => 'textarea', 'group' => 'general', 'info' => 'Provide Terms & Conditions / Consent Text (HTML Supported)'],
                'tc_description'     => ['label' => 'Terms Description (optional)', 'type' => 'html', 'group' => 'general', 'info' => 'The full description of your terms and condition. It will show as scrollable text'],
                'required'           => ['label' => 'Required',                 'type' => 'switch',   'group' => 'general'],
                'default_value'      => ['label' => 'Default Value',            'type' => 'text',     'group' => 'general', 'info' => 'Keep value 1 if you want to make it pre-checked by default'],
                'admin_label'        => ['label' => 'Admin Label',              'type' => 'text',     'group' => 'advanced'],
                'wrapper_class'      => ['label' => 'Field Wrapper CSS Class',  'type' => 'text',     'group' => 'advanced'],
                'conditional_render' => [
                    'type'              => 'conditional_render',
                    'group'             => 'advanced',
                    'label'             => 'Conditional render',
                    'selection_type'    => 'Conditional logic',
                    'conditional_logic' => ['yes' => 'Yes', 'no' => 'No'],
                    'conditional_type'  => ['any' => 'Any', 'all' => 'All'],
                ],
            ],
            'field_options' => [
                'label'                    => $label ?: 'I Agree With The Terms And Condition',
                'required'                 => $required,
                'wrapper_class'            => '',
                'admin_label'              => 'Terms & Condition Agreement',
                'tc_description'           => '',
                'conditional_logic_option' => self::conditionalLogicDefault(),
            ],
            'id' => $id,
        ];
    }

    /**
     * Build a Paymattic 'phone' (Phone Number) element.
     * Matches CustomPhoneNumber::component() field_options exactly.
     */
    private static function buildPhoneElement(string $id, string $label, string $required): array
    {
        return [
            'type'                => 'phone',
            'editor_title'        => 'Phone Number',
            'group'               => 'input',
            'postion_group'       => 'general',
            'is_pro'              => 'no',
            'quick_checkout_form' => true,
            'conditional_hide'    => true,
            'editor_elements'     => [
                'label'                  => ['label' => 'Field Label',    'type' => 'text',              'group' => 'general'],
                'required'               => ['label' => 'Required',        'type' => 'switch',            'group' => 'general'],
                'default_country_code'   => ['label' => 'Default Country', 'type' => 'select_option',    'group' => 'general', 'options' => [], 'creatable' => 'yes'],
                'active_list'            => ['label' => 'Show status',     'type' => 'radio_button',      'group' => 'general', 'options' => [], 'creatable' => 'yes'],
                'priority_country_code'  => ['label' => 'Country List',    'type' => 'select_multi_tags', 'group' => 'general', 'options' => [], 'creatable' => 'yes'],
                'admin_label'            => ['label' => 'Admin Label',               'type' => 'text', 'group' => 'advanced'],
                'wrapper_class'          => ['label' => 'Field Wrapper CSS Class',    'type' => 'text', 'group' => 'advanced'],
                'element_class'          => ['label' => 'Input Element CSS Class',    'type' => 'text', 'group' => 'advanced'],
                'conditional_render'     => [
                    'type'              => 'conditional_render',
                    'group'             => 'advanced',
                    'label'             => 'Conditional render',
                    'selection_type'    => 'Conditional logic',
                    'conditional_logic' => ['yes' => 'Yes', 'no' => 'No'],
                    'conditional_type'  => ['any' => 'Any', 'all' => 'All'],
                ],
            ],
            'field_options' => [
                'label'                    => $label,
                'placeholder'              => '',
                'required'                 => $required,
                'active_list'              => 'all',
                'conditional_logic_option' => self::conditionalLogicDefault(),
            ],
            'id' => $id,
        ];
    }

    /**
     * Build a Paymattic choice element (select, radio, or checkbox).
     * Matches SelectComponent / RadioComponent / CheckBoxComponent field_options exactly.
     *
     * @param string $type  'select' | 'radio' | 'checkbox'
     */
    private static function buildChoiceElement(
        string $id,
        string $label,
        string $required,
        array  $field,
        string $type
    ): array {
        // Normalize options from getAllFieldDefs() format: [['label'=>..,'value'=>..], ...]
        $rawOptions = $field['options'] ?? [];
        $options    = [];

        if (is_array($rawOptions)) {
            foreach ($rawOptions as $opt) {
                if (is_array($opt)) {
                    $optLabel = sanitize_text_field((string) ($opt['label'] ?? ''));
                    $optValue = sanitize_text_field((string) ($opt['value'] ?? $optLabel));
                } else {
                    $optLabel = sanitize_text_field((string) $opt);
                    $optValue = $optLabel;
                }
                if ($optLabel !== '') {
                    $options[] = ['label' => $optLabel, 'value' => $optValue];
                }
            }
        }

        if (empty($options)) {
            $options = [['label' => 'Option 1', 'value' => 'Option 1']];
        }

        switch ($type) {
            case 'select':
                $editorTitle     = 'Dropdown Field';
                $editorElements  = array_merge(
                    [
                        'label'         => ['label' => 'Field Label',    'type' => 'text',     'group' => 'general'],
                        'placeholder'   => ['label' => 'Placeholder',    'type' => 'text',     'group' => 'general'],
                        'required'      => ['label' => 'Required',        'type' => 'switch',   'group' => 'general'],
                        'default_value' => ['label' => 'Default Value',   'type' => 'text',     'group' => 'general'],
                        'options'       => ['label' => 'Field Choices',   'type' => 'key_pair', 'group' => 'general'],
                    ],
                    self::advancedEditorElements()
                );
                $fieldOptions = [
                    'label'                    => $label,
                    'placeholder'              => '',
                    'required'                 => $required,
                    'default_value'            => '',
                    'conditional_logic_option' => self::conditionalLogicDefault(),
                    'options'                  => $options,
                ];
                break;

            case 'radio':
                $editorTitle    = 'Radio Field';
                $editorElements = array_merge(
                    [
                        'label'         => ['label' => 'Field Label',  'type' => 'text',     'group' => 'general'],
                        'required'      => ['label' => 'Required',      'type' => 'switch',   'group' => 'general'],
                        'inline'        => ['label' => 'Inline Radio Items', 'type' => 'switch', 'group' => 'general'],
                        'default_value' => ['label' => 'Default Value', 'type' => 'text',     'group' => 'general'],
                        'options'       => ['label' => 'Field Choices', 'type' => 'key_pair', 'group' => 'general'],
                    ],
                    self::advancedEditorElements()
                );
                $fieldOptions = [
                    'label'                    => $label,
                    'placeholder'              => '',
                    'required'                 => $required,
                    'inline'                   => 'no',
                    'conditional_logic_option' => self::conditionalLogicDefault(),
                    'options'                  => $options,
                ];
                break;

            default: // checkbox
                $editorTitle    = 'Checkbox Field';
                $editorElements = array_merge(
                    [
                        'label'           => ['label' => 'Field Label',         'type' => 'text',         'group' => 'general'],
                        'required'        => ['label' => 'Required',             'type' => 'switch',       'group' => 'general'],
                        'inline'          => ['label' => 'Inline Checkbox Items','type' => 'switch',       'group' => 'general'],
                        'default_value'   => ['label' => 'Default Value',        'type' => 'text',         'group' => 'general'],
                        'options'         => ['label' => 'Field Options',        'type' => 'key_pair',     'group' => 'general'],
                        'selection_limit' => ['label' => 'Selection Limit',      'type' => 'choice_limit', 'group' => 'advanced',
                                             'info' => 'Set 0 for unlimited selection'],
                    ],
                    self::advancedEditorElements()
                );
                $fieldOptions = [
                    'label'                    => $label,
                    'required'                 => $required,
                    'default_value'            => '',
                    'inline'                   => 'no',
                    'selection_limit'          => 0,
                    'conditional_logic_option' => self::conditionalLogicDefault(),
                    'options'                  => $options,
                ];
                break;
        }

        return [
            'type'                => $type,
            'editor_title'        => $editorTitle,
            'group'               => 'input',
            'postion_group'       => 'general',
            'is_pro'              => 'no',
            'quick_checkout_form' => true,
            'editor_elements'     => $editorElements,
            'field_options'       => $fieldOptions,
            'id'                  => $id,
        ];
    }

    /**
     * Build a Paymattic 'hidden_input' element.
     * Matches HiddenInputComponent::component() field_options exactly.
     */
    private static function buildHiddenElement(string $id, string $label): array
    {
        return [
            'type'          => 'hidden_input',
            'editor_title'  => 'Hidden Input',
            'group'         => 'input',
            'postion_group' => 'general',
            'is_pro'        => 'no',
            'editor_elements' => [
                'label'         => ['label' => 'Admin Field Label', 'type' => 'text', 'group' => 'general'],
                'default_value' => ['label' => 'Input Value',       'type' => 'text', 'group' => 'general'],
                'admin_label'   => ['label' => 'Admin Label',       'type' => 'text', 'group' => 'advanced'],
                'element_class' => ['label' => 'Input Element CSS Class', 'type' => 'text', 'group' => 'advanced'],
            ],
            'field_options' => [
                'label'         => $label,
                'required'      => 'no',
                'default_value' => '',
            ],
            'id' => $id,
        ];
    }
}
