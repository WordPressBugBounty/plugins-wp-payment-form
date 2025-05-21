<?php

namespace WPPayForm\App\Hooks\Handlers;

use WPPayForm\Database\DBMigrator;

class ActivationHandler
{
    public function handle($network_wide = false)
    {
        DBMigrator::run($network_wide);

        $this->setPluginInstallTime();
        $this->registerWpCron();
    }

    public function setPluginInstallTime()
    {
        $statuses = get_option( 'wppayform_statuses', []);
        if( !isset($statuses['installed_time']) ){
            $statuses['installed_time'] = strtotime("now") ;
            update_option('wppayform_statuses', $statuses, false);
        }
    }

    public function registerWpCron() {
        if (function_exists('as_schedule_recurring_action')) {
            as_schedule_recurring_action(
                time(),
                60 * 60 * 12,
                'wppayform/daily_reminder_task',
                [],
                'wppayform-scheduler-task'
            );
        }
    }
}
