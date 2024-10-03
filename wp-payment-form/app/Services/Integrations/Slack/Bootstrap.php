<?php

namespace WPPayForm\App\Services\Integrations\Slack;

use WPPayForm\App\Services\ConditionAssesor;
use WPPayForm\App\Services\Integrations\IntegrationManager;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Meta;
use WPPayForm\Framework\Foundation\App;

class Bootstrap extends IntegrationManager
{
    public $hasGlobalMenu = false;

    public $disableGlobalSettings = 'yes';

    public function __construct()
    {
        parent::__construct(
            App::getInstance(),
            'Slack',
            'slack',
            '_wppayform_slack_settings',
            'slack_feeds',
            10
        );

        $this->logo = WPPAYFORM_URL . 'assets/images/integrations/slack.png';

        $this->description = __('Get realtime notification in slack channel when a new submission will be added.', 'wp-payment-form');

        $this->category = 'crm';

        $this->registerAdminHooks();

        add_filter('wppayform_global_notification_feeds', array($this, 'getSlackFeeds'), 20, 2);

        add_filter('wppayform_notifying_async_slack', '__return_false');
    }

    public function isConfigured()
    {
        return true;
    }


    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title' => $this->title . ' Integration',
            'logo' => $this->logo,
            'is_active' => 'yes',
            'configure_title' => __('Configuration required!', 'wp-payment-form'),
            'config_url' => admin_url('admin.php?page=wppayform.php#/integrations/slack'),
            'global_configure_url' => admin_url('admin.php?page=wppayform.php#/integrations/slack'),
            'configure_message' => 'Please configure your Slack settings to enable this integration.',
            'configure_button_text' => __('Set Slack', 'wp-payment-form')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name' => '',
            'webhook' => '',
            'trigger_on_payment' => false,
            'conditionals' => [
                'conditions' => [],
                'status' => false,
                'type' => 'all'
            ],
            'enabled' => 'yes'
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        return [
            'fields' => [
                [
                    'key' => 'name',
                    'label' => __('Feed Name', 'wp-payment-form'),
                    'required' => true,
                    'placeholder' => __('Your Feed Name', 'wp-payment-form'),
                    'component' => 'text'
                ],
                [
                    'key' => 'webhook',
                    'label' => __('Slack Webhook', 'wp-payment-form'),
                    'required' => true,
                    'placeholder' => __('https://hooks.slack.com/services/...', 'wp-payment-form'),
                    'tips' => __(' The <a href="https://api.slack.com/incoming-webhooks" target="_blank">slack webhook URL</a> where Paymattic will send JSON payload.', 'wp-payment-form'),
                    'component' => 'text'
                ],
                [
                    'key' => 'trigger_on_payment',
                    'require_list' => false,
                    'checkbox_label' => __('Trigger notification on payment success only', 'wp-payment-form'),
                    'component' => 'checkbox-single'
                ],
                [
                    'require_list' => false,
                    'key'          => 'conditionals',
                    'label'        => __('Conditional Logics', 'wp-payment-form'),
                    'tips'         => __('Allow Fluent Support integration conditionally based on your submission values', 'wp-payment-form'),
                    'component'    => 'conditional_block'
                ],
                [
                    'require_list' => false,
                    'key' => 'enabled',
                    'label' => 'Status',
                    'component' => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'wp-payment-form')
                ]
            ],
            'button_require_list' => false,
            'integration_title' => $this->title
        ];
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return [];
    }

    public function getSlackFeeds($feeds, $formId)
    {
        $webhookFeeds = Meta::where('form_id', $formId)
        ->where('meta_key', 'slack')->get();

        if(!$webhookFeeds){
            return $feeds;
        }

        foreach ($webhookFeeds as $feed) {
            $feeds[] = $feed;
        }
        return $feeds;
    }

    public function notify($feed, $formData, $entry, $formId)
    {
        // especially for asynchronous notifications
        $response = Slack::handle($feed, $formData, $entry, $formId);

        if ($response['status'] === 'success') {
            do_action('wppayform_log_data', [
                'form_id' => $formId,
                'submission_id' => $entry->id,
                'type' => 'success',
                'created_by' => 'Paymattic BOT',
                'title' => 'Slack',
                'content' => $response['message']
            ]);
        } else {
            do_action('wppayform_log_data', [
                'form_id' => $formId,
                'submission_id' => $entry->id,
                'type' => 'failed',
                'created_by' => 'Paymattic BOT',
                'title' => 'Slack',
                'content' => $response['message']
            ]);
        }
    }
}