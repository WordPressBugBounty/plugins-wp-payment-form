<?php

namespace WPPayForm\App\Modules\PaymentMethods\Stripe;

use WPPayForm\App\Services\GeneralSettings;
use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle Payment Charge Via Stripe
 * @since 1.2.0
 */
class Plan
{
    public static function retirive($planId, $formId = false)
    {
        try {
            $stripe = new Stripe();
            ApiRequest::set_secret_key($stripe->getSecretKey($formId));
            $response = ApiRequest::request([], 'plans/' . $planId, 'GET');
            if (!empty($response->error)) {
                $errotType = 'general';
                if (!empty($response->error->type)) {
                    $errotType = $response->error->type;
                }
                $errorCode = '';
                if (!empty($response->error->code)) {
                    $errorCode = $response->error->code . ' : ';
                }
                return self::errorHandler($errotType, $errorCode . $response->error->message);
            }
            if (false !== $response) {
                return $response;
            }
        } catch (\Exception $e) {
            // Something else happened, completely unrelated to Stripe
            return self::errorHandler('non_stripe', esc_html__('General Error', 'wp-payment-form') . ': ' . $e->getMessage());
        }
        return false;
    }

    public static function create($plan, $formId = false)
    {
        $stripe = new Stripe();
        ApiRequest::set_secret_key($stripe->getSecretKey($formId));
        $response = ApiRequest::request($plan, 'plans', 'POST');
        if (!empty($response->error)) {
            $errotType = 'general';
            if (!empty($response->error->type)) {
                $errotType = $response->error->type;
            }
            $errorCode = '';
            if (!empty($response->error->code)) {
                $errorCode = $response->error->code . ' : ';
            }
            return self::errorHandler($errotType, $errorCode . $response->error->message);
        }
        if (false !== $response) {
            return $response;
        }
        return false;
    }

    public static function getOrCreatePlan($subscription, $submission)
    {
        if (GeneralSettings::isZeroDecimal($submission->currency)) {
            $subscription->recurring_amount = intval($subscription->recurring_amount / 100);
        }

        // Generate The subscription ID Here
        $subscriptionId = self::getGeneratedSubscriptionId($subscription, $submission->currency);
        $subscriptionId = apply_filters('wppayform/subscription_plan_id_form_' . $submission->form_id, $subscriptionId);

        $stripePlan = self::retirive($subscriptionId, $submission->form_id);

        if ($stripePlan && !is_wp_error($stripePlan)) {
            return $stripePlan;
        }

        $intervalCount = 1;
        // We don't have this plan yet. Now we have to create the plan from subscription
        $billingInterval = $subscription->billing_interval;
        if ($billingInterval == 'daily') {
            $billingInterval = 'day';
        } else if ($billingInterval == 'fortnight') {
            $billingInterval = 'week';
            $intervalCount = 2;
        } else if ($billingInterval == 'quarter') {
            $billingInterval = 'month';
            $intervalCount = 3;
        } else if ($billingInterval == 'half_year') {
            $billingInterval = 'month';
            $intervalCount = 6;
        } 

        $intervalCountFromOriginalPlan = Arr::get($subscription->original_plan, 'interval_count', 1);

        // check interval count limit in days
        if ($intervalCountFromOriginalPlan > 1 ) {
           if ($billingInterval == 'week' && $intervalCountFromOriginalPlan > 156) {
                return new \WP_Error('interval_count', __('Weekly interval count can not be more than 156', 'wp-payment-form')); 
           } else if ($billingInterval == 'month' && $intervalCountFromOriginalPlan > 36) {
               return new \WP_Error('interval_count', __('Monthly interval count can not be more than 36', 'wp-payment-form'));
           } else if ($billingInterval == 'year' && $intervalCountFromOriginalPlan > 3) {
               return new \WP_Error('interval_count', __('Yearly interval count can not be more than 3', 'wp-payment-form'));
           } else if ($billingInterval == 'fortnight' && $intervalCountFromOriginalPlan > 78) {
              return new \WP_Error('interval_count', __('Fortnightly interval count can not be more than 78', 'wp-payment-form'));
           } else if ($billingInterval == 'quarter' && $intervalCountFromOriginalPlan > 12) {
                return new \WP_Error('interval_count', __('Quarterly interval count can not be more than 12', 'wp-payment-form'));
           } else if ($billingInterval == 'half_year' && $intervalCountFromOriginalPlan > 6) {
                return new \WP_Error('interval_count', __('Half yearly interval count can not be more than 6', 'wp-payment-form'));
           }
        }

        if ($intervalCountFromOriginalPlan > 1) {
            $intervalCount = $intervalCount * $intervalCountFromOriginalPlan;
        }

        $plan = array(
            'id' => $subscriptionId,
            'currency' => $submission->currency,
            'interval' => $billingInterval,
            'interval_count' => $intervalCount,
            'amount' => $subscription->recurring_amount,
            'trial_period_days' => $subscription->trial_days,
            'product' => array(
                'id' => $subscriptionId,
                'name' => $subscription->item_name . ' (' . $subscription->plan_name . ')',
                'type' => 'service'
            ),
            'metadata' => array(
                'form_id' => $subscription->form_id,
                'element_id' => $subscription->element_id,
                'wp_plugin' => 'wppayform'
            )
        );

        return self::create($plan, $submission->form_id);
    }

    private static function validate($args)
    {
        $errors = array();
        // check if the currency is right or not
        if (isset($args['currency'])) {
            $supportedCurrncies = GeneralSettings::getCurrencies();
            if (!isset($supportedCurrncies[$args['currency']])) {
                $errors['currency'] = __('Invalid currency', 'wp-payment-form');
            }
        } else {
            $errors['currency'] = __('Currency is required', 'wp-payment-form');
        }
        // Validate the token
        if (empty($args['source']) && empty($args['customer'])) {
            $errors['source'] = __('Stripe Token is required', 'wp-payment-form');
        }

        // Validate Amount
        if (empty($args['amount'])) {
            $errors['amount'] = __('Payment Amount can not be 0', 'wp-payment-form');
        }

        return $errors;
    }

    private static function errorHandler($code, $message, $data = array())
    {
        return new \WP_Error($code, $message, $data);
    }

    public static function getGeneratedSubscriptionId($subscription, $currency = 'USD')
    {
        $intervalCount = Arr::get($subscription->original_plan, 'interval_count', 1);

        $subscriptionId = 'wpf_' . $subscription->form_id . '_' . $subscription->element_id . '_' . $subscription->recurring_amount . '_' . $intervalCount . '_' . $subscription->billing_interval . '_' . $subscription->trial_days . '_' . $currency;;
        return apply_filters('wppayform/stripe_plan_name_generated', $subscriptionId, $subscription, $currency);
    }

}