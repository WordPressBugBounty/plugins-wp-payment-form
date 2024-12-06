<?php

namespace WPPayForm\App\Modules\LeaderBoard;

use WPPayForm\App\App;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Services\GeneralSettings;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Http\Controllers\FormController;
use WPPayForm\App\Models\DemoForms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ajax Handler Class
 * @since 1.0.0
 */
class Render
{
    private $totalRaisedAmount;
    private $totalDonations;
    private $donationGoal;
    private $percent;

    public function __construct($totalRaisedAmount = 0, $totalDonations = 0, $donationGoal = 0, $percent = 0) {
        $this->totalRaisedAmount = esc_html($totalRaisedAmount);
        $this->totalDonations = esc_html($totalDonations);
        $this->donationGoal = esc_html($donationGoal);
        $this->percent = esc_html($percent);
    }
    public function render($template_id = '', $form_id = null, $per_page = 10, $show_total = true, $show_name = true, $show_avatar = true, $orderby = null)
    {
        $options = get_option('wppayform_settings', []);
        $option_key = "wppayform_donation_leaderboard_settings";

        if ($form_id != 0 && $form_id != null) {
            $option_key = "wppayform_donation_leaderboard_settings_" . $form_id;
        }

        $leaderboard_settings = get_option($option_key, array(
            'enable_donation_for' => 'all',
            'template_id' => 3,
            'enable_donation_for_specific' => [],
            'orderby' => 'grand_total'
        )
        );
        if ($leaderboard_settings == false || $leaderboard_settings == null || Arr::get($leaderboard_settings, 'enable_donation_for') == 'disable') {
            return;
        }
        wp_enqueue_style('wppayform_leaderboard', WPPAYFORM_URL . 'assets/css/leaderboard.css', array(), WPPAYFORM_VERSION);
        wp_enqueue_script(
            'wppayform_leaderboard_js',
            WPPAYFORM_URL . 'assets/js/leaderboard.js',
            array('jquery'),
            WPPAYFORM_VERSION,
            true
        );

        wp_localize_script('wppayform_leaderboard_js', 'wp_payform_leader_board', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_payform_nonce'),
            'no_donor_image' => WPPAYFORM_URL . 'assets/images/empty-cart.svg',
        )
        );

        $template_src = 'leaderBoard.' . $template_id;

        $donationItems = $this->getDonarList($form_id, '', $orderby, 'true', $per_page);
        $currency_settings = GeneralSettings::getGlobalCurrencySettings();
        $currency_sign = GeneralSettings::getCurrencySymbol($currency_settings['currency']);
        $currency_settings['currency_sign'] = $currency_sign;
        $total_raised_amount = wpPayFormFormattedMoney(wpPayFormConverToCents(Arr::get($donationItems, 'total_raised_amount', 0)), $currency_settings);
        $donationGoal = wpPayFormFormattedMoney(wpPayFormConverToCents(Arr::get($donationItems, 'donation_goal', 0)), $currency_settings);
        $percent = Arr::get($donationItems, 'percent', 0);
        $total_donations = Arr::get($donationItems, 'total_donations', 0);
        $show_statistic = Arr::get($donationItems, 'show_statistic', '');
        $progress_bar = Arr::get($donationItems, 'progress_bar','');

        ob_start();
        App::make('view')->render($template_src, [
            'donars' => $donationItems['donars'],
            'topThreeDonars' => $donationItems['topThreeDonars'],
            'show_total' => $show_total,
            'show_name' => $show_name,
            'show_avatar' => $show_avatar,
            'form_id' => $form_id,
            'per_page' => $per_page,
            'orderby' => $orderby,
            'has_more_data' => $donationItems['has_more_data'],
            'total' => $donationItems['total'],
            'template_id' => $template_id,
            'total_raised_amount' => $total_raised_amount,
            'donation_goal' => $donationGoal,
            'percent' => $percent,
            'total_donations' => $total_donations,
            'progress_bar' => $progress_bar,
            'show_statistic' => $show_statistic
        ]);
        $view = ob_get_clean();
        return $view;
    }
    public function leaderBoardRender()
    {
        $form_id = isset($_POST['form_id']);
        $form_id = sanitize_text_field(wp_unslash($_POST['form_id']));
        $searchText = isset($_REQUEST['searchText']);
        $searchText = sanitize_text_field(wp_unslash($_REQUEST['searchText']));
        $sortKey = isset($_REQUEST['sortKey']);
        $sortKey = sanitize_text_field(wp_unslash($_REQUEST['sortKey']));
        $sortType = isset($_REQUEST['sortType']);
        $sortType = sanitize_text_field(wp_unslash($_REQUEST['sortType']));
        $per_page = isset($_REQUEST['perPage']);
        $per_page = sanitize_text_field(wp_unslash($_REQUEST['perPage']));

        $donationItems = $this->getDonarList($form_id, $searchText, $sortKey, $sortType, $per_page);

        wp_send_json_success($donationItems, 200);
    }

    private function getDonarList($form_id, $searchText = null, $sortKey = null, $sortType = '', $per_page = 20)
    {
        $donationItems = (new Submission())->getDonationItem($form_id, $searchText, $sortKey, $sortType, 0, $per_page);
        return $donationItems;
    }

    public static function displayDonationStats($totalRaisedAmount, $totalDonations, $donationGoal, $percent) {  
        $template_src = 'leaderBoard.stats';  
        ob_start();  
        $viewRenderer = App::make('view');  
        $viewRenderer->render($template_src, [  
            'raised' => $totalRaisedAmount,  
            'total_donations'  => $totalDonations,  
            'goal'   => $donationGoal,  
            'percent' => $percent,  
        ]);  
        return ob_get_clean();  
    }
}
