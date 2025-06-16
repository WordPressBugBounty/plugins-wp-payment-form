<?php

use WPPayForm\App\Hooks\Handlers\ActivationHandler;
use WPPayForm\App\Hooks\Handlers\DeactivationHandler;
use WPPayForm\Framework\Foundation\Application;

return function ($file) {
    register_activation_hook($file, function () {
        (new ActivationHandler)->handle();
    });

    register_deactivation_hook($file, function () {
        (new DeactivationHandler)->handle();
    });

    $actionSchedulerPath = WPPAYFORM_DIR . 'app/Modules/ActionScheduler/ActionScheduler.php';  
    if (file_exists($actionSchedulerPath)) {  
        $actionSchedular = require_once $actionSchedulerPath;  
    }


    add_action('plugins_loaded', function () use ($file) {
        // check the server here
        if (substr(phpversion(), 0, 3) == '7.0') {
            add_action('admin_notices', function () {
                $class = 'notice notice-error fc_message';
                $message = 'Looks like you are using PHP 7.0 which is not supported by WPPayForm. Please upgrade your PHP Version greater than to 7.2';
                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_attr($message));
            });
        } else {
            do_action('wppayform_loaded', new Application($file));
        }
      
        if ((defined('WPPAYFORMPRO_VERSION') && version_compare(WPPAYFORMPRO_VERSION, '4.6.0', '<')) && (defined('WPPAYFORM_VERSION') &&version_compare(WPPAYFORM_VERSION, '4.6.0', '>='))) {
            $demoPage = new \WPPayForm\App\Modules\Exterior\ProcessDemoPage();
            $demoPage->injectAgreement();
        }
        
        add_action('wppayform_loading_app', function () {
            if(function_exists('as_schedule_recurring_action') && !as_next_scheduled_action('wppayform/daily_reminder_task')) {
                as_schedule_recurring_action(
                    time(),
                    60 * 60 * 12,
                    'wppayform/daily_reminder_task',
                    [],
                    'wppayform-scheduler-task'
                );
            }
        });
    });
};
