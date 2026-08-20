<?php

namespace WPPayForm\App\Modules\PaymentMethods\Stripe;

use WPPayForm\App\Models\Refund;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\SubmissionActivity;
use WPPayForm\App\Models\Subscription;
use WPPayForm\App\Models\SubscriptionTransaction;
use WPPayForm\App\Services\GeneralSettings;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Modules\PaymentMethods\Stripe\PaymentSuccessHandler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle Stripe Webhook Events (IPN)
 * Handles bank transfers and other payment method webhooks
 * @since 1.0.0
 */
class StripeListener
{
    public function init()
    {
        add_action('init', array($this, 'verifyIPN'));
        add_filter('wppayform/stripe_onetime_payment_metadata', array($this, 'pushSingleAmountMetaData'), 10, 2);
    }

    public function pushSingleAmountMetaData($metadata, $submission)
    {
        $settings = get_option('wppayform_stripe_payment_settings', array());
        if (empty($settings['send_meta_data']) || $settings['send_meta_data'] != 'yes') {
            return $metadata;
        }

        $submissionModel = new Submission();
        $entries = $submissionModel->getUnParsedSubmission($submission);
        foreach ($entries as $entry) {
            if ($entry['type'] == 'customer_name') {
                unset($metadata['customer_name']);
            }
            if ($entry['type'] == 'customer_email') {
                unset($metadata['customer_email']);
            }
            $value = $entry['value'];
            if (is_string($value) && $value) {
                $label = \substr($entry['label'], 0, 38);
                $metadata[$label] = $value;
            }
        }

        return $metadata;
    }

    /**
     * Verify Stripe webhook signature using Stripe-Signature header.
     * Signing secret is read from the wppayform_stripe_webhook_signing_secret option.
     */
    private function verifyStripeSignature($payload, $sigHeader, $secret)
    {
        if (empty($sigHeader) || empty($secret)) {
            return false;
        }

        $parts = explode(',', $sigHeader);
        $timestamp = null;
        $signatures = [];
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }

        if (!$timestamp || empty($signatures)) {
            return false;
        }

        // Reject webhooks older than 5 minutes
        if (abs(time() - intval($timestamp)) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    public function verifyIPN()
    {
        if (!isset($_GET['wpf_stripe_listener'])) {
            return;
        }

        // retrieve the request's body and parse it as JSON
        $body = @file_get_contents('php://input');

        $event = json_decode($body);
        $eventId = $event->id ?? null;

        if ($eventId) {
            status_header(200);
            try {
                // TR-STR-2: Extract form_id from event metadata to support per-form Stripe keys
                $formId = $event->data->object->metadata->form_id ?? null;
                if ($formId) {
                    $stripe = new Stripe();
                    ApiRequest::set_secret_key($stripe->getSecretKey(intval($formId)));
                }

                // S-H3: Verify Stripe-Signature header if a signing secret is configured
                $signingSecret = get_option('wppayform_stripe_webhook_signing_secret', '');
                if ($signingSecret) {
                    $sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_STRIPE_SIGNATURE'])) : '';
                    if (!$this->verifyStripeSignature($body, $sigHeader, $signingSecret)) {
                        status_header(400);
                        die('Webhook signature verification failed');
                    }
                }

                $event = $this->retrive($eventId);

                if (is_wp_error($event)) {
                    error_log('[WPF-LITE-IPN] retrive() WP_Error: ' . $event->get_error_message());
                }

                if ($event && !is_wp_error($event)) {
                    $eventType = $event->type;
                    if ($eventType == 'charge.succeeded') {
                        $this->handleChargeSucceeded($event);
                    } elseif ($eventType == 'charge.captured') {
                        $this->handleChargeCaptured($event->data->object);
                    } elseif ($eventType == 'invoice.payment_succeeded') {
                        $this->maybeHandleSubscriptionPayment($event);
                    } elseif ($eventType == 'charge.refunded') {
                        $this->handleChargeRefund($event);
                    } elseif ($eventType == 'customer.subscription.deleted') {
                        $this->handleSubscriptionCancelled($event);
                    } elseif ($eventType == 'checkout.session.completed') {
                        $this->handleCheckoutSessionCompleted($event);
                    } elseif ($eventType == 'payment_intent.succeeded') {
                        // Handle payment_intent.succeeded for bank transfers and other payment methods
                        $this->handlePaymentIntentSucceeded($event);
                    } elseif ($eventType == 'charge.failed') {
                        $this->handleChargeFailed($event);
                    } elseif ($eventType == 'invoice.payment_failed') {
                        $this->handleSubscriptionPaymentFailed($event);
                    }
                }
            } catch (\Exception $e) {
                return; // No event found for this account
            }
        } else {
            status_header(500);
            die('-1'); // Failed
        }
        die('1');
    }

    // This is an onetime payment success
    private function handleChargeSucceeded($event)
    {
        $charge = $event->data->object;
        $transaction = Transaction::where('charge_id', $charge->id)
            ->where('payment_method', 'stripe')
            ->first();

        if (!$transaction) {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        // We have the transaction so we have to update some fields
        $updateData = array(
            'status' => 'paid'
        );

        if (!$transaction->card_last_4) {
            if (!empty($charge->source->last4)) {
                $updateData['card_last_4'] = $charge->source->last4;
            } elseif (!empty($charge->payment_method_details->card->last4)) {
                $updateData['card_last_4'] = $charge->payment_method_details->card->last4;
            }
        }
        if (!$transaction->card_brand) {
            if (!empty($charge->source->brand)) {
                $updateData['card_brand'] = $charge->source->brand;
            } elseif (!empty($charge->payment_method_details->card->network)) {
                $updateData['card_brand'] = $charge->payment_method_details->card->network;
            }
        }

        Transaction::where('id', $transaction->id)
            ->update($updateData);

        do_action('wppayform/after_payment_status_change', $transaction->submission_id, 'paid');
    }

    private function handleChargeCaptured($charge)
    {
        // get authorized transaction which doesn't have a charge_id yet instead it has payment_intent id as charge_id for now
        $transaction = Transaction::where('charge_id', $charge->payment_intent)
            ->where('payment_method', 'stripe')
            ->first();

        if (!$transaction) {
            return;
        }

        $chargeId = $charge->id;

        if ($transaction->status === 'paid' || $transaction->status === 'refunded' || $transaction->status === 'partially-refunded') {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        if ($charge->amount_captured === 0){
            $status = 'refunded';
        } else if($charge->amount_refunded === 0) {
            $status = 'paid';
        } else {
            $status = 'partially-refunded';
        }

        // We have the transaction so we have to update some fields as well as charge_id
        $updateData = array(
            'status' => $status,
            'charge_id' => $chargeId,
        );

        if (!$transaction->card_last_4) {
            if (!empty($charge->source->last4)) {
                $updateData['card_last_4'] = $charge->source->last4;
            } elseif (!empty($charge->payment_method_details->card->last4)) {
                $updateData['card_last_4'] = $charge->payment_method_details->card->last4;
            }
        }
        if (!$transaction->card_brand) {
            if (!empty($charge->source->brand)) {
                $updateData['card_brand'] = $charge->source->brand;
            } elseif (!empty($charge->payment_method_details->card->network)) {
                $updateData['card_brand'] = $charge->payment_method_details->card->network;
            }
        }

        Transaction::where('id', $transaction->id)
            ->update($updateData);

        $submissionUpdateData = array(
            'payment_status' => $status,
            'payment_method' => 'stripe',
        );
  
        $currencySymbol = GeneralSettings::getCurrencySymbol($charge->currency);
        $submissionUpdateData['payment_total'] = $charge->amount_captured;
        $updateData['payment_total'] = $charge->amount_captured;

        $submissionModel = new Submission();
        $submissionModel->updateSubmission($transaction->submission_id, $submissionUpdateData);

        $content = '';
        if ($charge->amount_refunded > 0 && $charge->amount_captured > 0) {
            $content = __('Payment status changed from authorized to paid', 'wp-payment-form') . '. '.  $currencySymbol . $charge->amount_captured / 100 . __(' Captured, and released the remaining ', 'wp-payment-form') . $currencySymbol . $charge->amount_refunded / 100 . __(' to the customer', 'wp-payment-form');
        } else if ($charge->amount_refunded == 0) {
            $content = __('Payment status changed from authorized to paid', 'wp-payment-form');
        } else if($charge->amount_captured == 0) {
            $content = __('Payment status changed from authorized to refunded', 'wp-payment-form');
        }

        if ('paid' === $status || 'partially-refunded' === $status) {
            SubmissionActivity::createActivity(array(
                'form_id' => $transaction->form_id,
                'submission_id' => $transaction->submission_id,
                'type' => 'activity',
                'created_by' => 'Paymattic BOT',
                'content' => __('Full payment captured successfully. Charge ID: ', 'wp-payment-form') . $charge->id
            ));
    
            SubmissionActivity::createActivity(array(
                'form_id' => $transaction->form_id,
                'submission_id' => $transaction->submission_id,
                'type' => 'activity',
                'created_by' => 'Paymattic BOT',
                'content' => $content
            ));
        }

        // create a refund whether partially or fully refunded
        if ('paid' !== $status){
            $refundModel = new Refund();
            $exist = $refundModel->getRefundByChargeId($chargeId);

            if (!$exist){
                $refundAmount = $charge->amount_refunded;
                if (GeneralSettings::isZeroDecimal($transaction->currency)) {
                    $refundAmount = $refundAmount * 100;
                }

                $refundData = [
                    'form_id' => $transaction->form_id,
                    'submission_id' => $transaction->submission_id,
                    'payment_method' => 'stripe',
                    'charge_id' => $charge->id,
                    'payment_note' => "Customer Requested",
                    'payment_total' => $refundAmount,
                    'payment_mode' => $transaction->payment_mode,
                    'created_at' => gmdate('Y-m-d H:i:s', $charge->created),
                    'updated_at' => current_time('Y-m-d H:i:s'),
                    'status' => 'refunded',
                ];

                $refundId = $refundModel->create($refundData);

                $refundedMoney = $refundAmount / 100;
                SubmissionActivity::createActivity(array(
                    'form_id' => $transaction->form_id,
                    'submission_id' => $transaction->submission_id,
                    'type' => 'info',
                    'created_by' => 'Payform Bot',
                    /* translators: 1: currency symbol, 2: refunded amount */
                    'content' => sprintf(__('Payment Refunded via Admin/Stripe. Refunded: %1$s %2$s', 'wp-payment-form'), $currencySymbol, $refundedMoney)
                ));
                $refund = $refundModel->getRefund($refundId);
                do_action('wppayform/payment_refunded_stripe', $refund, $refund->form_id, $charge);
                do_action('wppayform/payment_refunded', $refund, $refund->form_id, $charge);
            }
        }

        do_action('wppayform/after_payment_status_change', $transaction->submission_id, 'paid');
    }

    /*
     * Handle Subscription Payment IPN
     * Refactored in version 2.0
     */
    private function maybeHandleSubscriptionPayment($event)
    {
        $data = $event->data->object;

        // Webhook events are immutable and render in the account's default API version
        // (Basil 2025+), which drops top-level invoice.subscription and invoice.charge.
        // Re-fetch the invoice with a direct API call so Stripe renders it in our pinned
        // version (2019-05-16), restoring both fields and keeping charge_id identical to
        // the creation/sync flows (both use $invoice->charge = ch_...) so dedup matches
        // and the admin "Charge ID" link resolves to a real charge.
        if (!empty($data->id)) {
            $freshInvoice = ApiRequest::request([], 'invoices/' . $data->id, 'GET');
            if (!is_wp_error($freshInvoice) && !empty($freshInvoice->id)) {
                $data = $freshInvoice;
            }
        }

        // Resolve subscription id across Stripe API versions.
        // Old (<= 2025-03): top-level invoice.subscription.
        // New (Basil, 2025-05-28+): invoice.parent.subscription_details.subscription,
        // fallback per-line invoice.lines.data[].parent.subscription_item_details.subscription.
        $subscriptionId = false;
        if (!empty($data->subscription)) {
            $subscriptionId = $data->subscription;
        } elseif (isset($data->parent->subscription_details->subscription)) {
            $subscriptionId = $data->parent->subscription_details->subscription;
        } elseif (isset($data->lines->data[0]->parent->subscription_item_details->subscription)) {
            $subscriptionId = $data->lines->data[0]->parent->subscription_item_details->subscription;
        }

        if (!$subscriptionId) {
            return;
        }

        // The first invoice (billing_reason = subscription_create) is already recorded
        // synchronously by the checkout/creation flow. Skip it here to avoid inserting a
        // duplicate transaction; only renewals (subscription_cycle/update) are webhook-owned.
        if (($data->billing_reason ?? '') === 'subscription_create') {
            return;
        }

        $subscription = Subscription::where('vendor_subscriptipn_id', $subscriptionId)
            ->where('vendor_customer_id', $data->customer)
            ->first();

        if (!$subscription) {
            error_log('[WPF-LITE-IPN] ABORT: no wpf_subscriptions match for sub=' . $subscriptionId . ' customer=' . $data->customer);
            return;
        }

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($subscription->submission_id);
        if (!$submission) {
            error_log('[WPF-LITE-IPN] ABORT: no submission for subscription ' . $subscription->id);
            return;
        }

        do_action('wppayform/form_submission_activity_start', $submission->form_id);

        // Maybe Insert The transaction Now
        $subscriptionTransaction = new SubscriptionTransaction();

        $totalAmount = $data->total;
        if (GeneralSettings::isZeroDecimal($data->currency)) {
            $totalAmount = intval($totalAmount * 100);
        }

        $paymentIntentId = '';
        if (!empty($data->payment_intent)) {
            $paymentIntentId = is_object($data->payment_intent)
                ? (isset($data->payment_intent->id) ? $data->payment_intent->id : '')
                : (string) $data->payment_intent;
        }

        // Resolve a real, linkable Stripe payment id for this renewal. The re-fetched
        // (version-pinned) invoice normally exposes the charge (ch_...) — the same id the
        // creation/sync flows store, so dedup matches and the admin link resolves. Fall
        // back to the payment intent (pi_..., still a valid Stripe object), and only as an
        // absolute last resort the invoice id (in_..., unique but not linkable).
        $chargeId = '';
        if (!empty($data->charge)) {
            $chargeId = is_object($data->charge) ? ($data->charge->id ?? '') : (string) $data->charge;
        }
        if (!$chargeId && $paymentIntentId) {
            $chargeId = $paymentIntentId;
        }
        if (!$chargeId) {
            $chargeId = $data->id;
        }

        $transactionId = $subscriptionTransaction->maybeInsertCharge([
            'form_id' => $submission->form_id,
            'user_id' => $submission->user_id,
            'submission_id' => $submission->id,
            'subscription_id' => $subscription->id,
            'transaction_type' => 'subscription',
            'payment_method' => 'stripe',
            'charge_id' => $chargeId,
            'payment_total' => $totalAmount,
            'status' => $data->status,
            'currency' => $submission->currency,
            'payment_mode' => ($data->livemode) ? 'live' : 'test',
            'payment_note' => maybe_serialize($data),
            'created_at' => gmdate('Y-m-d H:i:s', $data->created),
            'updated_at' => gmdate('Y-m-d H:i:s', $data->created),
            '_alt_charge_ids' => array_filter([$paymentIntentId]),
        ]);

        $transaction = $subscriptionTransaction->getTransaction($transactionId);

        $subscriptionModel = new Subscription();

        $subscriptionModel->updateSubscription($subscription->id, [
            'status' => 'active'
        ]);

        $mainSubscription = $subscriptionModel->getSubscription($subscription->id);

        $isNewPayment = $subscription->bill_count != $mainSubscription->bill_count;

        // Check For Payment EOT
        if ($mainSubscription->bill_times && $mainSubscription->bill_count >= $mainSubscription->bill_times) {

            // We have to cancel this subscription as total bill times done
            $response = ApiRequest::request([
                'cancel_at_period_end' => 'true'
            ], 'subscriptions/' . $mainSubscription->vendor_subscriptipn_id, 'POST');

            if (!is_wp_error($response)) {
                $subscriptionModel->updateSubscription($mainSubscription->id, [
                    'status' => 'completed'
                ]);
                SubmissionActivity::createActivity(array(
                    'form_id' => $submission->form_id,
                    'submission_id' => $submission->id,
                    'type' => 'activity',
                    'created_by' => 'Paymattic BOT',
                    'content' => __('The Subscription Term Period has been completed', 'wp-payment-form')
                ));
                $updatedSubscription = $subscriptionModel->getSubscription($subscription->id);
                do_action('wppayform/subscription_payment_eot_completed', $submission, $updatedSubscription, $submission->form_id, $response);
                do_action('wppayform/subscription_payment_eot_completed_stripe', $submission, $updatedSubscription, $submission->form_id, $response);
            }
        }

        if ($isNewPayment) {
            // New Payment Made so we have to fire some events here
            do_action('wppayform/subscription_payment_received', $submission, $transaction, $submission->form_id, $subscription);
            do_action('wppayform/subscription_payment_received_stripe', $submission, $transaction, $submission->form_id, $subscription);
        }
    }

    /*
     * Handle Subscription Renewal Payment Failure
     * Fires on invoice.payment_failed when a recurring charge fails (expired/declined card).
     * Unlike charge.failed, this event carries the subscription context directly, so site
     * owners can hook custom logic (dunning, access hold, notifications) on each failed attempt.
     * MVP: log activity + fire actions only; final cancellation stays owned by
     * customer.subscription.deleted.
     */
    private function handleSubscriptionPaymentFailed($event)
    {
        $data = $event->data->object;

        // Re-fetch the invoice in our pinned API version (2019-05-16) to restore
        // subscription/charge fields dropped by newer default API versions — same
        // reason as the renewal-success flow.
        if (!empty($data->id)) {
            $freshInvoice = ApiRequest::request([], 'invoices/' . $data->id, 'GET');
            if (!is_wp_error($freshInvoice) && !empty($freshInvoice->id)) {
                $data = $freshInvoice;
            }
        }

        // Resolve subscription id across Stripe API versions.
        $subscriptionId = false;
        if (!empty($data->subscription)) {
            $subscriptionId = $data->subscription;
        } elseif (isset($data->parent->subscription_details->subscription)) {
            $subscriptionId = $data->parent->subscription_details->subscription;
        } elseif (isset($data->lines->data[0]->parent->subscription_item_details->subscription)) {
            $subscriptionId = $data->lines->data[0]->parent->subscription_item_details->subscription;
        }

        if (!$subscriptionId) {
            return;
        }

        $subscription = Subscription::where('vendor_subscriptipn_id', $subscriptionId)
            ->where('vendor_customer_id', $data->customer)
            ->first();

        if (!$subscription) {
            return;
        }

        // Skip already-terminal subscriptions.
        if (in_array($subscription->status, array('cancelled', 'completed'))) {
            return;
        }

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($subscription->submission_id);
        if (!$submission) {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $submission->form_id);

        $attemptCount = isset($data->attempt_count) ? absint($data->attempt_count) : 0;
        $nextAttempt = !empty($data->next_payment_attempt)
            ? gmdate('Y-m-d H:i:s', $data->next_payment_attempt)
            : '';

        $note = __('Subscription renewal payment failed.', 'wp-payment-form');
        if ($attemptCount) {
            /* translators: %d: retry attempt count */
            $note .= ' ' . sprintf(__('Attempt: %d.', 'wp-payment-form'), $attemptCount);
        }
        if ($nextAttempt) {
            /* translators: %s: next retry datetime (UTC) */
            $note .= ' ' . sprintf(__('Next retry: %s UTC.', 'wp-payment-form'), $nextAttempt);
        }

        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'info',
            'created_by' => 'Paymattic BOT',
            'content' => $note
        ));

        // Fire hooks so site owners can trigger custom logic on recurring failure.
        do_action('wppayform/subscription_payment_failed', $submission, $subscription, $submission->form_id, $data);
        do_action('wppayform/subscription_payment_failed_stripe', $submission, $subscription, $submission->form_id, $data);
    }

    /*
     * Refactored at version 2.0
     * We are logging refunds now for both subscription and
     * One time payments
     */
    private function handleChargeRefund($event)
    {
        $data = $event->data->object;

        $chargeId = $data->id;

        // Get the Transaction from database
        $transaction = Transaction::where('charge_id', $chargeId)
            ->where('payment_method', 'stripe')
            ->first();

        if (!$transaction) {
            // Not our transaction
            return;
        }

        do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);

        if (!$submission) {
            return;
        }

        $remainingAmount = $data->amount - $data->amount_refunded;

        if (GeneralSettings::isZeroDecimal($transaction->currency)) {
            $remainingAmount = intval($remainingAmount * 100);
        }

        if ($remainingAmount == 0) {
            $status = 'refunded';
        } else {
            $status = 'partially-refunded';
        }

        Transaction::where('id', $transaction->id)
            ->update([
                'status' => $status
            ]);

        do_action('wppayform/after_payment_status_change', $transaction->submission_id, $status);

        $submissionModel->updateSubmission($submission->id, [
            'payment_status' => $status
        ]);

        // We have to record this refund to be honest
        $refunds = $data->refunds->data;
        $refundModel = new Refund();

        foreach ($refunds as $refund) {
            $exist = $refundModel->getRefundByChargeId($refund->id);
            if (!$exist) {
                $refundAmount = $refund->amount;
                if (GeneralSettings::isZeroDecimal($transaction->currency)) {
                    $refundAmount = $refundAmount * 100;
                }

                $refundData = [
                    'form_id' => $transaction->form_id,
                    'submission_id' => $transaction->submission_id,
                    'payment_method' => 'stripe',
                    'charge_id' => $refund->id,
                    'payment_note' => $refund->reason,
                    'payment_total' => $refundAmount,
                    'payment_mode' => $transaction->payment_mode,
                    'created_at' => gmdate('Y-m-d H:i:s', $refund->created),
                    'updated_at' => current_time('Y-m-d H:i:s'),
                    'status' => 'refunded',
                ];

                if ($transaction->subscription_id) {
                    $refundData['subscription_id'] = $transaction->subscription_id;
                }

                $refund = $refundModel->createRefund($refundData);

                $refundedMoney = $refundAmount / 100;
                SubmissionActivity::createActivity(array(
                    'form_id' => $transaction->form_id,
                    'submission_id' => $transaction->submission_id,
                    'type' => 'info',
                    'created_by' => 'Payform Bot',
                    /* translators: %s: refunded amount */
                    'content' => sprintf(__('Payment Refunded By Stripe. Refunded: %s', 'wp-payment-form'), $refundedMoney)
                ));
                $refund = $refundModel->getRefund($refund->id);
                do_action('wppayform/payment_refunded_stripe', $refund, $refund->form_id, $data);
                do_action('wppayform/payment_refunded', $refund, $refund->form_id, $data);
            }
        }
    }

    /*
     * Handle Subscription Canceled
     */
    private function handleSubscriptionCancelled($event)
    {
        $data = $event->data->object;
        $subscriptionId = $data->id;
        $subscriptionModel = new Subscription();

        $subscription = Subscription::where('vendor_subscriptipn_id', $subscriptionId)
            ->where('status', '!=', 'completed')
            ->first();

        if (!$subscription || $subscription->status == 'completed' || $subscription->status == 'cancelled') {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $subscription->form_id);

        $subscriptionModel->updateSubscription($subscription->id, [
            'status' => 'cancelled'
        ]);
        
        SubmissionActivity::createActivity(array(
            'form_id' => $subscription->form_id,
            'submission_id' => $subscription->submission_id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'content' => __('The Subscription Has been cancelled', 'wp-payment-form')
        ));

        $subscription = $subscriptionModel->getSubscription($subscription->id);

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($subscription->submission_id);

        // New Payment Made so we have to fire some events here
        do_action('wppayform/subscription_payment_canceled', $submission, $subscription, $submission->form_id, $data);
        do_action('wppayform/subscription_payment_canceled_stripe', $submission, $subscription, $submission->form_id, $data);
    }

    /**
     * Handle Checkout Session Completed
     * This handles bank transfers and other payment methods in hosted checkout
     */
    private function handleCheckoutSessionCompleted($event)
    {
        $data = $event->data->object;

        $submissionId = $data->client_reference_id ?? null;

        if (!$submissionId) {
            return;
        }

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($submissionId);
        if (!$submission) {
            return;
        }

        $session = CheckoutSession::retrive($data->id, [
            'expand' => [
                'subscription.latest_invoice.payment_intent',
                'payment_intent'
            ]
        ], $submission->form_id);

        if (!$session) {
            return;
        }
        
        $stripeHostedHandler = new StripeHostedHandler();
        $stripeHostedHandler->handleCheckoutSessionSuccess($submission, $session);
    }

    /**
     * Handle Payment Intent Succeeded
     * This handles bank transfers and other payment methods that use payment_intent
     */
    private function handlePaymentIntentSucceeded($event)
    {
        $paymentIntent = $event->data->object;
        
        // Try to get submission ID from metadata
        $submissionId = null;
        if (!empty($paymentIntent->metadata)) {
            $submissionId = $paymentIntent->metadata->{'Submission ID'} ?? null;
        }
        
        // If not in metadata, try to find transaction by payment_intent ID
        if (!$submissionId) {
            $transaction = Transaction::where('charge_id', $paymentIntent->id)
                ->where('payment_method', 'stripe')
                ->first();
            
            if ($transaction) {
                $submissionId = $transaction->submission_id;
            }
        }
        
        if (!$submissionId) {
            return;
        }
        
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($submissionId);
        
        if (!$submission) {
            return;
        }
        
        // Check if this is from a checkout session (hosted checkout)
        $sessionId = $submissionModel->getMeta($submission->id, 'stripe_intended_session');
        
        if ($sessionId) {
            // Retrieve the checkout session to process it properly
            $session = CheckoutSession::retrive($sessionId, [
                'expand' => [
                    'subscription.latest_invoice.payment_intent',
                    'payment_intent'
                ]
            ], $submission->form_id);
            
            if ($session) {
                $stripeHostedHandler = new StripeHostedHandler();
                $stripeHostedHandler->handleCheckoutSessionSuccess($submission, $session);
            }
        } else {
            // Handle as direct payment_intent (not from checkout session)
            // This might be for inline payments or other scenarios
            $transactionModel = new Transaction();
            $transaction = $transactionModel->getLatestIntentedTransaction($submission->id);
            
            if ($transaction && $transaction->status == 'intented') {
                $paymentSuccessHandler = new PaymentSuccessHandler();
                $paymentSuccessHandler->processOnetimeSuccess($transaction, $paymentIntent, $submission);
            }
        }
    }

    private function handleChargeFailed($event)
    {
        $data = $event->data->object;
        $chargeId = $data->id;
        $transaction = Transaction::where('charge_id', $chargeId)
            ->where('payment_method', 'stripe')
            ->first();
        if (!$transaction) {
            return;
        }
        // update transaction status to failed
        Transaction::where('id', $transaction->id)
            ->update([
                'status' => 'failed'
            ]);
        do_action('wppayform/form_payment_failed_stripe', $transaction->submission_id, 'failed');
        do_action('wppayform/form_payment_failed', $transaction->submission_id, 'failed');
        // create submission activity
        do_action('wppayform/form_submission_activity_start', $transaction->form_id);
        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'Payform Bot',
            /* translators: %s: failure reason message */
            'content' => sprintf(__('Payment Failed in Stripe. Reason: %s', 'wp-payment-form'), $data->failure_message ?? 'Unknown error')
        ));
    }

    /**
     * Retrieve Stripe event by ID
     */
    public function retrive($eventId)
    {
        return ApiRequest::request([], 'events/' . $eventId, 'GET');
    }
}
