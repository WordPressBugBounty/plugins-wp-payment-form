<?php

namespace WPPayForm\App\Modules\FluentCommunity;

class FluentCommunity {

	public function register()
	{
		$this->addMenu();
		$this->loadAssets();
	}

	public function addMenu()
	{
		add_filter('wppayform/customer_dashboard/menus', function ($menus) {
			$menus[] = [
				'name' => __('Spaces & Courses', 'wp-payment-form'),
				'slug' => 'wpf-community',
				'icon' => 'dashicons dashicons-buddicons-groups'
			];
			return $menus;
		});

	}

	public function loadAssets()
	{
		add_action('wp_enqueue_scripts', function () {
			wp_enqueue_script(
				'wppayform_community_script',
				WPPAYFORM_URL . 'assets/js/Community/CustomerDashboard.js',
				array('jquery'),
				WPPAYFORM_VERSION,
				true
			);
		});

	}

}