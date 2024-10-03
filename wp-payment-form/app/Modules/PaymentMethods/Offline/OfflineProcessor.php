<?php

namespace WPPayForm\App\Modules\PaymentMethods\Offline;

use DateTime;
use WPPayForm\App\Models\SubmissionActivity;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\Submission;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Refund;
use WPPayForm\App\Models\Subscription;
use WPPayForm\App\Models\SubscriptionTransaction;


class OfflineProcessor
{
    public function init()
    {
        // Init paypal Element for Editor
        new OfflineElement();
        (new OfflineSettings())->init();
        // Choose Payment method Here
        add_filter('wppayform/choose_payment_method_for_submission', array($this, 'choosePaymentMethod'), 10, 4);
        add_action('wppayform/form_submission_make_payment_offline', array($this, 'makeFormPayment'), 10, 4);
        //sync offline subscriptions
        add_action('wppayform/offline_action_subcr_sync', array($this, 'processSubscriptionSync'), 10, 4);
        // offline subscription status change 
        add_action('wppayform/offline_action_subcr_status_change', array($this, 'processSubscriptionStatusChange'), 10, 4);
        // subscription payment status changes on manually
        add_action('wppayform/offline_action_subcr_payment_status_change', array($this, 'processSubscriptionPaymentStatusChange'), 10,4);
        // fetch all subscription entry wise
        add_filter('wppayform/form_entry', array($this, 'addPaymentName'));
    }


    public function choosePaymentMethod($paymentMethod, $elements, $formId, $form_data)
    {
        if ($paymentMethod) {
            // Already someone choose that it's their payment method
            return $paymentMethod;
        }
        // Now We have to analyze the elements and return our payment method
        foreach ($elements as $element) {
            if ((isset($element['type']) && $element['type'] == 'offline_gateway_element')) {
                return 'offline';
            }
        }
        return $paymentMethod;
    }

    public function makeFormPayment($transactionId, $submissionId, $form_data, $form)
    {
        $transactionModel = new Transaction();
        $transaction = $transactionModel->getTransaction($transactionId);
        $settings = (new OfflineSettings())->getPaymentSettings();

        if ($transactionId) {
            $transactionModel->updateTransaction($transactionId, array(
                'payment_mode' => Arr::get($settings, 'payment_mode.value')
            ));
        }

        $submissionModel = new Submission();

        $submissionModel->updateSubmission($submissionId, array(
            'payment_mode' => Arr::get($settings, 'payment_mode.value'),
        ));

        SubmissionActivity::createActivity(array(
            'form_id' => $form->ID,
            'submission_id' => $submissionId,
            'type' => 'info',
            'created_by' => 'Payform Bot',
            'content' => __('Offline Payment recorded and change the status to pending', 'wp-payment-form-pro')
        ));

        $subscriptionModel = new Subscription();
        $subscriptions = $subscriptionModel->getSubscriptions($submissionId);
      
        if(!empty($subscriptions)) {
            foreach($subscriptions as $subscription) {
                $vendor_data = array(
                    'custom' => $subscription->id,
                    'payment_status' => 'pending',
                    'txn_id' => self::generateRandomID(),
                    'payment_note' => __('Offline subscription initialized and added', 'wp-payment-form-pro'),
                    'payment_gross' =>  $subscription->recurring_amount,
                );
                $this->processSubscriptionPayment($vendor_data, $subscription->id);
            }
        }
      
    }

    public function processSubscriptionSync($submission, $submissionId, $subscriptions, $formId)
    {

        foreach ($subscriptions as $subscription) {
          $expectedBillingTimes = $this->getSubscriptionBillingTimes($subscription);
          $gapDays = $this->getBillingGapDays($subscription);
          
          $subscriptionId = Arr::get($subscription, 'id');
          $gross_payment = Arr::get($subscription, 'recurring_amount');
        
          // gap between billing intervals
          $lastBillCreatedAt = $this->getLastBillCreationDate($subscriptionId);
   
          $interval  = $gapDays;

          for($i = 0; $i<$expectedBillingTimes; $i++) {
                $vendor_data = array(
                    'custom' => $subscriptionId,
                    'payment_status' => 'pending',
                    'txn_id' => self::generateRandomID(),
                    'payment_note' => __('Offline subscription initialized and added as pending.', 'wp-payment-form-pro'),
                    'payment_gross' =>  $gross_payment,
                );

                // sync the exact billing dates
                $createdAt = date("Y-m-d H:i:s.u O", strtotime('+'. $interval .  " days", strtotime($lastBillCreatedAt)));

                $this->processSubscriptionPayment($vendor_data, $subscriptionId, $createdAt);

                $interval += $gapDays;
            }
        }

        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submissionId,
            'type' => 'info',
            'created_by' => 'Paymattic Bot',
            'content' => 'Offline subscription billings syncronized.'
        ));

        wp_send_json_success(array(
            'message' => __('Subscription synced successfully', 'wp-payment-form-pro'),
        ), 200);
    
    }

    public function getSubscriptionBillingTimes($subscription)
    {

        $currentDate = new DateTime();
        $startingDate = new DateTime(Arr::get($subscription, 'created_at'));
        $interval = $startingDate->diff($currentDate);
        $days = $interval->days;

        $billingInterval = Arr::get($subscription, 'billing_interval');
        $related_payments = Arr::get($subscription, 'related_payments');
        $billedTimes = count($related_payments);
        $billTimes = Arr::get($subscription, 'bill_times');

        $expectedBillingTimes = 0;
        
        if(intval($billTimes) != 0 && $billedTimes >= $billTimes) {
            return $expectedBillingTimes;
        }

        if($billingInterval == 'daily') {
            $expectedBillingTimes = $days/1;
        }
        if($billingInterval == 'weekly') {
            $expectedBillingTimes = $days/7;
        }
        if($billingInterval == 'monthly') {
            $expectedBillingTimes = $days/30;
        }
        if($billingInterval == 'yearly') {
            $expectedBillingTimes = $days/365;
        }

        return $expectedBillingTimes - $billedTimes;
    }

    public static function getBillingGapDays($subscription)
    {
        $billingInterval = Arr::get($subscription, 'billing_interval');

        if($billingInterval == 'daily') {
            return 1;
        }
        if($billingInterval == 'weekly') {
           return 7;
        }
        if($billingInterval == 'monthly') {
            return 30;
        }
        if($billingInterval == 'yearly') {
           return 365;
        }
    }

    public static function getLastBillCreationDate($subscriptionId)
    {
        $subscriptionTransactionModel = new SubscriptionTransaction();
        $lastSubScriptionTransaction = $subscriptionTransactionModel->getLastSubscriptionTransaction($subscriptionId);
    
        return $lastSubScriptionTransaction->created_at;
    }

    public static function generateRandomID()
    {
        return bin2hex(openssl_random_pseudo_bytes(16));
    }

    public function addPaymentName($submission)
    {
        if ($submission->payment_method == 'offline') {
            foreach ($submission->transactions as $transaction) {
                if ($transaction->payment_method == 'offline') {
                    $paymentMethod = Arr::get($submission->form_data_raw, '__offline_payment_gateway', 'offline');
                    $transaction->payment_method = $paymentMethod . ' (Offline)';
                    $transaction->transaction_url = $paymentMethod;
                }
            }
        }
        return $submission;
    }

    public function processSubscriptionStatusChange($submission, $subscription, $status, $note)
    {
        if(!$subscription['id']) {
            wp_send_json_error(array(
                'message' => __("No subscription found", 'wp-payment-form'),
            ), 423);
        }

        $data['status'] = $status;

        $subscriptionModel = new Subscription();

        $subscriptionModel->updateSubscription($subscription['id'], $data);

        $content = 'Subscription status changed to ' . $status;

        if($note) {
            $content = 'Subscription status changed to ' . $status . ' - ' . $note;
        }

        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'info',
            'created_by' => 'Paymattic Bot',
            'content' => $content
        ));

        do_action('wppayform/offline_subscription_status_changed', $submission->form_id, $submission, $subscription);
        do_action('wppayform/offline_subscription_status_changed_to_' . $status, $submission->form_id, $submission, $subscription);

        wp_send_json_success(array( 
            'message' => __("Offline Subscription status changes successfully!"),
        ), 200);
    }

    public function processSubscriptionPaymentStatusChange($submission, $transactionId, $newStatus, $note)
    {
        if(!$transactionId) {
            wp_send_json_error(array(
                'message' => __("No transaction found", 'wp-payment-form-pro'),
            ), 423);
        }

        $updatedData = array(
            'status' => $newStatus,
            'payment_note' => $note,
        );


        $transactionModel = new Transaction();
        if ($transactionId) {
            $transactionModel->updateTransaction($transactionId, $updatedData);
        }

        if('paid' == $newStatus) {
                SubmissionActivity::createActivity(array(
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'info',
                'created_by' => 'Paymattic Bot',
                'content' => __('Subscription billed for the billing count 2', 'wp-payment-form-pro')
            ));
        }

        $content = 'Subscription status changed to ' . $newStatus;

        if($note) {
            $content = 'Subscription status changed to ' . $newStatus . ' - ' . $note;
        }

        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'info',
            'created_by' => 'Paymattic Bot',
            'content' => $content
        ));

        do_action('wppayform/offline_subscription_payment_status_changed', $submission, $transactionId);
        do_action('wppayform/offline_subscription_payment_status_updated_to' . $newStatus, $submission, $transactionId);

        if('paid' == $newStatus) {
            $subscriptionTransactionModel = new SubscriptionTransaction();
            $transaction = $subscriptionTransactionModel->getTransaction($transactionId);

            $subscriptionModel = new Subscription();
            $subscription = $subscriptionModel->getSubscription($transaction->subscription_id);
            
            do_action('wppayform/subscription_payment_received_offline', $submission,$submission->form_id, $subscription);
        }

        wp_send_json_success(array(
            'message' => __('Subscription payment status successfully changed to ' . $newStatus, 'wp-payment-form-pro')
        ), 200);
    }

     public function processSubscriptionPayment($vendor_data, $subscriptionId, $createdtAt = null)
    {

        if (!intval($subscriptionId)) {
            $subscriptionId = $vendor_data['custom'];
        }
        if (!$subscriptionId) {
            return;
        }

        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->getSubscription($subscriptionId);

        if (!$subscription) {
            return;
        }

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($subscription->submission_id);

        if (!$submission) {
            return;
        }

        $subscriptionTransactionModel = new SubscriptionTransaction();

        $paymentStatus = strtolower($vendor_data['payment_status']);
        if ($paymentStatus == 'completed') {
            $paymentStatus = 'paid';
        }

        $paymentData = [
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'subscription_id' => $subscription->id,
            'transaction_type' => 'subscription',
            'payment_method' => $submission->payment_method,
            'charge_id' => $vendor_data['txn_id'],
            'payment_total' => $vendor_data['payment_gross'],
            'status' => $paymentStatus,
            'currency' => $submission->currency,
            'payment_mode' => $submission->payment_mode,
            'payment_note' => maybe_serialize($vendor_data['payment_note']),
            'created_at' => $createdtAt
        ];

        $transactionId = $subscriptionTransactionModel->maybeInsertCharge($paymentData);

        $subscriptionModel->updateSubscription($subscription->id, [
            'status' => 'active'
        ]);

        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'content' => __('Subscription Payment has been added as pending', 'wp-payment-form-pro')
        ));

    }

}
