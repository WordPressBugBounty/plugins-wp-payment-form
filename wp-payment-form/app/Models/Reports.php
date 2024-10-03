<?php

namespace WPPayForm\App\Models;

use WPPayForm\App\Models\Model;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Submission;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\Framework\Foundation\App;
use WPPayForm\App\Services\GeneralSettings;


if (!defined('ABSPATH')) {
    exit;
}

/**
 *  Refund Model
 * @since 2.0.0
 */
class Reports extends Model
{

    public static function getDurationDate($duration)
    {
        $beforeDate = '';
        $beforeTwoTimeDate = '';
        switch ($duration) {
            case 'last_7_days':
                $beforeDate = date('Y-m-d', strtotime('-7 days'));
                $beforeTwoTimeDate = date('Y-m-d', strtotime('-14 days'));
                break;
            case 'last_30_days':
                $beforeDate = date('Y-m-d', strtotime('-30 days'));
                $beforeTwoTimeDate = date('Y-m-d', strtotime('-60 days'));
                break;
            case 'this_year':
                $beforeDate = date('Y-m-d', strtotime('-365 days'));
                $beforeTwoTimeDate = date('Y-m-d', strtotime('-730 days'));
                break;
            default:
                $beforeDate = '';
                $beforeTwoTimeDate = '';
                break;
        }

        return array(
            'beforeDate' => $beforeDate,
            'beforeTwoTimeDate' => $beforeTwoTimeDate,
        );
    }
    public function getReports()
    {
        $filter_data = $this->sanitizedGetReports(Arr::get($_REQUEST, 'filter', []));
        $periodicalStats = Form::getStatus($filter_data);
        $stats = Arr::get($periodicalStats, 'stats', []);
        
        $paidStats = Arr::get($periodicalStats, 'paidStats', []);
        $customer = Submission::getByCustomerEmail();
   
        $customerProfiles = [];
        foreach ($customer as $value) {
            $email = $value['customer_email'];
            $customerProfiles[$email]['count'] = isset($customerProfiles[$email]['count']) ? $customerProfiles[$email]['count'] : 0;
            $customerProfiles[$email]['items'][] = array(
                "currency" => $value["currency"],
                "total_paid" => floatval($value["total_paid"]) / 100,
                "submissions" => $value["submissions"]
            );
            $customerProfiles[$email]['name'] = $value["customer_name"];
            $customerProfiles[$email]['avatar'] = get_avatar_url($email);
            $customerProfiles[$email]['count'] += intval($value['submissions']);
        }

        $chartData = array(
            'labels' => [],
            'data' => []
        );
        foreach($paidStats as $statusVal) {
            $chartData['label'][] = $statusVal['currency'];
            $chartData['data'][] = floatval($statusVal['total_paid'] / 100);
        }
       
        if(defined('WPPAYFORMHASPRO') && WPPAYFORMHASPRO) {
            $subscriptionModel = new Subscription();
            $latestSubscriptions = $subscriptionModel->getLatestSubscriptions();
        } else {
            $latestSubscriptions = [];
        }

        return array(
            'payments' => $stats,
            'latest_subscriptions' => $latestSubscriptions,
            'customer' => $customerProfiles,
            'currency_base' => $paidStats,
            'chart' => $chartData,
            'entries_count' => Submission::count()
        );
    }

    public static function getCustomerAndSubmissionReports()
    {
        $filter_data = self::sanitizedGetReports(Arr::get($_REQUEST, 'filter', []), Arr::get($_REQUEST, 'duration'));
        $filter_data['end_date'] = $filter_data['end_date'] ? $filter_data['end_date'] . ' 23:59:59' : '';
        $duration = Arr::get($filter_data, 'duration');

        $getDurationDate = self::getDurationDate($duration);

        $filter_data['beforeDate'] = $getDurationDate['beforeDate'];
        $filter_data['beforeTwoTimeDate'] = $getDurationDate['beforeTwoTimeDate'];

        wp_send_json_success([
            'customerReports' => self::getCustomerReports($filter_data),
            'submissionReports' => self::getSubmissionReports($filter_data),
            'totalRevenue' => self::getTotalRevenueReports($filter_data),
            'totalPendingRevenue' => self::getTotalRevenueReports($filter_data, ['intented', 'pending']),
        ]);
    }

    public static function getCustomerReports($filter_data)
    {
        $customers = (new Customers())->CustomerReports($filter_data, 'customer_email');
        $total = Arr::get($customers, 'totalCustomers', 0);
        $newCustomerPercent = Arr::get($customers, 'newCustomerPercentage', 0);

        return array (
            'id'=> 'total_customers',
            'title'=> 'Total Customers',
            'value'=> $total,
            'change'=> $newCustomerPercent,
        );
    }

    public static function getSubmissionReports($filter_data)
    {
        $submissions = (new Customers())->CustomerReports($filter_data);
        $total = Arr::get($submissions, 'totalCustomers', 0);
        $totalPercentages = Arr::get($submissions, 'newCustomerPercentage', 0);

        return array(
            'id'=> 'total_submissions',
            'title'=> 'Total Paid Submissions',
            'value'=> $total,
            'change'=> $totalPercentages,
        );
    }

    public static function getTotalRevenueReports($filter_data, $payment_status=['paid'])
    {
        $duration = Arr::get($filter_data, 'duration');
        $startDate = Arr::get($filter_data, 'start_date');
        $endDate = Arr::get($filter_data, 'end_date');

        $beforeDate = Arr::get($filter_data, 'beforeDate');
        $beforeTwoTimeDate = Arr::get($filter_data, 'beforeTwoTimeDate');

        $lastTwoTimeRevenue = [];
        $totalPercentages = 0;

        $revenue = self::getTotalRevenue($startDate, $endDate, null, null, $payment_status);

        if($duration && $duration !== 'All') {
            $lastTwoTimeRevenue = self::getTotalRevenue(null, null, $beforeDate, $beforeTwoTimeDate, $payment_status)->SUM('total_paid');
            $revenueTotal = $revenue->SUM('total_paid');
            $totalPercentages = Customers::getDiff($lastTwoTimeRevenue, $revenueTotal);
        }
        if ($payment_status === ['paid']) {
            return array(
                'id'=> 'total_revenue',
                'title'=> 'Total Revenue',
                'value'=> $revenue,
                'currency'=> '',
                'change'=> $totalPercentages,
            );
        } else {
            return array(
                'id'=> 'pending_revenue',
                'title'=> 'Pending Payments',
                'value'=> $revenue,
                'currency'=> '', 
                'change'=> $totalPercentages,
            );
        
        }
    }

    public static function getTotalRevenue($startDate = null, $endDate = null, $beforeDate = null, $beforeTwoTimeDate = null, $payment_status=['paid'])
    {
        $DB = App::make('db');
        $revenue = Transaction::select(
            'currency',
            'created_at',
            'status',
            $DB->raw("SUM(round(payment_total/ 100, 2)) as total_paid")
        )
            ->whereIn('status', $payment_status)
            ->where('payment_total', '>', 0)
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            })
            ->when($beforeDate && $beforeTwoTimeDate, function ($query) use ($beforeDate, $beforeTwoTimeDate) {
                $query->whereBetween('created_at', [$beforeTwoTimeDate, $beforeDate]);
            })
            ->groupBy('currency')
            ->get();
        
        return $revenue;
    }

    public static function getColors($colorArray, $labels)
    {
        $colors = [];
        foreach ($labels as $status) {
            $lb = strtolower($status);
            $colors[] = isset($colorArray[$lb]) ? $colorArray[$lb] : '#ccc';
        }

        return $colors;
    }

    public function getStatistics()
    {
        $DB = App::make('db');
        $statuses = Submission::select(
            'form_id',
            'payment_status',
            $DB->raw("COUNT(payment_status) as count")
        )
            ->where('payment_method', '!=', '')
            ->groupBy('payment_status')
            ->get();

            $statusLabels = [];
            $statusData = [];
            // dd($statuses->toArray());
            foreach ($statuses as $status) {
                $statusLabels[] = $status['payment_status'];
                $statusData[] = intval($status['count']);
            }

        $methods = Submission::select(
            'form_id',
            'payment_method',
            $DB->raw("COUNT(payment_method) as count")
        )
            ->where('payment_method', '!=', '')
            ->groupBy('payment_method')
            ->get();

        $methodLabels = [];
        $methodData = [];
        foreach ($methods as $method) {
            $methodLabels[] = ucfirst($method['payment_method']);
            $methodData[] = intval($method['count']);
        }

        // status colors
        $statusColor = [
            'paid' => '#189877',
            'pending' => '#F58E07',
            'refunded' => '#6B3CEB',
            'failed' => '#F04438',
            'cancelled' => 'rgb(255,0,0)',
            'precessing' => '#017EF3',
            'waiting' => '#C85FFF',
            'abandoned' => '#98A2B3',
        ];

        $statusColors = self::getColors($statusColor, $statusLabels);  // get colors

        $methodColors = [
            'stripe' => '#67B2F8',
            'paypal' => '#74C1AD',
            'mollie' => '#FBF2E5',
            'razorpay' => '#E7DBF8',
            'paystack' => '#CCFEFF',
            'square' => '#F9BB6A',
            'payrexx' => '#BAFFB3',
            'sslcommerz' => '#C7CFFF',
            'billplz' => '#EB856B',
            'offline' => '#AA83E9',
        ];

       $methodColors = self::getColors($methodColors, $methodLabels);  // get colors

        return array(
            'payment_statuses' => array(
                'height' => 110,
                'width' => 'auto',
                'id' => 'payment_status_chart',
                'type' => 'bar',
                'title' => 'Payment Status',
                'label' => $statusLabels,
                'data' => $statusData,
                'maxBarThickness' => 1,
                'value' => $statuses,
                'color' => $statusColors,
                'backgroundColor' => $statusColors,
            ),
            'payment_methods' => array(
                'height' => '50',
                'width' => '50',
                'id' => 'method_chart',
                'type' => 'doughnut',
                'title' => 'Payment Status',
                'label' => $methodLabels,
                'color' => $methodColors,
                'backgroundColor' => $methodColors,
                'data' => $methodData,
                'value' => $methods,
            )
        );
    }

    public function topCustomers($request)
    {
        $filter_data = $this->sanitizedGetReports($request->filter);
        $customer = Submission::getByCustomerEmail($filter_data);

        $customerProfiles = [];
        $filter = $request->currency;
        if ($filter === 'transactions') {
            $customerProfiles = $this->getCustomerProfiles($customer);
        } else {
            // filter by currency
            foreach ($customer as $value) {
                if ($value['currency'] === $filter) {
                    $email = $value['customer_email'];
                    $customerProfiles[$email]['count'] = isset($customerProfiles[$email]['count']) ? $customerProfiles[$email]['count'] : 0;
                    $customerProfiles[$email]['items'][] = array(
                        "currency" => $value["currency"],
                        "total_paid" => floatval($value["total_paid"]) / 100,
                        "submissions" => $value["submissions"]
                    );
                    $customerProfiles[$email]['name'] = $value["customer_name"];
                    $customerProfiles[$email]['avatar'] = get_avatar_url($email);
                    $customerProfiles[$email]['count'] += intval($value['submissions']);
                }
            }

            //sorting top customers
            $customers = array();
            foreach ($customerProfiles as $email => $value) {
               $customers[$email] = $value['items'][0]['total_paid'];
            }
            array_multisort($customers, SORT_DESC, $customerProfiles);
        }

        return array(
            'customers' => $customerProfiles
        );
    }

    public function getCustomerProfiles($customer){
        $customerProfiles = [];
        foreach ($customer as $value) {
            $email = $value['customer_email'];
            $customerProfiles[$email]['count'] = isset($customerProfiles[$email]['count']) ? $customerProfiles[$email]['count'] : 0;
            $customerProfiles[$email]['items'][] = array(
                "currency" => $value["currency"],
                "total_paid" => floatval($value["total_paid"]) / 100,
                "submissions" => $value["submissions"]
            );
            $customerProfiles[$email]['name'] = $value["customer_name"];
            $customerProfiles[$email]['count'] += intval($value['submissions']);

        }
        return $customerProfiles;
    }

    public function getRecentRevenue($email = '')
    {
        $filter_data = $this->sanitizedGetReports($_REQUEST['filter']);
        $startDate = Arr::get($filter_data, 'start_date');
        $endDate = Arr::get($filter_data, 'end_date');
        $endDate = $endDate ? $endDate . ' 23:59:59' : $endDate;

        $DB = App::make('db');
        $revenueQuery = Submission::select(
            'currency',
            'payment_status',
            $DB->raw('Date(created_at) as date'),
            $DB->raw("SUM(round(payment_total/ 100, 2)) as total_paid"),
            $DB->raw("COUNT(*) as submissions")
        );

        if ($email) {
            $revenueQuery->where('customer_email', $email);
        }

        $revenue = $revenueQuery->whereIn('payment_status', ['paid'])
            ->where('payment_total', '>', 0)
            ->groupBy([$DB->raw('Date(created_at)'), 'currency'])
            ->orderBy('id', 'desc')
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            })
            ->limit(50)
            ->get();


        $group = array();
        foreach ( $revenue as $value ) {
            $group[$value['currency']][] = $value;
        }

        $groupSelect = array();
        $chartData = array();
        foreach ($group as $key => $value) {
            $groupSelect[] = array(
                'label' => $key,
                'value' => $key,
            );
            foreach ($value as $val) {
                $chartData[$key]['label'][] = $val['date'];
                $chartData[$key]['data'][] = floatval($val['total_paid']);
            }
        }

        return array(
            'data' => $group,
            'options' => $groupSelect,
            'chartData' => $chartData,
        );
    }

    private static function sanitizedGetReports($data, $duration = null) {

        return array(
            'start_date' => sanitize_text_field(Arr::get($data, 'startDate', '')),
            'end_date' => sanitize_text_field(Arr::get($data, 'endDate', '')),
            'duration' => sanitize_text_field($duration),
        );
    }

}