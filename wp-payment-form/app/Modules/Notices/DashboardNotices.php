<?php

namespace WPPayForm\App\Modules\Notices;

use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * DashboardNotices
 * @since 3.7.1
 */
class DashboardNotices
{
    private $formCount = null;

    private $paidTransactions = null;

    /**
     * Option name
     * @var string
     * @since 3.7.1
     **/
    private $option_name = 'wppayform_statuses';

    private $mileStones = [20, 100, 200, 500, 1000, 5000, 10000];

    private function getFormCount()
    {
        if (null === $this->formCount){
            $templates = get_posts([
                'post_type' => ['wp_payform'],
                'post_status' => 'publish',
                'numberposts' => -1
            ]);
            $this->formCount = count($templates);
        }

        return $this->formCount;
    }

    private function getPaidTransactionCount()
    {
        if (null === $this->paidTransactions){
            $transactionModel = new \WPPayForm\App\Models\Transaction();
            $this->paidTransactions = $transactionModel::query()->where('status', 'paid')->count();
           
        }

        return $this->paidTransactions;
    }

    public function getCurrentMilestone($paidCount, $previousMilestone = 0)
    {
        // If $paidCount is below the first milestone, return "No milestones reached."
        if ($paidCount < $this->mileStones[0]) {
            return 0;
        }

        // Iterate through milestones to find the current milestone
        foreach ($this->mileStones as $milestone) {
            if ($paidCount < $milestone) {
                return $previousMilestone;
            }
            $previousMilestone = $milestone;
        }

        // If $paidCount exceeds all milestones, return the highest milestone
        return $previousMilestone;
    }

    public function noticeTracker()
    {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $statuses = get_option('wppayform_statuses', []);
      
        $rescue_me = Arr::get($statuses, 'rescue_me');

        if ($rescue_me === '3'){
            return false;
        }

        $previousMilestone = Arr::get($statuses, 'milestone', 0);

        $paidCount = $this->getPaidTransactionCount();

        $firsTime = 0;
        $targetMileStone = $this->getNextMileStone($paidCount);
        if ($previousMilestone === 0){
            $firsTime = 1;
        }
      
        $currentMilestone = $this->getCurrentMilestone($paidCount, $previousMilestone);
        

        $installDate = Arr::get($statuses, 'installed_time');

        $remind_me = Arr::get($statuses, 'remind_me', strtotime('now'));
        $remind_due = strtotime('+15 days', $remind_me);
        $past_date = strtotime("-10 days");
        $now = strtotime("now");

        // handle first time
        if ($firsTime){
            if ($currentMilestone >= $targetMileStone){
                return [
                    'type' => 'milestone',
                    'milestone' => $currentMilestone
                ];
            } 
        } else {
            if ($currentMilestone >= $this->mileStones[0] && !$firsTime){
                if (($now >= $remind_due && $rescue_me != '1') || $currentMilestone > $previousMilestone){
                    return [
                        'type' => 'milestone',
                        'milestone' => $currentMilestone
                    ];
                }
            }
        }


        if ($rescue_me === '1'){
            return false;
        }

        if ($this->getFormCount() > 0 && $this->paidTransactions < $this->mileStones[0]) { 
            if ($now >= $remind_due || ($past_date >= $installDate && $rescue_me !== '2')){
                return [
                    'type' => 'long_time_no_see',
                ];
            }
        }
        return false;
    }

    public function getNextMileStone($paidCount)
    {
       $nextMilestone = end($this->mileStones);
        foreach ($this->mileStones as $milestone) {
              if ($paidCount <= $milestone) {
                $nextMilestone = $milestone;
                break;
              }
        }

        return $nextMilestone;
    }


    public function updateNotices($args = [])
    {
        $value = sanitize_text_field(Arr::get($args, 'value'));
        $notice_type = sanitize_text_field(Arr::get($args, 'notice_type'));

        $statuses = get_option($this->option_name, []);

        if ($notice_type === 'rescue_me' && $value === '1'){
            $statuses['rescue_me'] = '1';
        }

        if ($notice_type === 'remind_me' && $value === '1'){
            $statuses['remind_me'] = strtotime('now');
            $statuses['rescue_me'] = '2';
        }

        if ($notice_type === 'already_rated' && $value === '1'){
            $statuses['already_rated'] = 'yes';
            $statuses['rescue_me'] = '3';
        }

        $milestone = Arr::get($args, 'milestone', 0);

        if ($milestone){
            $statuses['milestone'] = intval($milestone);
        }

        $existing_data = get_option($this->option_name, []);

        $is_identical = serialize($existing_data) === serialize($statuses);
        if ($is_identical){
            return true;
        }
        return update_option($this->option_name, $statuses, false);
    }

    public function getNoticesStatus()
    {
        return $this->noticeTracker();
    }
}