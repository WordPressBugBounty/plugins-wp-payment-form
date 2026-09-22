<?php

namespace WPPayForm\App\Models;

use DateTime;

use WPPayForm\App\Models\Model;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\Subscription;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\Reports;
use WPPayForm\App\Models\SubscriptionTransaction;
use WPPayForm\App\Models\Meta;

use WPPayForm\Framework\Support\Arr;
use WPPayForm\Framework\Foundation\App;
use WPPayForm\App\Services\GeneralSettings;


class Customers extends Model
{
    public function index($request)
    {
        $queries = $request->get('queries');
        $perPage = absint(Arr::get($queries, 'pageSize', 0));
        $currentPage = absint(Arr::get($queries, 'currentPage', 1)) ;
        $offset = ($currentPage - 1) * $perPage;

        $sortType = sanitize_text_field(Arr::get($queries, 'sort_type', 'desc'));
        $sortBy = sanitize_text_field(Arr::get($queries, 'sort_by', 'created_at'));
        $search = sanitize_text_field(Arr::get($queries, 'search', ''));

        $filter_date = $request->get('filter_date');
        
        $startDate = sanitize_text_field(Arr::get($filter_date, 'startDate'));
        $endDate = sanitize_text_field(Arr::get($filter_date, 'endDate'));

        $endDate = $endDate ? $endDate . ' 23:59:59' : null;

        $DB = App::make('db');

        $query = Submission::select(
            'customer_email',
            'customer_name',
            'created_at',
            $DB->raw("DATE_FORMAT(created_at, '%d-%M-%Y') as created"),
            $DB->raw("COUNT(*) as submissions"),
            $DB->raw("DATEDIFF(CURDATE(), created_at) as date")
        )
            ->groupBy(['customer_email'])
            ->orderBy($sortBy, $sortType);
      
        $query->when($search, function ($query) use ($search) {
            global $wpdb;
            $safeSearch = $wpdb->esc_like($search);
            return $query->where('customer_email', 'like', "%{$safeSearch}%")
                ->orWhere('customer_name', 'like', "%{$safeSearch}%");
        });
        
        $query->when($startDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        });

        $totalCustomers = $query->get()->count();

        if ($perPage) {
            $query->limit($perPage);
        }
        if ($offset) {
            $query->offset($offset);
        }

        $customers = $query->get();

        $pageEmails = [];
        foreach ($customers as $c) {
            if (!empty($c->customer_email)) {
                $pageEmails[] = $c->customer_email;
            }
        }
        $migratedEmails = $this->getGiveWPMigratedEmails($pageEmails);

        $wordpressDate = current_time('Y-m-d H:i:s');
        foreach ($customers as $customer) {
            $customer->avatar = get_avatar($customer->customer_email, 128);
            $customer->is_givewp_migrated = isset($migratedEmails[$customer->customer_email]);
            // Calculate "x hours y minutes ago"
            $created = new DateTime($customer->created_at);
            $now = new DateTime($wordpressDate);
            $diff = $now->diff($created);
            $parts = [];

            if ($diff->days > 0) {
                $parts[] = $diff->days . ($diff->days == 1 ? ' day' : ' days');
            }
            if ($diff->h > 0) {
                $parts[] = $diff->h . ($diff->h == 1 ? ' hour' : ' hours');
            }
            if ($diff->i > 0) {
                $parts[] = $diff->i . ($diff->i == 1 ? ' minute' : ' minutes');
            }

            $customer->time_ago = empty($parts) ? 'just now' : implode(' ', $parts);
        }

        return array(
            'customers' => $customers,
            'total' => $totalCustomers
        );
    }

    private function getGiveWPMigratedEmails(array $emails): array
    {
        if (empty($emails)) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($emails), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT s.customer_email
                 FROM {$wpdb->prefix}wpf_submissions s
                 INNER JOIN {$wpdb->prefix}wpf_meta wm ON wm.option_id = s.id
                 WHERE s.customer_email IN ($placeholders)
                   AND wm.meta_key = %s
                   AND wm.meta_group = %s",
                array_merge($emails, ['give_source_donation_id', 'wpf_submissions'])
            )
        );

        return array_fill_keys((array) $rows, true);
    }

    /**
     * Get customer details
     * @param string $customerEmail
     * @param string $apiCall default 'no', 'Yes' will return the response in array format instead of object
     * @return array
     */
    public function customer($customerEmail, $apiCall = 'no')
    {
        $customerEmail = sanitize_email($customerEmail);
        $customer = Submission::where('customer_email', $customerEmail)
            ->orderBy('id', 'desc')
            ->get();

        if ($customer->isEmpty()) {
            return null;
        }

        $payload = $this->buildCustomerPayload($customer, $customerEmail, $apiCall);

        if ($apiCall == 'yes') {
            return array(
                'entries'       => $customer->toArray(),
                'info'          => $payload['info']->toArray(),
                'subscriptions' => $payload['subscriptions'],
                'orders'        => $payload['orders'],
            );
        }

        return array(
            'entries'       => $customer,
            'info'          => $payload['info'],
            'subscriptions' => $payload['subscriptions'],
            'orders'        => $payload['orders'],
        );
    }

    /**
     * Get customer details for the front-end dashboard with server-side pagination.
     *
     * Uses a hybrid ownership filter: returns submissions owned by the given WP
     * user_id OR guest submissions (user_id = 0 or NULL) whose email matches — so a
     * customer who submitted as a guest before registering sees their full history.
     *
     * Pagination applies to the Entries tab only. Subscriptions are batch-loaded from
     * all submission IDs (up to 500) so the Subscription tab is never fragmented by
     * page boundaries. All data fetched in 7 queries regardless of submission count.
     *
     * Called from: wp-payment-form-pro/src/Classes/Dashboard/Render.php::getAllSubmissions()
     *
     * @param string $customerEmail
     * @param int    $userId    Current WP user ID — must be > 0; returns null for guests.
     * @param int    $page      1-based page number (default 1).
     * @param int    $perPage   Rows per page, capped at 50 (default 20).
     * @return array|null  Keys: entries, info, subscriptions, orders, total, per_page, current_page.
     */
    public function customerForDashboard($customerEmail, $userId, $page = 1, $perPage = 20, $statusFilter = null)
    {
        $customerEmail = sanitize_email($customerEmail);
        $userId        = absint($userId);
        $page          = max(1, absint($page));
        $perPage       = max(1, min(50, absint($perPage)));

        if ($userId < 1) {
            return null;
        }

        // Closure reused across all three ownership queries below.
        // Guest submissions may store user_id as 0 (native forms) or NULL (GiveWP migration).
        $ownershipFilter = function ($q) use ($userId, $customerEmail) {
            $q->where('user_id', $userId)
              ->orWhere(function ($q2) use ($customerEmail) {
                  $q2->where(function ($q3) {
                          $q3->where('user_id', 0)->orWhereNull('user_id');
                      })
                     ->where('customer_email', $customerEmail);
              });
        };

        // Normalize status filter: null / 'all' / empty → no filter; string → wrap in array; array → use as-is.
        if (is_string($statusFilter) && $statusFilter !== '' && $statusFilter !== 'all') {
            $statusFilter = [$statusFilter];
        }
        $allowedStatusValues = ['paid', 'pending', 'failed', 'refunded'];
        $statuses = (is_array($statusFilter) && !empty($statusFilter))
            ? array_values(array_intersect(array_map('sanitize_text_field', $statusFilter), $allowedStatusValues))
            : null;
        if (empty($statuses)) {
            $statuses = null;
        }

        $total = Submission::where($ownershipFilter)
            ->when($statuses, function ($q) use ($statuses) {
                $q->whereIn('payment_status', $statuses);
            })
            ->count();

        if (!$total) {
            return null;
        }

        // Cap to the actual last page so an out-of-bounds ?wpf_page never returns null.
        $lastPage = (int) ceil($total / $perPage);
        $page     = min($page, max(1, $lastPage));

        // All submission IDs (bounded to 500) for subscription batch loading.
        // Subscriptions must not be fragmented by page boundaries — a subscription
        // may belong to a submission that is not on the current entries page.
        $maxSubIds = absint(apply_filters('wppayform/dashboard_max_subscription_ids', 500));
        $allSubRows = Submission::select('id')
            ->where($ownershipFilter)
            ->when($statuses, function ($q) use ($statuses) {
                $q->whereIn('payment_status', $statuses);
            })
            ->orderBy('id', 'desc')
            ->limit($maxSubIds)
            ->get();
        $allSubmissionIds = [];
        foreach ($allSubRows as $row) {
            $allSubmissionIds[] = absint($row->id);
        }
        $submissionIdsCapped = ($total > $maxSubIds);

        // Compute total one-time orders using an uncapped ownership-filter query so
        // customers with > $maxSubIds submissions get an accurate count rather than a
        // cap-bounded one. When total <= $maxSubIds, $allSubmissionIds is already the
        // full set, so we skip the extra query.
        $orderCounter = new Transaction();
        $totalOrders  = 0;
        if ($submissionIdsCapped) {
            // Subquery keeps all IDs server-side; avoids loading unbounded rows into PHP.
            $totalOrders = $orderCounter
                ->whereIn('submission_id', Submission::select('id')->where($ownershipFilter)->when($statuses, function ($q) use ($statuses) {
                    $q->whereIn('payment_status', $statuses);
                }))
                ->where('transaction_type', 'one_time')
                ->count();
        } elseif (!empty($allSubmissionIds)) {
            $totalOrders = $orderCounter
                ->whereIn('submission_id', $allSubmissionIds)
                ->where('transaction_type', 'one_time')
                ->count();
        }

        // Aggregate payment_total by currency across ALL filtered submissions so the
        // dashboard's total-spent figure is accurate regardless of the current page.
        $DB = App::make('db');
        $paymentTotalByCurrency = [];
        $rawTotals = Submission::select(
            'currency',
            $DB->raw("SUM(payment_total) as total")
        )
            ->where($ownershipFilter)
            ->when($statuses, function ($q) use ($statuses) {
                $q->whereIn('payment_status', $statuses);
            })
            ->groupBy('currency')
            ->get();
        foreach ($rawTotals as $row) {
            $paymentTotalByCurrency[(string) $row->currency] = absint($row->total);
        }

        // Paginated entries for the Entries tab.
        $offset = ($page - 1) * $perPage;
        $pagedSubmissions = Submission::where($ownershipFilter)
            ->when($statuses, function ($q) use ($statuses) {
                $q->whereIn('payment_status', $statuses);
            })
            ->orderBy('id', 'desc')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $payload = $this->buildCustomerPayloadBatched($pagedSubmissions, $allSubmissionIds, $customerEmail, $totalOrders);

        return array(
            'entries'                         => $pagedSubmissions,
            'info'                            => $payload['info'],
            'subscriptions'                   => $payload['subscriptions'],
            'orders'                          => $payload['orders'],
            'total_orders'                    => $payload['totalOrders'],
            'total'                           => $total,
            'per_page'                        => $perPage,
            'current_page'                    => $page,
            'last_page'                       => $lastPage,
            'submission_ids_capped'           => $submissionIdsCapped,
            'subscriptions_history_truncated'       => $payload['subscriptionsHistoryTruncated'],
            'subscription_transactions_truncated'   => $payload['subscriptionTransactionsTruncated'],
            'payment_total_by_currency'       => $paymentTotalByCurrency,
        );
    }

    /**
     * Build subscription/order/info payload using batch-loaded data.
     *
     * Four batch queries (called context adds 3 more = 7 total) — no per-row DB calls:
     *   1. Subscriptions for all submission IDs (capped at wppayform/dashboard_max_subscriptions).
     *   2. Subscription transactions for those subscriptions.
     *   3. refund_available meta for all subscription transactions (1 query, replaces accessor N+1).
     *   4. One-time order transactions for the current-page submission IDs only.
     *
     * $totalOrders is pre-computed by customerForDashboard() using an uncapped ownership
     * filter and passed in so customers with >500 submissions get an accurate count.
     *
     * Used exclusively by customerForDashboard(). The original buildCustomerPayload()
     * remains unchanged for the admin-side customer() path.
     *
     * @param  mixed  $pagedSubmissions  WPFluent Collection — current-page Submission rows.
     * @param  array  $allSubmissionIds  All submission IDs for this user (bounded to 500).
     * @param  string $customerEmail
     * @param  int    $totalOrders       Pre-computed total one-time order count (uncapped).
     * @return array  Keys: subscriptions, orders, info, totalOrders, subscriptionsHistoryTruncated, subscriptionTransactionsTruncated.
     */
    private function buildCustomerPayloadBatched($pagedSubmissions, $allSubmissionIds, $customerEmail, $totalOrders = 0)
    {
        $subscriptionTransactionModel = new SubscriptionTransaction();
        $subscriptionModel            = new Subscription();
        $orderInstance                = new Transaction();

        // Build paged submission ID set and map for entry/order assembly.
        $pagedSubmissionIds = [];
        $submissionMap      = [];
        foreach ($pagedSubmissions as $item) {
            $id                 = absint($item->id);
            $pagedSubmissionIds[] = $id;
            $submissionMap[$id]  = $item;
        }

        // --- Batch-load subscriptions from ALL submission IDs (1 query, capped) ---
        // Using $allSubmissionIds ensures subscriptions from off-page submissions
        // are still included in the Subscription tab. Capped at $maxSubs (default 200)
        // to bound memory cost for customers with deep history; truncation is
        // signalled via $subscriptionsHistoryTruncated in the return value.
        $maxSubs = absint(apply_filters('wppayform/dashboard_max_subscriptions', 200));
        $subscriptionsHistoryTruncated = false;
        $subscriptionsBySubmissionId = [];
        $allSubIds                   = [];
        if (!empty($allSubmissionIds)) {
            $rawSubscriptions = $subscriptionModel
                ->whereIn('submission_id', $allSubmissionIds)
                ->orderBy('id', 'desc')
                ->limit($maxSubs + 1)
                ->get();

            $subscriptionsHistoryTruncated = ($rawSubscriptions->count() > $maxSubs);
            $processedCount = 0;
            foreach ($rawSubscriptions as $sub) {
                if ($processedCount >= $maxSubs) {
                    break; // Discard the sentinel row used for truncation detection.
                }
                $sub->original_plan   = wppayform_safeUnserialize($sub->original_plan);
                $sub->vendor_response = wppayform_safeUnserialize($sub->vendor_response);
                $subSubmissionId = absint($sub->submission_id);
                $subscriptionsBySubmissionId[$subSubmissionId][] = $sub;
                $allSubIds[] = absint($sub->id);
                $processedCount++;
            }
        }

        // --- Batch-load subscription transactions (1 query) ---
        // --- Batch-load refund_available meta for all transactions (1 query) ---
        // Resolves the value that SubscriptionTransaction::getRefundAvailableAttribute() would
        // compute per-row, stamps it directly onto the model, and disables the accessor so
        // downstream toArray() calls (including pro's serialization) never fire a Meta query.
        $relatedPaymentsBySubId           = [];
        $subPaymentArraysBySubId          = [];
        $subscriptionTransactionsTruncated = false;
        if (!empty($allSubIds)) {
            $maxTrxRows = absint(apply_filters('wppayform/dashboard_max_subscription_transactions', 500));
            $rawSubTransactions = $subscriptionTransactionModel
                ->whereIn('subscription_id', $allSubIds)
                ->orderBy('id', 'desc')
                ->limit($maxTrxRows + 1)
                ->get();

            $subscriptionTransactionsTruncated = ($rawSubTransactions->count() > $maxTrxRows);

            // Collect transaction IDs for the refund meta batch query (discard sentinel).
            $allTrxIds          = [];
            $processedTrxCount  = 0;
            $allSubTransactions = [];
            foreach ($rawSubTransactions as $trx) {
                if ($processedTrxCount >= $maxTrxRows) {
                    break;
                }
                $allSubTransactions[] = $trx;
                $allTrxIds[]          = absint($trx->id);
                $processedTrxCount++;
            }

            // One query replaces N*getRefundAvailableAttribute() calls.
            $refundMetaMap = [];
            if (!empty($allTrxIds)) {
                $refundMetas = (new Meta)->whereIn('option_id', $allTrxIds)
                    ->where('meta_key', 'refund_available')
                    ->get();
                foreach ($refundMetas as $rm) {
                    $refundMetaMap[absint($rm->option_id)] = $rm->meta_value;
                }
            }

            foreach ($allSubTransactions as $trx) {
                $subId = absint($trx->subscription_id);
                $trxId = absint($trx->id);

                // Stamp resolved value directly; disable accessor permanently.
                $trx->setAttribute(
                    'refund_available',
                    ($trx->status !== 'paid') ? 0 : ($refundMetaMap[$trxId] ?? $trx->payment_total)
                );
                $trx->setAppends([]);

                $subPaymentArraysBySubId[$subId][] = $trx->toArray();
                $trx->payment_note = wppayform_safeUnserialize($trx->payment_note);
                $trx->items = apply_filters('wppayform/subscription_items_' . $trx->payment_method, [], $trx);
                $relatedPaymentsBySubId[$subId][] = $trx;
            }

            // Wrap in Collection to match getSubscriptionTransactions() return type contract.
            foreach ($relatedPaymentsBySubId as $subId => $trxList) {
                $relatedPaymentsBySubId[$subId] = apply_filters(
                    'wppayform/subscription_transactions',
                    new \WPPayForm\Framework\Support\Collection($trxList),
                    $subId
                );
            }
        }

        // --- Batch-load one-time order transactions for the current page only (1 query) ---
        $ordersBySubmissionId = [];
        if (!empty($pagedSubmissionIds)) {
            $allOrders = $orderInstance->whereIn('submission_id', $pagedSubmissionIds)
                ->where('transaction_type', 'one_time')
                ->get();
            foreach ($allOrders as $order) {
                $ordersBySubmissionId[absint($order->submission_id)][] = $order;
            }
        }

        // --- Assemble entries (current page only) ---
        $orders = [];

        foreach ($pagedSubmissions as $item) {
            $item->total_subscription_payment = apply_filters('wppayform/form_entry_recurring_info', $item);
            $item->form_data_raw       = wppayform_safeUnserialize($item->form_data_raw);
            $item->form_data_formatted = wppayform_safeUnserialize($item->form_data_formatted);

            $item->has_subscription = isset($subscriptionsBySubmissionId[$item->id])
                && count($subscriptionsBySubmissionId[$item->id]) > 0;

            $currencySettings = Form::getCurrencySettings($item->form_id);
            $currencySettings['currency_sign'] = GeneralSettings::getCurrencySymbol($item['currency']);
            $item->currency_settings = $currencySettings;

            $itemOrders = isset($ordersBySubmissionId[$item->id])
                ? $ordersBySubmissionId[$item->id]
                : [];
            $itemOrders = apply_filters(
                'wppayform/entry_transactions_' . $item->payment_method,
                new \WPPayForm\Framework\Support\Collection($itemOrders),
                $item->id
            );
            if (count($itemOrders)) {
                foreach ($itemOrders as $order) {
                    $orders[] = $order;
                }
                $item->order_items = $itemOrders;
            }
        }

        // --- Assemble subscriptions from ALL loaded subs (full history, not page-limited) ---

        // Batch-load currency for subscription parent submissions that are NOT on the current
        // entries page. Without this, off-page parents resolve to an empty currency code,
        // producing a missing or incorrect currency symbol on those subscription rows.
        $submissionCurrencyMap = [];
        foreach ($submissionMap as $mapId => $mapSub) {
            $submissionCurrencyMap[absint($mapId)] = (string) $mapSub['currency'];
        }
        $subscriptionParentIds = array_keys($subscriptionsBySubmissionId);
        $offPageParentIds      = array_diff($subscriptionParentIds, array_keys($submissionMap));
        if (!empty($offPageParentIds)) {
            $offPageRows = Submission::select('id', 'currency')
                ->whereIn('id', array_values($offPageParentIds))
                ->get();
            foreach ($offPageRows as $offRow) {
                $submissionCurrencyMap[absint($offRow->id)] = (string) $offRow->currency;
            }
        }

        $subscriptions = [];

        foreach ($subscriptionsBySubmissionId as $subSubmissionId => $itemSubs) {
            // $submissionMap only contains the current page; off-page parents return null here.
            // Pro's getSubscriptionItems() overwrites $sub['submission'] with getSubmissionPrepared()
            // so this null is never surfaced to the template.
            $parentSubmission = isset($submissionMap[$subSubmissionId])
                ? $submissionMap[$subSubmissionId]
                : null;
            $parentCurrency   = $submissionCurrencyMap[$subSubmissionId] ?? '';

            foreach ($itemSubs as $sub) {
                $subCurrencySettings = Form::getCurrencySettings($sub['form_id']);
                $subCurrencySettings['currency_sign'] = GeneralSettings::getCurrencySymbol($parentCurrency);
                $sub['currency_settings'] = $subCurrencySettings;
                $sub['submission']        = $parentSubmission;

                $subId = absint($sub->id);
                $sub['related_payments'] = isset($relatedPaymentsBySubId[$subId])
                    ? $relatedPaymentsBySubId[$subId]
                    : [];

                $subPaymentData  = isset($subPaymentArraysBySubId[$subId]) ? $subPaymentArraysBySubId[$subId] : [];
                $transactionData = array_merge($subPaymentData[0] ?? [], [
                    'currency_settings' => $sub['currency_settings'],
                ]);
                $sub['subscription_payments'] = ['transactions' => $transactionData];
                $subscriptions[] = $sub;
            }
        }

        $info = $pagedSubmissions->first();
        $info->fluent_crm = apply_filters('wppayform_customer_profile', '', $customerEmail);
        $info->avatar = get_avatar($customerEmail, 128);

        return compact('subscriptions', 'orders', 'info', 'totalOrders', 'subscriptionsHistoryTruncated', 'subscriptionTransactionsTruncated');
    }

    /**
     * Build subscription/order/info payload from a loaded customer collection.
     * Shared by customer() and customerForDashboard() — keep in sync with their callers.
     */
    private function buildCustomerPayload($customer, $customerEmail, $apiCall = 'no')
    {
        $subscriptionTransactionModel = new SubscriptionTransaction();
        $subscriptionModel = new Subscription();
        $submission = new Submission();
        $orderInstance = new Transaction();

        $subscriptions = [];
        $orders = [];

        foreach ($customer as $item) {
            $item->total_subscription_payment = apply_filters('wppayform/form_entry_recurring_info', $item);
            $item->form_data_raw = wppayform_safeUnserialize($item->form_data_raw);
            $item->form_data_formatted = wppayform_safeUnserialize($item->form_data_formatted);

            $subscription = $subscriptionModel->getSubscriptions($item->id);
            $item->has_subscription = count($subscription) > 0 ? true : false;

            $currencySettings = Form::getCurrencySettings($item->form_id);
            $currencySettings['currency_sign'] = GeneralSettings::getCurrencySymbol($item['currency']);
            $item->currency_settings = $currencySettings;

            if (count($subscription)) {
                foreach ($subscription as $sub) {
                    $currencySettings = Form::getCurrencySettings($sub['form_id']);
                    $currencySettings['currency_sign'] = GeneralSettings::getCurrencySymbol($item['currency']);
                    $sub['currency_settings'] = $currencySettings;
                    $sub['submission'] = $submission->getSubmission($sub['submission_id']);
                    $sub['related_payments'] = $subscriptionTransactionModel->getSubscriptionTransactions($sub->id);
                    $subscriptionData = $subscriptionTransactionModel->getSubscriptionTransactionsBySubmissionId($sub->id);
                    $transactionData = array_merge($subscriptionData[0] ?? [], [
                        'currency_settings' => $sub['currency_settings'],
                    ]);
                    $sub['subscription_payments'] = [
                        'transactions' => $transactionData,
                    ];
                    $subscriptions[] = ($apiCall == 'yes') ? $sub->toArray() : $sub;
                }
            }

            $orderItems = $orderInstance->getTransactions($item['id']);
            if (count($orderItems)) {
                foreach ($orderItems as $order) {
                    $orders[] = ($apiCall == 'yes') ? $order->toArray() : $order;
                }
                $item->order_items = $orderItems;
            }
        }

        $info = $customer->last();
        $info->fluent_crm = apply_filters('wppayform_customer_profile', '', $customerEmail);
        $info->avatar = get_avatar($customerEmail, 128);

        return compact('subscriptions', 'orders', 'info');
    }

    public function getCustomerTransactions($email)
    {
        $DB = App::make('db');
        $customers = Submission::select(
            'currency',
            'customer_email',
            'customer_name',
            $DB->raw("SUM(payment_total) as total_paid"),
        )
            ->whereIn('payment_status', ['paid'])
            ->where('payment_total', '>', 0)
            ->where('customer_email', $email)
            ->groupBy(['currency'])
            ->get();

        return $customers;
    }

    public function customerProfile($email)
    {
        $permissions = array(
            'roles' => [],
            'user_id' => 0,
            'display_name' => '',
            'manage_user' => false,
        );

        $user = get_user_by('email', $email);

        if ($user) {
            $permissions['roles'] = $user->roles;
            $permissions['user_id'] = $user->ID;
            $permissions['display_name'] = $user->display_name;
            $permissions['manage_user'] = current_user_can('edit_user', $user->ID) ? admin_url("user-edit.php?user_id=$user->ID") : false;
        }

        $spends = $this->getCustomerTransactions($email);

        foreach ($spends as $spend) {
            $spend->sign = GeneralSettings::getCurrencySymbol($spend->currency);
            $spend->formatted_price = (int) $spend["total_paid"] / 100;
        }

        return array(
            'spends' => $spends,
            'permissions' => $permissions
        );
    }

    public function customerEngagements($email)
    {
        $DB = App::make('db');
        $customers = Submission::select(
            'posts.post_title',
            'wpf_submissions.id',
            'wpf_submissions.form_id',
            'wpf_submissions.user_id',
            $DB->raw("DATE_FORMAT(created_at, '%d-%M-%Y') as created"),
            $DB->raw("COUNT(*) as submission")
        )
            ->where('wpf_submissions.customer_email', $email)
            ->groupBy(['wpf_submissions.form_id'])
            ->orderBy('wpf_submissions.id', 'desc')
            ->join('posts', 'posts.ID', '=', 'wpf_submissions.form_id')
            ->get();

        $graphicalData = (new Reports())->getRecentRevenue($email);

        return array(
            'customers' => $customers,
            'graphicalData' => $graphicalData
        );
    }

    public function getAvatar($email, $size)
    {
        $hash = md5(strtolower(trim($email)));

        /**
         * Gravatar URL by Email
         *
         * @return HTML $gravatar img attributes of the gravatar image
         */
        return apply_filters('wppayform_get_avatar',
            "https://www.gravatar.com/avatar/$hash?s=$size&d=mm&r=g",
            $email
        );
    }

    public function CustomerReports($filter_data, $groupBy = null, $pay_status = ['paid'])
    {
        $duration = Arr::get($filter_data, 'duration');
        $startDate = Arr::get($filter_data, 'start_date');
        $endDate = Arr::get($filter_data, 'end_date');

        $beforeDate = Arr::get($filter_data, 'beforeDate');
        $beforeTwoTimeDate = Arr::get($filter_data, 'beforeTwoTimeDate');

        $newCustomers = 0;
        $getBeforeDateData = 0;
        $newCustomerPercentage = 0;

        if($duration && $duration != 'All') {
        
            $newCustomers = $this->getCustomerByDuration($beforeDate, $groupBy, null, null, null, $pay_status);
            $getBeforeDateData = $this->getCustomerByDuration($beforeDate, $groupBy, $beforeTwoTimeDate, null, null, $pay_status);
            $newCustomerPercentage = self::getDiff($getBeforeDateData, $newCustomers);
        }

        $totalCustomers = $this->getCustomerByDuration(null, $groupBy, null, $startDate, $endDate, $pay_status);

        return array(
            'totalCustomers' => $totalCustomers,
            'newCustomerPercentage' => $newCustomerPercentage
        );
    }

    public function getCustomerByDuration($beforeDate = null, $groupBy = null, $beforeTwoTimeDate = null, $startDate = null, $endDate = null, $pay_status = ['paid'])
    {
        return Submission::select(
            'customer_email',
            'created_at'
        )
            ->whereIn('payment_status', $pay_status)
            // ->where('payment_total', '>', 0)
            ->when($groupBy, function ($query) use ($groupBy) {
                return $query->groupBy($groupBy);
            })
            ->when($beforeDate, function ($query) use ($beforeDate, $beforeTwoTimeDate) {
                if($beforeTwoTimeDate)
                    return $query->whereBetween('created_at', [$beforeTwoTimeDate, $beforeDate]);
                else
                    return $query->where('created_at', '>=', $beforeDate);
            })
            ->when($startDate, function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('created_at', [$startDate, $endDate]);
            })
            ->get()->count();
    }

    public static function getDiff($last, $new)
    {
        if ($last == 0) {
            return 100;
        } else if($new == 0) {
            return -100;
        } else {
            return round((($new - $last) / $last) * 100);
        }
    }

}