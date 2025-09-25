<?php

namespace WPPayForm\App\Services\Integrations;

use WPPayForm\app\Services\AsyncRequest;
use WPPayForm\App\Services\ConditionAssesor;
use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Meta;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Services\PlaceholderParser;
use WPPayForm\App\Services\FluentcrmListConditional;

class GlobalNotificationManager
{
    public function triggerNotification($insertId, $formId, $formData, $formattedElements, $triggerAction = 'on_submit')
    {
        // Let's find the feeds that are available for this form
        $feedKeys = apply_filters('wppayform_global_notification_active_types', [], $formId);

        if (!$feedKeys) {
            return;
        }

        $feedMetaKeys = array_keys($feedKeys);

        $feeds = Meta::where('form_id', $formId)
            ->whereIn('meta_key', $feedMetaKeys);
        // all notifications without filtering
       $allNotifications = (clone $feeds)->orderBy('id', 'ASC')->get();

        if ($triggerAction == 'on_submit') {
            $feeds->where('meta_group', '!=', 'on_payment');
        } else {
            $feeds->where('meta_group', '=', 'on_payment');
        }
        
        $notifications = $feeds->orderBy('id', 'ASC')->get();

        $this->globalNotify($insertId, $formId, $formData, $feedKeys, $notifications, $allNotifications);
    }

    public function globalNotify($insertId, $formId, $formData, $feedKeys, $feeds, $allNotifications)
    {
        if (!$feeds) {
            return;
        }

        // Now we have to filter the feeds which are enabled
        $enabledFeeds = [];
        foreach ($feeds as $feed) {
            $parsedValue = json_decode($feed->meta_value, true);

            if ($parsedValue && Arr::get($parsedValue, 'enabled')) {
                if($feed->meta_key == 'fluentcrm_feeds' && empty($parsedValue['list_id'])) {
                    $checkList = (new FluentcrmListConditional())->checkConditions($feed, $parsedValue, $formData, $formId);
                    if (!$checkList['isValid']) {
                        continue;
                    }
                    $parsedValue['list_id'] = $checkList['listId'];
                }
                // Now check if conditions matched or not
                $isConditionMatched = $this->checkCondition($parsedValue, $formData, $insertId);
                if ($isConditionMatched) {
                    $item = [
                        'id'       => $feed->id,
                        'meta_key' => $feed->meta_key,
                        'settings' => $parsedValue
                    ];
                    $enabledFeeds[] = $item;
                }
            }
        }

        $allEnabledFeeds = [];
        foreach ($allNotifications as $notification) {
            $parsedValue = json_decode($notification->meta_value, true);
            if ($parsedValue && Arr::get($parsedValue, 'enabled')) {
                if($notification->meta_key == 'fluentcrm_feeds' && empty($parsedValue['list_id'])) {
                    $checkList = (new FluentcrmListConditional())->checkConditions($notification, $parsedValue, $formData, $formId);
                    if (!$checkList['isValid']) {
                        continue;
                    }
                    $parsedValue['list_id'] = $checkList['listId'];
                }
                $isConditionMatched = $this->checkCondition($parsedValue, $formData, $insertId);
                if ($isConditionMatched) {
                    $item = [
                        'id'       => $notification->id,
                        'meta_key' => $notification->meta_key,
                        'settings' => $parsedValue
                    ];
                    $allEnabledFeeds[] = $item;
                }
            }
        }

        if (!$allEnabledFeeds) {
            do_action('wppayform_global_notify_completed', $insertId, $formId);
            return;
        }

        $entry = false;
        $asyncFeeds = [];

        foreach ($enabledFeeds as $feed) {

            // We will decide if this feed will run on async or sync
            $integrationKey = Arr::get($feedKeys, $feed['meta_key']);

            // Giving support of legacy integration for zapier,webhook and slack as they were used to work separately before 4.6.0
            if ($feed['meta_key'] == 'webhook' || $feed['meta_key'] == 'zapier' || $feed['meta_key'] == 'slack') {
                $feed['meta_key'] = $feed['meta_key'] . '_feeds';
            }

            $action = 'wppayform_integration_notify_' . $feed['meta_key'];
            if (!$entry) {
                $entry = $this->getEntryFormatted($insertId);
            }
            // It's sync
            $processedValues = $feed['settings'];

            unset($processedValues['conditionals']);

            $processedValues = PlaceholderParser::parse($processedValues, $entry);
            $feed['processedValues'] = $processedValues;

            if (apply_filters('wppayform_notifying_async_' . $integrationKey, true, $formId)) {
                // It's async
                $asyncFeeds[] = [
                    'action' => $action,
                    'form_id' => $formId,
                    'origin_id' => $insertId,
                    'feed_id' => $feed['id'],
                    'type' => 'submission_action',
                    'status' => 'pending',
                    'data' => maybe_serialize($feed),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ];
            } else {
                do_action($action, $feed, $formData, $entry, $formId);
            }
        }

        if (!$asyncFeeds) {
            do_action('wppayform_global_notify_completed', $insertId, $formId);
            return;
        }
        // Now we will push this async feeds
        $handler = new AsyncRequest();
        $handler->queueFeeds($asyncFeeds);
        $handler->dispatchAjax(['origin_id' => $insertId]);
    }

    public function checkCondition($parsedValue, $formData, $insertId)
    {
        $conditionSettings = Arr::get($parsedValue, 'conditionals');
        if (!$conditionSettings ||
            !Arr::get($conditionSettings, 'status') ||
            !count(Arr::get($conditionSettings, 'conditions'))
         ) {
            return true;
        }

        return ConditionAssesor::evaluate($parsedValue, $formData);
    }

    private function getEntryFormatted($insertId)
    {
        return (new Submission())->getSubmission($insertId);
    }

    public function cleanUpPassword($insertId, $passwordKeys)
    {
        $submission = new Submission();
        $entry = $submission->getSubmission($insertId);
        $rawData = $entry->form_data_raw;
        $formattedData = $entry->form_data_formatted;
        $replaced = false;
        foreach ($passwordKeys as $passwordKey) {
            if (!empty($formattedData[$passwordKey])) {
                $originalPassword = $formattedData[$passwordKey];
                $truncate = str_repeat("*", strlen($originalPassword)).' '. __('(truncated)', 'wp-payment-form');
                $formattedData[$passwordKey] = $truncate;
                $rawData[$passwordKey] = $truncate;
                $replaced = true;
            }
        }

        if ($replaced) {
            Submission::where('id', $insertId)
            ->update(
                [
                    'form_data_raw' => maybe_serialize($rawData),
                    'form_data_formatted' => maybe_serialize($formattedData)
                ]
            );
        }
    }
}
