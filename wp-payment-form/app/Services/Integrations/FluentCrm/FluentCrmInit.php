<?php

namespace WPPayForm\App\Services\Integrations\FluentCrm;

use WPPayForm\Framework\Foundation\App;
use WPPayForm\App\Models\Submission;
use FluentCrm\App\Models\Subscriber;
use \WPPayForm\App\Models\Meta;
use WPPayForm\Framework\Support\Arr;

class FluentCrmInit
{
    public function init()
    {
        new \WPPayForm\App\Services\Integrations\FluentCrm\Bootstrap();
        add_filter('wppayform_single_entry_widgets', array($this, 'pushContactWidget'), 10, 3);
        add_filter('wppayform_customer_profile', array($this, 'getCustomerProfile'), 10, 2);

        add_action('wppayform/after_payment_status_change', array($this, 'handle'), 10, 3);
        add_action('wppayform/subscription_payment_canceled', array($this, 'handleSubscriptionCancelled'), 10, 4);
    }

    public function getCustomerProfile($profiles, $email)
    {
        return fluentcrm_get_crm_profile_html($email, false);
    }

    public function pushContactWidget($widgets, $entryData)
    {
        $userId = $entryData['submission']->user_id;

        if ($userId) {
            $maybeEmail = Arr::get($entryData['submission']->user, 'email');
            if (!$maybeEmail) {
                $maybeEmail = $userId;
            }
        } else {
            $maybeEmail = $entryData['submission']->customer_email;
        }

        if (!$maybeEmail) {
            return $widgets;
        }

        $profileHtml = fluentcrm_get_crm_profile_html($maybeEmail, false);

        if (!$profileHtml) {
            return $widgets;
        }

        $widgets['fluent_crm'] = [
            'title' => __('FluentCRM Profile', 'fluent-crm'),
            'content' => $profileHtml
        ];
        return $widgets;
    }

    public function handle($submissionId, $newStatus)
    {
        if ($newStatus !== 'refunded') {
            return;
        }

        $entry = (new Submission())->getSubmission($submissionId);

        $settings = Meta::getFormMeta($entry->form_id, 'fluentcrm_feeds');
        if (!$settings) {
            return;
        }

        // Not enable on refund then skip
        if (!Arr::get($settings, 'remove_on_refund')) {
            return;
        }
     
        // remove/unsubscribe from crm contacts
        $email = $entry->customer_email; 
        Subscriber::where('email', $email)->delete();

        return true;
    }

    public function handleSubscriptionCancelled($submission, $subscription, $formId, $vendor_data)
    {
   
        $userId = $submission->user_id;
        // get form meta related to fcom feeds
        if (!$userId) {
            return;
        }

        $entry = (new Submission())->getSubmission($submission->id);

        $settings = Meta::getFormMeta($entry->form_id, 'fluentcrm_feeds');
        if (!$settings) {
            return;
        }

        // Not enable on subscription cancel then skip
        if (!Arr::get($settings, 'remove_on_subscription_cancel')) {
           return;
        }

        // remove/unsubscribe from crm contacts
        $email = $entry->customer_email; 
        Subscriber::where('email', $email)->delete();
        return;
    }
}
