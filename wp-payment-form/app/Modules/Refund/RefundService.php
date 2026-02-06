<?php

namespace WPPayForm\App\Modules\Refund;

use WPPayForm\App\Models\Meta;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\SubmissionActivity;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Modules\Refund\Exceptions\RefundException;
use WPPayForm\Framework\Support\Arr;

class RefundService
{
    const STATUS_REFUNDED = 'refunded';
    const STATUS_PARTIALLY_REFUNDED = 'partially-refunded';
    const STATUS_PAID = 'paid';

    public function validateRefundAmount(float $amount): void
    {
        if (!is_numeric($amount) || $amount <= 0) {
            throw new RefundException('Invalid refund amount', 422);
        }
    }

	/**
	 * @throws RefundException
	 */
	public function validateTransaction(Transaction $transaction, int $submissionId): void
    {
        if ( $transaction->submission_id != $submissionId ) {
            throw new RefundException('Transaction not found', 404);
        }

    }

	/**
	 * @throws RefundException
	 */
	public function validateUserAccess(Submission $submission, int $currentUserId): void
    {
        if ( ( $submission->user_id != $currentUserId && !current_user_can('manage_options')) ) {
            throw new RefundException('Unauthorized', 403);
        }
    }

	/**
	 * @throws RefundException
	 */
	public function validateRefundAvailability(int $amountInCents, int $refundAvailable): void
    {
        if ($amountInCents > $refundAvailable) {
            throw new RefundException('Refund amount exceeds available amount', 422);
        }
    }

    // //update status for submission
    // public function updateRefundStatus(int $amountInCents, int $refundAvailable): string
    // {
    //     return $amountInCents < $refundAvailable 
    //         ? self::STATUS_PARTIALLY_REFUNDED 
    //         : self::STATUS_REFUNDED;
    // }

    public function processPaymentMethodRefund(Transaction $transaction, array $payload, $subscription, $submission)
    {
        $amount = Arr::get($payload, 'amount', 0);
        $reason = Arr::get($payload, 'reason', '');
        $cancelSubscription = Arr::get($payload, 'cancel_Subscription', false);

        $refundResult = apply_filters(
            "wppayform/process_refund_{$transaction->payment_method}",
            [
                'transaction' => $transaction,
                'amount' => $amount,
                'reason' => $reason,
                'cancel_Subscription' => $cancelSubscription,
                'subscription' => $subscription,
                'submission' => $submission
            ]
        );

        if ($refundResult === false) {
            throw new RefundException('Refund processing failed', 500);
        }

        if (is_wp_error($refundResult)) {
            $errorMessages = $refundResult->get_error_messages();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RefundException($errorMessages[0] ?? 'Unknown refund error', 400);
        }

        return $refundResult;
    }

    public function createRefundTransaction($originalTransaction, $formId, $submissionId, $payload, $refundData) 
    {
        $amountInCents = Arr::get($payload, 'amount_in_cents', 0);
        $remainingAmount = Arr::get($refundData, 'remaining_amount', 0);
        $processTransaction = Transaction::create([
            'form_id' => $formId,
            'submission_id' => $submissionId,
            'user_id' => $originalTransaction->user_id,
            'subscription_id' => $originalTransaction->subscription_id,
            'transaction_type' => $originalTransaction->transaction_type,
            'payment_method' => $originalTransaction->payment_method,
            'card_last_4' => $originalTransaction->card_last_4 ?? '',
            'card_brand' => $originalTransaction->card_brand ?? '',
            'payment_total' => $amountInCents,
            'status' => 'refunded',
            'currency' => $originalTransaction->currency,
            'payment_mode' => $originalTransaction->payment_mode,
            'payment_note' => $originalTransaction->payment_note,
            'charge_id' => $originalTransaction->charge_id
        ]);

        if ($processTransaction) {
            $this->updateOrCreateMeta($originalTransaction, $formId, $remainingAmount);
            $this->updateSubmissionPaymentStatus($submissionId, $remainingAmount, $originalTransaction->transaction_type);
            $this->logRefundActivity($formId, $submissionId, $amountInCents/100);
        }

        return $processTransaction;
    }

    public function updateOrCreateMeta(Transaction $transaction, $formId, $remainingAmount)
    {
        return Meta::UPdateOrCreate([
            'meta_group' => 'wpf_transactions',
            'option_id' => $transaction->id,
            'meta_key' => 'refund_available',
            'form_id' => $formId
        ], [
            'meta_value' => $remainingAmount
        ]);
    }   

    public function logRefundActivity($formId, $submissionId, $amountInCents){
        SubmissionActivity::createActivity(array(
            'form_id' => $formId,
            'submission_id' => $submissionId,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'content' => "Refund of {$amountInCents} processed successfully.",
        ));
    }

    public function updateSubmissionPaymentStatus($submissionId, $remainingAmount, $transactionType)
    {
        if ($remainingAmount <= 0 && $transactionType !== 'subscription') {
            Submission::where('id', $submissionId)->update(['payment_status' => RefundService::STATUS_REFUNDED]);
            do_action('wppayform/after_payment_status_change', $submissionId, RefundService::STATUS_REFUNDED);
        }
    }
}
