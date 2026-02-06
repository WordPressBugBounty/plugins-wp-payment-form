<?php

/**
 ***** DO NOT CALL ANY FUNCTIONS DIRECTLY FROM THIS FILE ******
 *
 * This file will be loaded even before the framework is loaded
 * so the $app is not available here, only declare functions here.
 */

$wppayform_globals_dev_file = __DIR__ . '/globals_dev.php';

is_readable($wppayform_globals_dev_file) && include $wppayform_globals_dev_file;

function wpPayFormFormatMoney($amountInCents, $formId = false)
{
    if (!$formId) {
        $wppayform_currency_settings = \WPPayForm\App\Services\GeneralSettings::getGlobalCurrencySettings();
    } else {
        $wppayform_currency_settings = \WPPayForm\App\Models\Form::getCurrencySettings($formId);
    }
    if (empty($wppayform_currency_settings['currency_sign'])) {
        $wppayform_currency_settings['currency_sign'] = \WPPayForm\App\Services\GeneralSettings::getCurrencySymbol($wppayform_currency_settings['currency']);
    }
    return wpPayFormFormattedMoney($amountInCents, $wppayform_currency_settings);
}

function wpPayFormFormattedMoney($amountInCents, $currencySettings)
{
    //get exact currency symbol from currency code ex: get $ from &#36;
    $wppayform_arr = new WPPayForm\Framework\Support\Arr();
    $wppayform_symbol = $wppayform_arr::get($currencySettings, 'currency_sign');
    $wppayform_position = $wppayform_arr::get($currencySettings, 'currency_sign_position');
    $wppayform_decimal_separator = '.';
    $wppayform_thousand_separator = ',';
    if ($wppayform_arr::get($currencySettings, 'currency_separator') != 'dot_comma') {
        $wppayform_decimal_separator = ',';
        $wppayform_thousand_separator = '.';
    }
    $wppayform_decimal_points = 2;
    if ($amountInCents % 100 == 0 && $wppayform_arr::get($currencySettings, 'decimal_points') == 0) {
        $wppayform_decimal_points = 0;
    }

    $wppayform_amount = number_format($amountInCents / 100, $wppayform_decimal_points, $wppayform_decimal_separator, $wppayform_thousand_separator);

    if ('left' === $wppayform_position) {
        return $wppayform_symbol . $wppayform_amount;
    } elseif ('left_space' === $wppayform_position) {
        return $wppayform_symbol . ' ' . $wppayform_amount;
    } elseif ('right' === $wppayform_position) {
        return $wppayform_amount . $wppayform_symbol;
    } elseif ('right_space' === $wppayform_position) {
        return $wppayform_amount . ' ' . $wppayform_symbol;
    }
    return $wppayform_amount;
}

function wpPayFormConverToCents($amount)
{
    if (!$amount) {
        return 0;
    }
    $amount = floatval($amount);
    return round($amount * 100, 0);
}

function wppayformUpgradeUrl()
{
    $wppayform_url = 'https://paymattic.com/#pricing';

    $wppayform_url_args = apply_filters_deprecated(
        'paymattic_pro_buy_link',
        [
                [
                    'utm_source'   => 'plugin',
                    'utm_medium'   => 'menu',
                    'utm_campaign' => 'upgrade'
                ]
            ],
        '1.0.0',
        'wppayform/pro_buy_link',
        'Use wppayform/pro_buy_link instead of paymattic_pro_buy_link.'
    );
    $wppayform_url_args = apply_filters('wppayform/pro_buy_link', $wppayform_url_args);

    return add_query_arg($wppayform_url_args, $wppayform_url);
}

function wppayformPublicPath($assets_path)
{
    return WPPAYFORM_URL . '/assets/' . $assets_path;
}

function wppayform_sanitize_html($wppayform_html)
{
    if(!$wppayform_html) {
        return $wppayform_html;
    }

    $wppayform_tags = wp_kses_allowed_html('post');
    $wppayform_tags['style'] = [
        'types' => [],
    ];
    // iframe
    $wppayform_tags['iframe'] = [
        'width'           => [],
        'height'          => [],
        'src'             => [],
        'srcdoc'          => [],
        'title'           => [],
        'frameborder'     => [],
        'allow'           => [],
        'class'           => [],
        'id'              => [],
        'allowfullscreen' => [],
        'style'           => [],
    ];
    //button
    $wppayform_tags['button']['onclick'] = [];

    //svg
    if (empty($wppayform_tags['svg'])) {
        $wppayform_svg_args = array(
            'svg'   => array(
                'class'           => true,
                'aria-hidden'     => true,
                'aria-labelledby' => true,
                'role'            => true,
                'xmlns'           => true,
                'width'           => true,
                'height'          => true,
                'viewbox'         => true, // <= Must be lower case!
            ),
            'g'     => array('fill' => true),
            'title' => array('title' => true),
            'path'  => array(
                'd'    => true,
                'fill' => true,
            )
        );
        $wppayform_tags = array_merge($wppayform_tags, $wppayform_svg_args);
    }

    $wppayform_tags = apply_filters('wppayform_allowed_html_tags', $wppayform_tags);

    return wp_kses($wppayform_html, $wppayform_tags);
}

/*
 * Utility function to echo only internal hard coded strings or already escaped strings
 */
function wpPayFormPrintInternal($string)
{
    echo wp_kses_post($string); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
}

if(!function_exists('wppayform_safeUnserialize')) {
    function wppayform_safeUnserialize($wppayform_data) {
        if (is_serialized($wppayform_data)) { // Don't attempt to unserialize data that wasn't serialized going in.
            return @unserialize(trim($wppayform_data), ['allowed_classes' => false]);
        }
        return $wppayform_data;
    }
}


if (defined('WPPAYFORMPRO') && version_compare(WPPAYFORMPRO_VERSION, '4.6.11', '<')) {
    // Will be deprecated in the future
    if (!function_exists('safeUnserialize')) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
        function safeUnserialize($data) {
            // Only attempt to unserialize valid serialized strings
            if (is_serialized($data)) {
                return @unserialize(trim($data), ['allowed_classes' => false]);
            }
            return $data;
        }
    }
}

