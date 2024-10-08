<?php

namespace WPPayForm\App\Modules\PaymentMethods\Stripe;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle Plan Subscription Via Stripe
 * @since 1.2.0
 */
class PlanSubscription
{
    public static function create($subscription, $customer, $submission)
    {
        $plan = Plan::getOrCreatePlan($subscription, $submission);
        if ($plan && is_wp_error($plan)) {
            return $plan;
        }

        $billablePlan[] = array(
            'plan' => $plan->id,
            'quantity' => $subscription->quantity,
            'metadata' => array(
                'wpf_subscription_id' => $subscription->id
            )
        );

        $metaData = array(
            'submission_id' => $submission->id,
            'wpf_subscription_id' => $subscription->id,
            'form_id' => $submission->form_id
        );

        $metaData = apply_filters('wppayform/stripe_onetime_payment_metadata', $metaData, $submission);

        $subscriptionArgs = array(
            'customer' => $customer,
            'billing' => 'charge_automatically',
            'items' => $billablePlan,
            'metadata' => $metaData,
            'expand' => [
                'latest_invoice',
            ],
            'off_session' => 'true'
        );

        if ($subscription->trial_days) {
            $dateTime = current_datetime();
            $localtime = $dateTime->getTimestamp() + $dateTime->getOffset();
            $subscriptionArgs['trial_end'] = $localtime + $subscription->trial_days * 86400;
        }

        return self::subscribe($subscriptionArgs, $submission->form_id);
    }

    public static function subscribe($subscriptionArgs, $formId = false)
    {
        $stripe = new Stripe();
        ApiRequest::set_secret_key($stripe->getSecretKey($formId));
        $response = ApiRequest::request($subscriptionArgs, 'subscriptions', 'POST');
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

    private static function errorHandler($code, $message, $data = array())
    {
        return new \WP_Error($code, $message, $data);
    }
}
