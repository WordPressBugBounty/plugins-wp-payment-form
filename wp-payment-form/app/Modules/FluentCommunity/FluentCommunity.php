<?php

namespace WPPayForm\App\Modules\FluentCommunity;

class FluentCommunity {

	public function register()
	{
		add_filter('wppayform/customer_dashboard/menus', array($this, 'addMenu'), 10, 1);
	}

	public function addMenu($menus)
	{
		$this->loadAssets();

		$menus[] = [
			'name' => __('Spaces & Courses', 'wp-payment-form'),
			'slug' => 'wpf-community',
			'icon' => 'dashicons dashicons-buddicons-groups'
		];

		return $menus;

	}

	public function loadAssets()
	{
		wp_enqueue_script(
			'wppayform_community_script',
			WPPAYFORM_URL . 'assets/js/Community/CustomerDashboard.js',
			array('jquery'),
			WPPAYFORM_VERSION,
			true
		);

	}

}