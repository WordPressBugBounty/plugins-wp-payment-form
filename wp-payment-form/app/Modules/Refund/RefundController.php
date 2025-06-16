<?php

namespace WPPayForm\App\Modules\Refund;

use WPPayForm\App\Http\Controllers\Controller;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\Subscription;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Modules\Refund\Exceptions\RefundException;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Modules\Refund\RefundService;


class RefundController extends Controller
{

    public function processRefund($formId, $submissionId)
    {
        try {
            $refundService = new RefundService();
            $payload = $this->extractRefundPayload();
            $transactionId = Arr::get($payload, 'transaction_id', 0);
            
            // Get validated transaction
            $transaction = (new Transaction())->getValidatedTransaction($submissionId, $transactionId, $formId);
            
            $refundService->validateTransaction($transaction, $submissionId);
            $this->validateUserAndSubmission($refundService, $submissionId);

            $refundData = $this->calculateRefundAmounts($refundService, $transaction, $payload['amount_in_cents']);
            $submission = (new Submission())->getSubmission($submissionId);
            $subscriptionId = Arr::get($transaction, 'subscription_id', '');
            $subscription = (new Subscription())->getSubscription($subscriptionId);
            // Process refund with payment method
            $actionPaymentMethod = $refundService->processPaymentMethodRefund($transaction, $payload, $subscription, $submission);

            // Create refund transaction record
            if ($actionPaymentMethod) {
                $refundTransaction = $refundService->createRefundTransaction($transaction, $formId, $submissionId, $payload, $refundData);
                return $this->response->json([
                    'message' => 'Refund processed successfully',
                    'refund' => $refundTransaction,
                ]);
            } else {
                return $this->response->json(['error' => 'Refund processing failed'], 500);
            }

        } catch (RefundException $e) {
            return $this->response->json(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (\Exception $e) {
            return $this->response->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Extract and sanitize the refund payload from the request
     * 
     * @return array The sanitized and structured payload
     * @throws RefundException If payload is missing or invalid
     */
    private function extractRefundPayload(): array
    {
        // Get payload and ensure it's an array
        $rawPayload = (array) $this->request->get('payload', []);

        // Sanitize payload
        $payload = $this->sanitizePayload($rawPayload);
        $amount = (float)Arr::get($payload, 'amount', 0);
        // Delegate amount validation to service
        (new RefundService)->validateRefundAmount($amount);

        return [
            'transaction_id' => (int)Arr::get($payload, 'transaction_id', 0),
            'form_id' => (int)Arr::get($payload, 'form_id', 0),
            'submission_id' => (int)Arr::get($payload, 'submission_id', 0),
            'amount' => $amount,
            'reason' => Arr::get($payload, 'reason', ''),
            'transaction_type' => Arr::get($payload, 'transaction_type', ''),
            'cancel_Subscription' => (bool) Arr::get($payload, 'cancel_Subscription', false),
            'amount_in_cents' => (int)($amount * 100)
        ];
    }

    private function sanitizePayload(array $payload): array
    {
        if (empty($payload)) {
            throw new RefundException('Missing refund data', 422);
        }     
        // Special handling for boolean fields
        if (isset($payload['cancel_Subscription'])) {
            $payload['cancel_Subscription'] = filter_var($payload['cancel_Subscription'], FILTER_VALIDATE_BOOLEAN);
        }
        
        // Sanitize text fields in-place
        foreach ($payload as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $payload[$key] = sanitize_text_field(wp_unslash($value));
            }
        }
        
        return $payload;
    }

	/**
	 * @throws RefundException
	 */
	private function validateUserAndSubmission(RefundService $refundService, int $submissionId): void
	{
        $currentUser = get_current_user_id();
        $submission = Submission::find($submissionId);
        
        if (!$submission) {
            throw new RefundException('Submission not found', 404);
        }
        
        $refundService->validateUserAccess($submission, $currentUser);
    }

	/**
	 * @throws RefundException
	 */
	private function calculateRefundAmounts(RefundService $refundService, Transaction $transaction,$amountInCents): array
    {
        $refundAvailable = $transaction->refund_available;
        $refundService->validateRefundAvailability($amountInCents, $refundAvailable);
        
        return [
            'remaining_amount' => $refundAvailable - $amountInCents
        ];
    }

}
