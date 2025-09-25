<?php

namespace WPPayForm\app\Services;

use WPPayForm\Framework\Support\Arr;
use WPPayForm\Framework\Support\Str;
use WPPayForm\App\Models\Form;

class FluentcrmListConditional
{
    private static $cache = [];

    public static function checkConditions($feed, $parsedValue, $formData, $formId)
    {
        if (empty($parsedValue['list_conditional'])) {
            return [
                'isValid' => true,
                'listId' => $parsedValue['list_id'] ?? ''
            ];
        }

        $listId = self::getListId($parsedValue, $formData, $formId);
        return [
            'isValid' => !empty($listId),
            'listId' => $listId
        ];
    }

    public static function getListId($parsedValue, $formData, $formId)
    {
        $conditions = Arr::get($parsedValue, 'list_conditional', []);
        foreach ($conditions['conditions'] as $condition) {
            if (self::evaluateListCondition($condition, $formData, $formId)) {
                return $condition['list'];
            }
        }

        $enableDefault = Arr::get($conditions, 'enable_default', false);
        if ($enableDefault) {
            return Arr::get($conditions, 'default_list', '');
        }

        if (empty($conditions['conditions'])) {
            return Arr::get($conditions, 'default_list', '');
        }

        return '';
    }

    public static function evaluateListCondition($conditional, $inputs, $formId)
    {
        if ($conditional['field']) {
            $elementId = rtrim(str_replace(['[', ']', '*'], ['.'], $conditional['field']), '.');
            if (!Arr::has($inputs, $elementId)) {
                return false;
            }

            $inputValue = self::getValue($conditional, $inputs, $elementId, $formId);

            switch ($conditional['operator']) {
                case 'equal':
                    if(is_array($inputValue)) {
                        return array_key_exists($conditional['value'], $inputValue);
                    }
                    return $inputValue == $conditional['value'];
                    break;
                case 'not_equal':
                    if(is_array($inputValue)) {
                        return !array_key_exists($conditional['value'], $inputValue);
                    }
                    return $inputValue != $conditional['value'];
                    break;
                case 'greater_than':
                    return $inputValue > $conditional['value'];
                    break;
                case 'less_than':
                    return $inputValue < $conditional['value'];
                    break;
                case 'greater_or_equal':
                    return $inputValue >= $conditional['value'];
                    break;
                case 'less_or_equal':
                    return $inputValue <= $conditional['value'];
                    break;
                case 'starts_with':
                    return Str::startsWith($inputValue, $conditional['value']);
                    break;
                case 'ends_with':
                    return Str::endsWith($inputValue, $conditional['value']);
                    break;
                case 'contains':
                    return Str::contains($inputValue, $conditional['value']);
                    break;
                case 'not_contains':
                    return !Str::contains($inputValue, $conditional['value']);
                    break;
                default:
                    return false;
            }
        }

        return false;
    }

    private static function getValue($condition, $inputs, $elementId, $formId)
    {
        $elements = self::$cache[$formId] ??= Form::getFormattedElements($formId);
        $elementType = $condition['field_type'] ?? '';

        if ($elementType === 'address_subfield') {
            return Arr::get($inputs, $elementId, '');
        }

        if ($elementType === 'tabular_products' && isset($elements['payment'][$elementId])) {
            return self::getTabularProductsValue($elementId, $inputs, $elements['payment'][$elementId]);
        }

        if (isset($elements['input'][$elementId])) {
            $value = Arr::get($inputs, $elementId, '');
            return is_array($value) ? implode(' ', $value) : $value;
        }

        return intval(Arr::get($inputs, $elementId, ''));
    }

    public static function getTabularProductsValue($elementId, $inputs, $element) {  
        $productLayout = Arr::get($element, 'options.layout', '');  
        $totalPrice = 0;  
        if($productLayout === 'table') {  
            foreach ($inputs['tabular_products'] as $index => $productName) {  
                $priceKey = "tabular_products_price_{$index}";  
                $qtyKey = "tabular_products_qty_{$index}";  
                
                $price = Arr::get($inputs, $priceKey, 0);  
                $quantity = Arr::get($inputs, $qtyKey, 0);  
                
                $subtotal = floatval($price) * intval($quantity);  
                $totalPrice += $subtotal;  
            }  
            return $totalPrice;
        }
        if($productLayout === 'grid' || $productLayout === 'row') {
            $productPrice = Arr::get($inputs, 'tabular_products', 0);
            $key = 0;
            foreach ($inputs as $index => $productName) {
                $priceKey = "tabular_products_price_{$key}";
                $qtyKey = "tabular_products_qty_{$key}";

                $price = Arr::get($inputs, $priceKey, 0);
                $quantity = Arr::get($inputs, $qtyKey, 0);

                $subtotal = floatval($price) * intval($quantity);
                $totalPrice += $subtotal;
                if ($productPrice == $totalPrice) {
                    return $key;
                }
                $key++;
            }
        }
        return $totalPrice;
    }
}
