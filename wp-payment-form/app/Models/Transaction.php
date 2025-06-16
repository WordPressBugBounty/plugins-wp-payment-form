<?php

namespace WPPayForm\App\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 *  Transaction Model
 * @since 1.0.0
 */
class Transaction extends Model
{
    protected $table = 'wpf_order_transactions';

    protected $appends = ['refund_available'];

    public function getRefundAvailableAttribute()
    {
        $meta = Meta::where('option_id', $this->id)
            ->where('meta_key', 'refund_available')
            ->first();
        // if status is paid only then return payment_total
        if ($this->status !== 'paid') {
            return 0;
        }
        return $meta ? $meta->meta_value : $this->payment_total;
    }

    public function createTransaction($item)
    {
        if (!isset($item['transaction_type'])) {
            $item['transaction_type'] = 'one_time';
        }

        return static::create($item);
    }

    public function getTransactions($submissionId)
    {
        $transactions = static::where('submission_id', $submissionId)
            ->where('transaction_type', 'one_time')
            ->get();

        $submission = Submission::select('payment_method')->where('id', $submissionId)->first();
        return apply_filters('wppayform/entry_transactions_' . $submission->payment_method, $transactions, $submissionId);
    }

    public function getTransaction($transactionId)
    {
        return static::where('id', $transactionId)
            ->where('transaction_type', 'one_time')
            ->first();
    }

    public function getTransactionByChargeId($chargeId)
    {
        return static::where('charge_id', $chargeId)
            ->where('transaction_type', 'one_time')
            ->first();
    }

    public function updateTransaction($transactionId, $data)
    {
        $data['updated_at'] = current_time('mysql');
        return static::where('id', $transactionId)->update($data);
    }

    public function getLatestTransaction($submissionId)
    {
        return static::where('submission_id', $submissionId)
            ->where('transaction_type', 'one_time')
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function getLatestIntentedTransaction($submissionId)
    {
        return static::where('submission_id', $submissionId)
            ->where('status', 'intented')
            ->where('transaction_type', 'one_time')
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function getValidatedTransaction($submissionId, $transactionId, $formId)
    {
        return static::where('id', $transactionId)
            ->where('submission_id', $submissionId)
            ->where('form_id', $formId)
            ->where('status', '!=', 'draft')
            ->first();
    }
}
