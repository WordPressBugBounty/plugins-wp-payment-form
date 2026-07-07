<?php 
namespace WPPayForm\App\Http\Controllers;

use WPPayForm\App\Models\Reports;
use WPPayForm\App\Models\Customers;
use WPPayForm\App\Services\AccessControl;


class ReportsController extends Controller
{

    public function getReports()
	{
        return (new Reports())->getReports();
	}

	public function getCustomerAndSubmissionReports()
	{
        return Reports::getCustomerAndSubmissionReports();
	}

	public function getStatistics()
	{
		return (new Reports())->getStatistics();
	}

	public function getRecentRevenue()
	{
		return (new Reports())->getRecentRevenue();
	}

	public function topCustomers()
	{
		$request = $this->request;
		return (new Reports())->topCustomers($request);
	}

	public function customers()
	{
		$request = $this->request;
		return (new Customers())->index($request);
	}

	public function customer($customerEmail)
	{
        $customerEmail = sanitize_email($customerEmail);
        $this->assertOwnsCustomerEmail($customerEmail);
		return (new Customers())->customer($customerEmail, 'yes');
	}

	public function customerProfile($customerEmail)
	{
        $customerEmail = sanitize_email($customerEmail);
        $this->assertOwnsCustomerEmail($customerEmail);
		return (new Customers())->customerProfile($customerEmail);
	}

	public function customerEngagements($customerEmail)
	{
        $customerEmail = sanitize_email($customerEmail);
        $this->assertOwnsCustomerEmail($customerEmail);
		return (new Customers())->customerEngagements($customerEmail);
	}

    private function assertOwnsCustomerEmail($customerEmail)
    {
        if (AccessControl::hasGrandAccess()) {
            return;
        }
        if ($customerEmail !== sanitize_email(wp_get_current_user()->user_email)) {
            wp_send_json_error(
                ['message' => __('You do not have permission to access this customer.', 'wp-payment-form')],
                403
            );
        }
    }
}
