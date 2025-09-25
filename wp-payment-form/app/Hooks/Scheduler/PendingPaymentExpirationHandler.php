<?php

namespace WPPayForm\App\Hooks\Scheduler;

use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\SubmissionActivity;
use WPPayForm\App\Models\Subscription;
use WPPayForm\App\Models\Transaction;

class PendingPaymentExpirationHandler
{
    public function register()
    {
        add_action('wppayform/after_transaction_data_insert', [$this, 'autoFailPendingPayments'], 10, 2);
        add_action('wppayform/process_expiration', [$this, 'processExpiration'], 10, 3);
    }

    public function autoFailPendingPayments($transactionId, $transaction)
    {
        $submissionId = $transaction['submission_id'];
        $formId = $transaction['form_id'];

        $expirationSettings = get_option('wppayform_global_currency_settings');
        $expirationSettings = safeUnserialize($expirationSettings);
        $expiration_time_enabled = !empty($expirationSettings['expiration_time_enabled']) ? (bool) $expirationSettings['expiration_time_enabled'] : false;
        $expirationTimeType = !empty($expirationSettings['expiration_time_type']) ? sanitize_text_field($expirationSettings['expiration_time_type']) : null;
        $expirationTime = !empty($expirationSettings['expiration_time']) ? absint($expirationSettings['expiration_time']) : null;
        
        if (!$expiration_time_enabled || !$expirationTimeType || !$expirationTime || ($expirationTimeType === 'minutes' && $expirationTime < 3)) {
            return false;
        }

        $submission = (new Submission())->getSubmissionWithRelations($submissionId, $formId);
        $paymentStatus = $submission ? $submission->payment_status : null;
        if (!$paymentStatus || $paymentStatus !== 'pending') {
            return false;
        }

        if ($expirationTimeType === 'minutes') {
            $expirationTime = time() + ($expirationTime * 60);
        } else {
            $expirationTime = time() + ($expirationTime * 24 * 60 * 60);
        }

        $args = [
            'submission_id' => $submissionId,
            'form_id' => $formId,
            'transaction_id' => $transactionId
        ];

        // Schedule the expiration action
        as_schedule_single_action(
            $expirationTime,
            'wppayform/process_expiration',
            $args,
            'wppayform-scheduler-task'
        );

        return true;
    }

    public function processExpiration($submission_id, $form_id, $transaction_id)
    {
        if (!$submission_id || !$form_id || !$transaction_id) {
            return false;
        }

        $submission = (new Submission())->getSubmissionWithRelations($submission_id, $form_id);

        if (!$submission) {
            return false;
        }
        $submissionStatus = $submission->payment_status;
        $transaction = $submission->transactions->first();
        $TransactionStatus = $transaction ? $transaction->status : null;
        $subscription = $submission->subscriptions->first();
        $SubscriptionStatus = $subscription ? $subscription->status : null;

        if ($submissionStatus !== 'pending' || ($submissionStatus !== 'pending' && $TransactionStatus !== 'intented') || ($submissionStatus !== 'pending' && $SubscriptionStatus !== 'intented') ) {
            return false;
        }

        $updateSubmission = (new Submission())->updateSubmission($submission_id,  ['payment_status' => 'failed']);
        $updateTransaction = (new Transaction())->updateTransaction($transaction_id,  ['status' => 'failed']);

        $updateSubscription = false;
        if ($subscription) {
            $updateSubscription = (new Subscription())->updateSubscription($subscription->id,  ['status' => 'cancelled']);
        }

        if ((!$updateSubmission && !$updateTransaction) || (!$updateSubmission && !$updateSubscription)) {
            return false;
        }
        SubmissionActivity::create([
            'submission_id' => $submission_id,
            'form_id'       => $form_id,
            'status'        => 'failed',
            'type'         => 'timeout',
            'created_by'    => 'Paymattic BOT',
            'content'       => 'Payment failed due to timeout.',
            'created_at'    => current_time('mysql'),
        ]);

        return true;
    }
}
