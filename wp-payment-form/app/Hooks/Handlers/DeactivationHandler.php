<?php

namespace WPPayForm\App\Hooks\Handlers;

class DeactivationHandler
{
    public function handle()
    {
        if(function_exists('as_unschedule_action')) {
            as_unschedule_action('wppayform/daily_reminder_task');
        }
        wp_clear_scheduled_hook('wppayform/daily_reminder_task');
    }
}
