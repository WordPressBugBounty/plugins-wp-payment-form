<?php

namespace WPPayForm\App\Modules\FormComponents;

class InitComponents
{
    public function __init()
    {
        // Load and Register Form Components
        new \WPPayForm\App\Modules\FormComponents\CustomerNameComponent();
        new \WPPayForm\App\Modules\FormComponents\CustomerEmailComponent();
        new \WPPayForm\App\Modules\FormComponents\TextComponent();
        new \WPPayForm\App\Modules\FormComponents\NumberComponent();
        new \WPPayForm\App\Modules\FormComponents\SelectComponent();
        new \WPPayForm\App\Modules\FormComponents\RadioComponent();
        new \WPPayForm\App\Modules\FormComponents\CheckBoxComponent();
        new \WPPayForm\App\Modules\FormComponents\AddressFieldsComponent();
        new \WPPayForm\App\Modules\FormComponents\TextAreaComponent();
        new \WPPayForm\App\Modules\FormComponents\HtmlComponent();
        new \WPPayForm\App\Modules\FormComponents\PaymentItemComponent();
        new \WPPayForm\App\Modules\FormComponents\ItemQuantityComponent();
        new \WPPayForm\App\Modules\FormComponents\DateComponent();
        new \WPPayForm\App\Modules\FormComponents\CustomAmountComponent();
        new \WPPayForm\App\Modules\FormComponents\ChoosePaymentMethodComponent();
        new \WPPayForm\App\Modules\FormComponents\HiddenInputComponent();
        new \WPPayForm\App\Modules\FormComponents\ConsentComponent();
        new \WPPayForm\App\Modules\FormComponents\PasswordComponent();
        new \WPPayForm\App\Modules\FormComponents\PaymentSummaryComponent();
        new \WPPayForm\App\Modules\FormComponents\CustomPhoneNumber();
        new \WPPayForm\App\Modules\FormComponents\StepFormComponent();
        new \WPPayForm\App\Modules\FormComponents\Container\TwoColumnContainer();
        new \WPPayForm\App\Modules\FormComponents\Container\ThreeColumnContainer();

         //! Donation component moved to free version(4.5.3+)
         if (!defined('WPPAYFORMPRO_VERSION')) {
            new \WPPayForm\App\Modules\FormComponents\DonationComponent();
        } else {
            // If the pro version is installed, and the version is smaller then 4.5.2 then donation component is already exist in pro version
            $currentVersion = WPPAYFORMPRO_VERSION;
            if (version_compare($currentVersion, '4.5.2', '>')) {
                new \WPPayForm\App\Modules\FormComponents\DonationComponent();
            }
        }

        // If only free version is installed, then load demo gateways
        if (!defined('WPPAYFORMPRO_VERSION')) {
            //premium modules
            new \WPPayForm\App\Modules\FormComponents\DemoCouponComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoFileUploadComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoMaskInputComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoRecurringPaymentComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoTabularProductsComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoTaxItemComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoCurrencySwitcherComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoDynamicPaymentItemComponent();

            // Pro payment method modules
            new \WPPayForm\App\Modules\FormComponents\DemoFlutterWaveComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoMollieComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoChoosePaymentMethod();
            new \WPPayForm\App\Modules\FormComponents\DemoPaypalComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoXenditComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoRazorpayComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoSquareComponent();
            // new \WPPayForm\App\Modules\FormComponents\DemoOfflineComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoPaystackComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoPayrexxComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoSSLCommerzComponent();
            new \WPPayForm\App\Modules\FormComponents\DemoBillplzComponent();
        }
    }
}
