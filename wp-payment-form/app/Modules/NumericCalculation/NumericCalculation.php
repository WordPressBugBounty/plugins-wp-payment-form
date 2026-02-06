<?php
namespace WPPayForm\App\Modules\NumericCalculation;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use WPPayForm\Framework\Support\Arr;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;

class NumericCalculation
{
    private static $expressionLanguage;
    public function __construct()
    {
        self::$expressionLanguage = new ExpressionLanguage();
        add_filter('wppayform/dynamic_payment_calculation', array($this, 'processNumericCalculations'), 10, 4);
    }

    public static function processNumericCalculations($expressions, $calculation, $formattedElements, $formData)
    {
        $processedCalculations = [];
        foreach ($formattedElements as $group => $elements) {
            foreach ($elements as $elementId => $element) {

                // Calculate Payment Item Pricing  
                if ($element['type'] === 'payment_item') {
                    $formData[$elementId] = self::calculatePaymentItem($element, $formData[$elementId]);
                }

                // Calculate Tabular Product Pricing  
                if ($element['type'] === 'tabular_products') {
                    $formData[$elementId] = self::calculateTabularProducts($element, $formData, $formData[$elementId]);
                }

                // Calculate Payment Item Pricing
                if ($element['type'] === 'recurring_payment_item') {
                    $formData[$elementId] = self::calculateRecurringPaymnet($element, $formData[$elementId], $elementId, $formData);
                }
                // Calculate Donation Item Pricing  
                if ($element['type'] === 'donation_item') {
                    $formData[$elementId] = self::calculateDonationItem($element, $formData[$elementId], $elementId, $formData);
                }

                // Process Numeric Calculations  
                if (isset($element['options'])) {
                    $isNumericCalculation = isset($element['options']['numeric_calculation'])
                        && $element['options']['numeric_calculation'] === 'yes';
                    $hasCalculationExpression = Arr::get($element, 'options.calculation_expression', '');
                    $numericServersideValidation = Arr::get($element, 'options.numeric_serverside_validation', '');
                    $isAvailableFormData = isset($formData[$elementId]) && !empty($formData[$elementId]);
                    if ($isNumericCalculation && !empty($hasCalculationExpression) && $isAvailableFormData) {
                        try {
                            $processedExpression = self::replaceInputPlaceholders($hasCalculationExpression, $formData);
                            $result = (float)self::evaluateExpression($processedExpression);
                            $formValue = (float)($formData[$elementId] ?? 0);

                            // Round first, then format
                            $resultFormatted = number_format(round($result, 2), 2, '.', '');
                            $formDataValue = number_format(round($formValue, 2), 2, '.', '');

                            $processedCalculations[$elementId] = $resultFormatted;
                            
                            if ($resultFormatted !== $formDataValue && $numericServersideValidation === 'yes') {
                                self::errorHandler($result, 'Please verify numeric calculation, the value is not valid.', [
                                    'calculated_value' => $resultFormatted, 
                                    'form_data_value' => $formDataValue
                                ]);
                            }
                        } catch (\Exception $e) {
                            $processedCalculations[$elementId] = $e->getMessage();
                        }
                    }
                }
            }
        }
        return $processedCalculations;
    }

    private static function errorHandler($code, $message, $data = array())
    {
        $error = new \WP_Error($code, $message, $data);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        wp_die($error);
    }
    private static function replaceInputPlaceholders(string $expression, array $form_data): string
    {
        if (empty($expression)) {
            return null;
        }
        $expression = preg_replace_callback('/\{input\.(.*?)\}/', function ($match) use ($form_data) {
            $replacement = $form_data[$match[1]] ?? 0;
            $replacement = is_numeric($replacement) ? $replacement : 0;
            return $replacement;
        }, $expression);
        // calculate percentage 
        $expression = self::percentageCalculation($expression);
        return $expression;
    }

    private static function percentageCalculation(string $expression): string
    { 
        if (strpos($expression, '%') === false) {
            return $expression;
        }
        $percentageRegex = '/(\(.*?\)|\d+(\.\d+)?)\s*%\s*(\(.*?\)|\d+(\.\d+)?)/';
        $result = preg_replace_callback(  
            $percentageRegex,  
            function ($matches) {  
                // $matches[1] = base, $matches[3] = total  
                return '(' . $matches[1] . ' / 100) * ' . $matches[3];  
            },  
            $expression  
        );
        return $result;
    }

    private static function evaluateExpression(string $expression)
    {
        $expressionLanguage = new ExpressionLanguage();
        try {
            self::registerCustomFunction($expressionLanguage);
            $result = $expressionLanguage->evaluate($expression);
            return is_numeric($result) ? $result : 0;
        } catch (\Exception $e) {
            error_log("Expression Evaluation Error: $expression - " . $e->getMessage());
            return 0;
        }
    }
    private static function registerCustomFunction(ExpressionLanguage $expressionLanguage)
    {
        $functions = ['round', 'ceil', 'floor', 'max', 'min', 'abs'];
        foreach ($functions as $function) {
            $expressionLanguage->addFunction(ExpressionFunction::fromPhp($function));
        }
    }
    private static function calculatePaymentItem($element, $selectedItem)
    {
        $priceDetails = Arr::get($element, 'options.pricing_details', '');
        $payType = Arr::get($priceDetails, 'one_time_type', '');
        $multiPrice = Arr::get($priceDetails, 'multiple_pricing', []);
        if ($payType === 'single') {
            $price = Arr::get($priceDetails, 'payment_amount', 0);
            return $price;
        }

        if ($payType === 'choose_single') {
            // $selectedItem = $formData[$elementId] ?? [];
            $price = Arr::get($multiPrice, $selectedItem, []);
            if (isset($selectedItem) && isset($price)) {
                $priceValue = Arr::get($price, 'value', 0);
                return $priceValue;
            }
        }

        if ($payType === 'choose_multiple') {
            $selectedItems = $selectedItem ?? [];
            $totalPrice = 0;
            foreach ($selectedItems as $itemIndex => $item) {
                if (isset($multiPrice)) {
                    $itemPrice = Arr::get($multiPrice, $itemIndex . '.value', 0);
                    $totalPrice += $itemPrice;
                }
            }
            return $totalPrice;
        }
    }

    private static function calculateTabularProducts($element, $formData, $selectedItem)
    {
        $productLayout = Arr::get($element, 'options.layout', '');
        // $priceDetails = Arr::get($element, 'options.products', '');
        $totalPrice = 0;
        if($productLayout === 'table') {  
            foreach ($formData['tabular_products'] as $index => $productName) {  
                $priceKey = "tabular_products_price_{$index}";  
                $qtyKey = "tabular_products_qty_{$index}";  
                
                $price = Arr::get($formData, $priceKey, 0);  
                $quantity = Arr::get($formData, $qtyKey, 0);  
                
                $subtotal = floatval($price) * intval($quantity);  
                $totalPrice += $subtotal;  
            }  
        }
        if($productLayout === 'grid' || $productLayout === 'row') {
            $totalPrice = $selectedItem;
        }
        return $totalPrice;
    }


    private static function calculateRecurringPaymnet($element, $selectedItem, $elementId, $formdata)
    {
        $recurringTyeps = Arr::get($element, 'options.recurring_payment_options.choice_type', '');
        $priceDetails = Arr::get($element, 'options.recurring_payment_options.pricing_options', []);
        $totalPriceValue = 0;

        if($recurringTyeps === 'simple') {
            foreach($priceDetails as $price) {
                $totalPriceValue = self::calculateSimpleRecurringPayment($price, $elementId, $formdata);
            }
        }
        if($recurringTyeps === 'choose_single') {
            $recurringPrice = Arr::get($priceDetails, $selectedItem, []);
            $totalPriceValue = self::calculateSimpleRecurringPayment($recurringPrice, $elementId, $formdata); 
        }
        return $totalPriceValue;
    }

    private static function calculateSimpleRecurringPayment($priceDetailes, $elementId, $formData) {
        $subscriptionAmount = Arr::get($priceDetailes, 'subscription_amount', 0);
        $hasSignupFee = Arr::get($priceDetailes, 'has_signup_fee', 'no') === 'yes';
        $signupFee = Arr::get($priceDetailes, 'signup_fee', 0);
        $isCustomAmount = Arr::get($priceDetailes, 'user_input', 'no') === 'yes';
        $totalPriceValue = 0;
        
        if ($isCustomAmount) {
            $subscriptionAmount = floatval(self::findMatchingFormDataValue($elementId, $formData));
        }
        $totalPriceValue += $subscriptionAmount;
        if ($hasSignupFee) {
            $totalPriceValue += $signupFee;
        }
        return $totalPriceValue;
    }
    private static function findMatchingFormDataValue($elementId, $formData) {  
        $pattern = '/^' . preg_quote($elementId, '/') . '__\d+$/';  
        
        foreach ($formData as $key => $value) {  
            if (preg_match($pattern, $key)) {  
                return $value;  
            }  
        }  
        return null;
    }  

    private static function calculateDonationItem($element, $selectedItem, $elementId, $formData) {  
        $formDataKey = $elementId . '_custom';  
        $donationAmount = Arr::get($formData, $formDataKey, 0);  
        return $donationAmount;
    }
}
?>