<?php

namespace WPPayForm\App\Services\Integrations\FluentSupport;

use WPPayForm\App\Services\ConditionAssesor;
use WPPayForm\App\Services\Integrations\IntegrationManager;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\Framework\Foundation\App;

use FluentSupport\App\Models\Ticket;
use FluentSupport\App\Api\Classes\Tickets;

class Bootstrap extends IntegrationManager
{
    public $hasGlobalMenu = false;

    public $disableGlobalSettings = 'yes';

    public function __construct()
    {
        parent::__construct(
            App::getInstance(),
            'Fluent Support',
            'fluentsupport',
            '_wppayform_fluentsupport_settings',
            'fluentsupport_feeds',
            10
        );

        $this->logo = WPPAYFORM_URL . 'assets/images/integrations/fluentsupport.svg';

        $this->description = 'Paymattic\'s connection with Fluent Support enables you to take payments from users in return of services.';

        $this->category = 'crm';

        $this->registerAdminHooks();

        add_filter('wppayform_notifying_async_fluentsupport', '__return_false');
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title' => $this->title . ' Integration',
            'logo' => $this->logo,
            'is_active' => $this->isConfigured(),
            'configure_title' => __('Configuration required!', 'wp-payment-form'),
            'config_url' => admin_url('admin.php?page=wppayform.php#/integrations/' . $this->integrationKey . ''),
            'global_configure_url' => admin_url('admin.php?page=wppayform.php#/integrations/' . $this->integrationKey . ''),
            'configure_message' => __('Fluent Support is not configured yet! Please configure your Fluent Support api first', 'wp-payment-form'),
            'configure_button_text' => __('Set Fluent Support', 'wp-payment-form')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name' => '',
            'full_name' => '',
            'email' => '',
            'type' => '',
            'title' => '',
            'content' => '',
            'trigger_on_payment' => false,
            'conditionals' => [
                'conditions' => [],
                'status' => false,
                'type' => 'all'
            ],
            'enabled' => true
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
                    'key' => 'CustomFields',
                    'require_list' => false,
                    'label' => __('Primary Fields', 'wp-payment-form'),
                    'tips' => __('Associate your Fluent Support merge tags to the appropriate Paymattic fields by selecting the appropriate form field from the list.', 'wp-payment-form'),
                    'component' => 'map_fields',
                    'field_label_remote' => __('Fluent Support Field', 'wp-payment-form'),
                    'field_label_local' => __('Form Field', 'wp-payment-form'),
                    'primary_fileds' => [
                        [
                            'key' => 'email',
                            'label' => __('Email Address', 'wp-payment-form'),
                            'required' => true,
                            'input_options' => 'emails'
                        ],
                        [
                            'key' => 'title',
                            'label' => __('Tickets Title', 'wp-payment-form'),
                            'required' => true,
                            'input_options' => 'text'
                        ],
                        [
                            'key' => 'full_name',
                            'label' => __('Full Name', 'wp-payment-form'),
                            'help_text' => __('If First Name & Last Name is not available full name will be used to get first name and last name', 'wp-payment-form')
                        ],
                        [
                            'key' => 'Type',
                            'label' => __('Tickets type', 'wp-payment-form'),
                            'required' => false,
                            'input_options' => 'type'
                        ],
                        [
                            'key' => 'content',
                            'label' => __('Content', 'wp-payment-form'),
                            'required' => true,
                            'input_options' => 'content'
                        ],
                    ]
                ],
                [
                    'key' => 'trigger_on_payment',
                    'require_list' => false,
                    'checkbox_label' => __('Create ticket on payment success only', 'wp-payment-form'),
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

    /*
     * Form Submission Hooks Here
     */
    public function notify($feed, $formData, $entry, $formId)
    {
        $data = $feed['processedValues'];

        $ticketsData = Arr::only($data, ['email']);

        if (!is_email(Arr::get($ticketsData, 'email'))) {
            $ticketsData['email'] = Arr::get($formData, 'customer_email');
        }

        $fullName = Arr::get($data, 'full_name');
        if ($fullName) {
            $nameArray = explode(' ', $fullName);
            if (count($nameArray) > 1) {
                $ticketsData['last_name'] = array_pop($nameArray);
                $ticketsData['first_name'] = implode(' ', $nameArray);
            } else {
                $ticketsData['first_name'] = $fullName;
            }
        }

        if ($entry->ip) {
            $ticketsData['ip'] = $entry->ip;
        }

        if (!is_email($ticketsData['email'])) {
            $this->addLog(
                __('Fluent Support ticket creation skipped email, title or content are required!', 'wp-payment-form'),
                $formId,
                $entry->id,
                'failed'
            );
            return false;
        }

        try {

            if (function_exists('FluentSupportApi')) {
                $customerId = $this->createOrVerifyCustomer($ticketsData);

                $ticketInstance = FluentSupportApi('tickets');

                $ticket = FluentSupportApi('tickets')->createTicket([
                    'email' => sanitize_email($ticketsData['email']),
                    'name' => sanitize_text_field($ticketsData['first_name']),
                    'name' => sanitize_text_field($ticketsData['last_name']),
                    'title' => sanitize_text_field($data['title']),
                    'type' => $data['type'],
                    'content' => sanitize_text_field($data['content']),
                    'customer_id' => intVal($customerId)
                ]);

                if ($ticket->id) {
                    $ticketsUrl = admin_url("admin.php?page=fluent-support#/tickets/" . $ticket->id . "/view");
                    $this->addLog(
                        '<a href="' . $ticketsUrl . '">' . $ticket->id .'</a> Ticket created Successfully !'. $feed['settings']['name'],
                        $formId,
                        $entry->id,
                        'success'
                    );
                    do_action('fluentsupport_tickets_created_by_wppayform', $ticket->id, $entry, $formId, $feed);
                } else {
                    $this->addLog(
                        __("Ticket creation failed", 'wp-payment-form'),
                        $formId,
                        $entry->id,
                        'failed'
                    );
                }
            } else {
                $this->addLog(
                    __("Fluent Support is not installed or activated", 'wp-payment-form'),
                    $formId,
                    $entry->id,
                    'failed'
                );
            }
        } catch (\Exception $e) {
            $this->addLog(
                $e->getMessage(),
                $formId,
                $entry->id,
                'failed'
            );
        }
    }

    /**
     * @param $ticketsData
     * @return cusomerId
     */

    public function createOrVerifyCustomer($ticketsData, $createWpUser = false)
    {
        $DB = App::getInstance('db');

        $customer = $DB->table('fs_persons')
        ->where('person_type', 'customer')
        ->where('email', $ticketsData['email'])->first();

        if ($customer) {
            return $customer->id;
        } else {
            $customerInstance = FluentSupportApi('customers');
            $customer = $customerInstance->createCustomerWithOrWithoutWpUser([
                'email' => $ticketsData['email'],
                'first_name' => $ticketsData['first_name'],
                'last_name' => $ticketsData['last_name'],
            ], $createWpUser);
        }
        return $customer->id;
    }


    public function isConfigured()
    {
        return true;
    }

    public function isEnabled()
    {
        return true;
    }

    /*
     * We will remove this in future
     */
    protected function getSelectedTagIds($data, $inputData, $simpleKey = 'tag_ids', $routingId = 'tag_ids_selection_type', $routersKey = 'tag_routers')
    {
        $routing = Arr::get($data, $routingId, 'simple');
        if (!$routing || $routing == 'simple') {
            return Arr::get($data, $simpleKey, []);
        }

        $routers = Arr::get($data, $routersKey);
        if (empty($routers)) {
            return [];
        }

        return $this->evaluateRoutings($routers, $inputData);
    }

    /*
     * We will remove this in future
     */
    protected function evaluateRoutings($routings, $inputData)
    {
        $validInputs = [];
        foreach ($routings as $routing) {
            $inputValue = Arr::get($routing, 'input_value');
            if (!$inputValue) {
                continue;
            }
            $condition = [
                'conditionals' => [
                    'status' => true,
                    'is_test' => true,
                    'type' => 'any',
                    'conditions' => [
                        $routing
                    ]
                ]
            ];

            if (ConditionAssesor::evaluate($condition, $inputData)) {
                $validInputs[] = $inputValue;
            }
        }

        return $validInputs;
    }
}
