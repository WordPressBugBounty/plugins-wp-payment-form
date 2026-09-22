<?php

namespace WPPayForm\App\Modules\PaymentMethods\Stripe;

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\Subscription;
use WPPayForm\App\Services\AccessControl;

if (!defined('ABSPATH')) {
    exit;
}

class CancelSubscription
{
    public static function Cancel($formId, $subscription, $submission)
    {
        if (!$subscription) {
            return new \WP_Error('not_found', __('Sorry, Subscription is not available/already cancelled', 'wp-payment-form'));
        }

        // Resolve trusted IDs from server-loaded $submission — never from caller-provided $subscription.
        $trustedSubmissionId = absint(
            is_object($submission) ? ($submission->id ?? 0) : ($submission['id'] ?? 0)
        );
        $trustedFormId = absint($formId);

        // Load the subscription server-side and verify it belongs to this submission and form.
        $subscriptionModel = new Subscription();
        $clientSubscriptionId = absint(Arr::get($subscription, 'id', 0));
        $dbSubscription = $subscriptionModel->getSubscription($clientSubscriptionId);

        if (
            !$dbSubscription
            || absint($dbSubscription->submission_id) !== $trustedSubmissionId
            || absint($dbSubscription->form_id) !== $trustedFormId
        ) {
            return new \WP_Error('not_found', __('Sorry, Subscription is not available/already cancelled', 'wp-payment-form'));
        }

        $validStatuses = [
            'active',
            'trialing',
            'failing'
        ];

        // Use server-loaded values for all security-critical fields.
        $subscriptionId = $dbSubscription->vendor_subscriptipn_id;
        $subscriptionStatus = $dbSubscription->status;

        if (empty($subscriptionId)) {
            return new \WP_Error('invalid_subscription', __('Subscription cannot be cancelled: missing gateway reference.', 'wp-payment-form'));
        }

        if (!in_array($subscriptionStatus, $validStatuses)) {
            return new \WP_Error('wrong_status', __('Sorry, You can not cancel this subscription', 'wp-payment-form'));
        }

        $submissionUserId    = \is_object($submission)
            ? absint($submission->user_id ?? 0)
            : absint($submission['user_id'] ?? 0);
        $submissionEmail     = \is_object($submission)
            ? sanitize_email($submission->customer_email ?? '')
            : sanitize_email($submission['customer_email'] ?? '');
        $currentUserId       = \get_current_user_id();
        $currentUserEmail    = sanitize_email(\wp_get_current_user()->user_email);
        // Accept email-matched guest submissions (user_id = 0) as owned, mirroring the
        // ownershipFilter in Customers::customerForDashboard().
        $isOwner = ($submissionUserId > 0 && $submissionUserId === $currentUserId)
            || ($submissionUserId === 0 && $currentUserId > 0 && $submissionEmail && $submissionEmail === $currentUserEmail);

        $stripe = new Stripe();
        ApiRequest::set_secret_key($stripe->getSecretKey($formId));
        $response = [];
        if (AccessControl::hasGrandAccess() || $isOwner) {
            $response = ApiRequest::request([], 'subscriptions/' . $subscriptionId, 'DELETE');
        } else {
            return new \WP_Error('forbidden', __('Sorry, You do not have permission to cancel this subscription.', 'wp-payment-form'));
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $subscriptionModel->updateSubscription($dbSubscription->id, ['status' => 'cancelled']);

        $vendor_data = wppayform_safeUnserialize($dbSubscription->vendor_response);

        do_action('wppayform/subscription_payment_canceled', $submission, $dbSubscription, $formId, $vendor_data);
        do_action('wppayform/subscription_payment_canceled_stripe', $submission, $dbSubscription, $formId, $vendor_data);

        return 'Subscription has been cancelled successfully!';
    }
}
