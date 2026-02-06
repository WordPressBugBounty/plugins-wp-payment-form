<?php 
namespace WPPayForm\App\Http\Controllers;

use WPPayForm\App\Models\Reports;
use WPPayForm\App\Models\Customers;


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
		return (new Customers())->customer($customerEmail, 'yes');
	}

	public function customerProfile($customerEmail)
	{
        $customerEmail = sanitize_email($customerEmail);
		return (new Customers())->customerProfile($customerEmail);
	}

	public function customerEngagements($customerEmail)
	{
        $customerEmail = sanitize_email($customerEmail);
		return (new Customers())->customerEngagements($customerEmail);
	}
}
