<?php

namespace WPPayForm\App\Services;

if (!defined('ABSPATH')) {
    exit;
}


class ProRoutes 
{

    public static function getRoutes()
    {
        $default = array(
            [
                'path' => 'stripe',
                'name' => 'stripe',
                'meta'=> [
                    'title' => 'Stripe' 
                ]
            ],
            [
                'path' => 'offline',
                'name' => 'offline',
                'meta'=> [
                    'title' => 'Offline' 
                ]
            ],
            [
                'path' => 'paypal',
                'name' => 'paypal',
                'meta'=> [
                    'title' => 'PayPal' 
                ]
            ]
        );
        
        $premium = array(
            [
                'path' => 'authorizedotnet',
                'name' => 'authorizedotnet',
                'meta'=> [
                    'title' => 'Authorize.Net' 
                ]
            ],
            [
                'path' => 'moneris',
                'name' => 'moneris',
                'meta'=> [
                    'title' => 'Moneris' 
                ]
            ],
            [
                'path' => 'vivawallet',
                'name' => 'vivawallet',
                'meta'=> [
                    'title' => 'Viva Wallet' 
                ]
            ],
            [
                'path' => 'square',
                'name' => 'square',
                'meta'=> [
                    'title' => 'square' 
                ]
            ],
            [
                'path' => 'mollie',
                'name' => 'mollie',
                'meta'=> [
                    'title' => 'Mollie' 
                ]
            ],
            [
                'path' => 'razorpay',
                'name' => 'razorpay',
                'meta'=> [
                    'title' => 'Razorpay' 
                ]
            ],
            [
                'path' => 'paystack',
                'name' => 'paystack',
                'meta'=> [
                    'title' => 'Paystack' 
                ]
            ],
            [
                'path' => 'payrexx',
                'name' => 'payrexx',
            ],
            [
                'path' => 'billplz',
                'name' => 'billplz',
            ],
            [
                'path' => 'sslcommerz',
                'name' => 'sslcommerz',
                'meta'=> [
                    'title' => 'SSLCommerz' 
                ]
            ],
            [
                'path' => 'xendit',
                'name' => 'xendit',
                'meta'=> [
                    'title' => 'Xendit',
                ]
            ],
            [
                'path' => 'flutterwave',
                'name' => 'flutterwave',
                'meta'=> [
                    'title' => 'Flutterwave',
                ]
            ],
            [
                'path' => 'offline',
                'name' => 'offline',
                'meta'=> [
                    'title' => 'Offline' 
                ]
            ],
        );

        return defined('WPPAYFORMHASPRO') ? $default : array_merge($default,$premium);
    }

    public static function getMethods()
    {
        $default =  array(
            'stripe' => array(
                'title' => 'Stripe',
                'route_name' => 'stripe',
                'svg' => WPPAYFORM_URL .'assets/images/gateways/stripe.svg',
            ),

            'offline' => array(
                'title' => 'Offline',
                'route_name' => 'offline',
                'svg' => WPPAYFORM_URL .'assets/images/gateways/offline.svg',
            ),

            'paypal' => array(
                'title' => 'PayPal',
                'route_name' => 'paypal',
                'svg' => WPPAYFORM_URL .'assets/images/gateways/paypal.svg',
                'route_query' => [],
            ),
        );


        $premium = array(
            'authorizedotnet' => array(
                'title' => 'Authorize.Net',
                'route_name' => 'authorizedotnet',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/authorizedotnet.svg',
            ),
            'square' => array(
                'title' => 'Square',
                'route_name' => 'square',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/square.svg',
            ),
            'moneris' => array(
                'title' => 'Moneris',
                'route_name' => 'moneris',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/moneris.svg',
            ),
           'mollie' => array(
                'title' => 'Mollie',
                'route_name' => 'mollie',
                'svg' => WPPAYFORM_URL .'assets/images/gateways/mollie.svg',
                'route_query' => [],
            ),
            'vivawallet' => array(
                'title' => 'Viva Wallet',
                'route_name' => 'vivawallet',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/vivawallet.svg',
            ),
            'razorpay' => array(
                'title' => 'Razorpay',
                'route_name' => 'razorpay',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/razorpay.svg',
            ),
            'paystack' => array(
                'title' => 'Paystack',
                'route_name' => 'paystack',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/paystack.svg',
            ),
            'payrexx' => array(
                'title' => 'Payrexx',
                'route_name' => 'payrexx',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/payrexx.svg',
            ),
            'billplz' => array(
                'title' => 'Billplz',
                'route_name' => 'billplz',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/billplz.svg',
            ),
            'sslcommerz' => array(
                'title' => 'SSLCommerz',
                'route_name' => 'sslcommerz',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/sslcommerz.svg',
            ),
            'flutterwave' => array(
                'title' => 'Flutterwave',
                'route_name' => 'flutterwave',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/flutterwave.svg',
            ),
            'xendit' => array(
                'title' => 'Xendit',
                'route_name' => 'xendit',
                'route_query' => [],
                'svg' => WPPAYFORM_URL .'assets/images/gateways/xendit.svg',
            ),
        );
       
        return defined('WPPAYFORMHASPRO') ? $default : array_merge($default, $premium);
    }

    public static function getPaymentAddons()
    {
        $addons = array(
            'moneris' => array(
                'name' => 'moneris',
                'slug' => 'moneris-payment-for-paymattic',
                'svg' => WPPAYFORM_URL .'assets/images/gateways/moneris.svg',
                'src' => 'github',
                'url' => 'https://api.github.com/repos/WPManageNinja/moneris-payment-for-paymattic/zipball/1.0.0'
            ),
            'vivawallet' => array(
                'name' => 'vivawallet',
                'slug' => 'vivawallet-payment-for-paymattic',
                'svg' => WPPAYFORM_URL .'assets/images/gateways/vivawallet.svg',
                'src' => 'github',
                'url' => 'https://api.github.com/repos/WPManageNinja/vivawallet-payment-for-paymattic/zipball/1.0.0'
            ),
            'xendit' => array(
                'name' => 'xendit',
                'slug' => 'xendit-payment-for-paymattic',
                'svg' => WPPAYFORM_URL .'assets/images/gateways/xendit.svg',
                'src' => 'github',
                'url' => 'https://api.github.com/repos/WPManageNinja/xendit-payment-for-paymattic/zipball/1.0.0'
            ),
            'flutterwave' => array(
                'name' => 'flutterwave',
                'slug' => 'flutterwave-payment-for-paymattic',
                'svg' => WPPAYFORM_URL .'assets/images/gateways/flutterwave.svg',
                'src' => 'github',
                'url' => 'https://api.github.com/repos/WPManageNinja/flutterwave-payment-for-paymattic/zipball/1.0.0'
            ),
        );

        return $addons;
    }

}