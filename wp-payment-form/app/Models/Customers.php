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

use WPPayForm\Framework\Support\Arr;
use WPPayForm\Framework\Foundation\App;
use WPPayForm\App\Services\GeneralSettings;


class Customers extends Model
{
    public function index($request)
    {
        $queries = $request->get('queries');

        $perPage = Arr::get($queries, 'pageSize', 0);
        $currentPage = Arr::get($queries, 'currentPage', 1);
        $offset = ($currentPage - 1) * $perPage;

        $sortType = Arr::get($queries, 'sort_type', 'desc');
        $sortBy = Arr::get($queries, 'sort_by', 'created_at');
        $search = Arr::get($queries, 'search', '');

        $filter_date = $request->get('filter_date');
        
        $startDate = Arr::get($filter_date, 'startDate');
        $endDate = Arr::get($filter_date, 'endDate');
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
            return $query->where('customer_email', 'like', "%$search%")
                ->orWhere('customer_name', 'like', "%$search%");
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
        
        foreach ($customers as $customer) {
            $customer->avatar = get_avatar($customer->customer_email, 128);
        }

        return array(
            'customers' => $customers,
            'total' => $totalCustomers
        );
    }

    /**
     * Get customer details
     * @param string $customerEmail
     * @param string $apiCall default 'no', 'Yes' will return the response in array format instead of object
     * @return array
     */
    public function customer($customerEmail, $apiCall = 'no')
    {
        $subscriptionTransactionModel = new SubscriptionTransaction();
        $query = Submission::where('customer_email', $customerEmail)
            ->orderBy('id', 'desc');

        $customer = $query->get();

        if (count($customer->toArray()) < 1) {
            return null;
        }

        $subscriptionModel = new Subscription();
        $submission = new Submission();
        $orderInstance = new Transaction();

        $subscriptions = [];
        $orders = [];

        foreach ($customer as $item) {
            $item->total_subscription_payment = apply_filters('wppayform/form_entry_recurring_info', $item);
            $item->form_data_raw = safeUnserialize($item->form_data_raw);
            $item->form_data_formatted = safeUnserialize($item->form_data_formatted);
            $formattedResults[] = $item;

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
                    // $sub['subscription_payment_total'] = $sub['related_payments']->SUM('payment_total');
                    if ($apiCall == 'yes') {
                        $subscriptions[] = $sub->toArray();
                    } else {
                        $subscriptions[] = $sub;
                    }
                }
            }

            $orderItems = $orderInstance->getTransactions($item['id']);
            if (count($orderItems)) {
                foreach ($orderItems as $order) {
                    if ($apiCall == 'yes') {
                        $orders[] = $order->toArray();
                    } else {
                        $orders[] = $order;
                    }
                }
                $item->order_items = $orderItems;
            }
        }

        $info = $customer->last();
        $info->fluent_crm = apply_filters('wppayform_customer_profile', '', $customerEmail);
        $info->avatar = get_avatar($customerEmail, 128);

        if ($apiCall == 'yes') {
            return array(
                'entries' => $customer->toArray(),
                'info' => $info->toArray(),
                'subscriptions' => $subscriptions,
                'orders' => $orders
            );
        }
 
        return array(
            'entries' => $customer,
            'info' => $info,
            'subscriptions' => $subscriptions,
            'orders' => $orders
        );
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
            $spend->formatted_price = floatval($spend["total_paid"]) / 100;
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