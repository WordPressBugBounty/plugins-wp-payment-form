<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Mappers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EmailNotificationMapper — translates GiveWP email template tags to Paymattic smart tags.
 *
 * Converts GiveWP notification subjects, messages, and recipient settings
 * into the notification structure expected by Paymattic's global notification system.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Mappers
 * @since   4.6.21
 */
class EmailNotificationMapper
{
    private static $tagMap = [
        '{payment_id}'             => '{submission.id}',
        '{payment_total}'          => '{submission.payment_total}',
        '{donor}'                  => '{submission.customer_name}',
        '{name}'                   => '{submission.customer_name}',
        '{email}'                  => '{submission.customer_email}',
        '{sitename}'               => '{wp:site_title}',
        '{site_url}'               => '{wp:site_url}',
        '{date}'                   => '{other:date}',
        '{receipt_link}'           => '{submission.payment_receipt}',
        '{donation_history_link}'  => '{submission.payment_receipt}',
        '{payment_method}'         => '{submission.payment_method}',
        '{transaction_id}'         => '{submission.transaction_id}',
        '{form_title}'             => '{wp:post_title}',
    ];

    private static $typeConfig = [
        'donation-receipt' => [
            'title'          => 'Donation Receipt',
            'sending_action' => 'wppayform/form_payment_success',
            'email_to'       => '{submission.customer_email}',
            'has_recipients' => false,
        ],
        'new-donation' => [
            'title'          => 'New Donation Notification',
            'sending_action' => 'wppayform/form_payment_success',
            'email_to'       => '{wp:admin_email}',
            'has_recipients' => true,
        ],
        'failed-donation' => [
            'title'          => 'Failed Donation Notification',
            'sending_action' => 'wppayform/form_payment_failed',
            'email_to'       => '{wp:admin_email}',
            'has_recipients' => true,
        ],
        'offline-donation-instruction' => [
            'title'          => 'Offline Donation Instructions',
            'sending_action' => 'wppayform/after_form_submission_complete',
            'email_to'       => '{submission.customer_email}',
            'has_recipients' => false,
        ],
        'new-offline-donation' => [
            'title'          => 'New Offline Donation Notification',
            'sending_action' => 'wppayform/after_form_submission_complete',
            'email_to'       => '{wp:admin_email}',
            'has_recipients' => true,
        ],
    ];

    private static $defaultNotifications = [
        'donation-receipt' => [
            'subject' => 'Donation Receipt',
            'message' => '<p>Dear {submission.customer_name},</p><p>Thank you for your generous donation of {submission.payment_total}.</p><p>{submission.payment_receipt}</p>',
        ],
        'new-donation' => [
            'subject' => 'New Donation - #{submission.id}',
            'message' => '<p>A new donation of {submission.payment_total} was received from {submission.customer_name} ({submission.customer_email}).</p><p>{submission.all_input_field_html}</p>',
        ],
    ];

    public static function mapNotifications(array $giveNotifications, string $fromName = '', string $fromEmail = ''): array
    {
        $resolvedFromName  = $fromName  ?: '{wp:site_title}';
        $resolvedFromEmail = $fromEmail ?: '{wp:admin_email}';

        $mapped     = [];
        $indexedGive = [];

        foreach ($giveNotifications as $notification) {
            $type = $notification['type'] ?? '';
            if ($type !== '') {
                $indexedGive[$type] = $notification;
            }
        }

        foreach (self::$typeConfig as $type => $config) {
            $give = $indexedGive[$type] ?? null;

            if ($give !== null && ($give['is_disabled'] ?? false)) {
                continue;
            }

            if ($give !== null) {
                $subject = self::replaceTags((string) ($give['subject'] ?? ''));
                $message = self::replaceTags((string) ($give['message'] ?? ''));
            } else {
                $subject = '';
                $message = '';
            }

            if ($subject === '' && isset(self::$defaultNotifications[$type])) {
                $subject = self::$defaultNotifications[$type]['subject'];
            }

            if ($message === '' && isset(self::$defaultNotifications[$type])) {
                $message = self::$defaultNotifications[$type]['message'];
            }

            if ($config['has_recipients'] && $give !== null && !empty($give['recipients'])) {
                $emailTo = implode(',', $give['recipients']);
            } else {
                $emailTo = $config['email_to'];
            }

            $mapped[] = [
                'title'           => $config['title'],
                'status'          => 'active',
                'sending_action'  => $config['sending_action'],
                'email_to'        => $emailTo,
                'email_subject'   => $subject,
                'email_body'      => $message,
                'from_name'       => $resolvedFromName,
                'from_email'      => $resolvedFromEmail,
                'reply_to'        => '',
                'bcc_to'          => '',
                'cc_to'           => '',
                'email_footer'    => '',
                'attachments'     => [],
                'pdf_attachments' => [],
                'conditionals'    => [
                    'status'     => false,
                    'type'       => 'All',
                    'conditions' => [],
                ],
            ];
        }

        if (empty($mapped)) {
            $mapped = self::buildDefaults($resolvedFromName, $resolvedFromEmail);
        }

        return $mapped;
    }

    private static function replaceTags(string $text): string
    {
        $text = str_replace(
            array_keys(self::$tagMap),
            array_values(self::$tagMap),
            $text
        );

        $text = preg_replace('/\{(?!submission\.|wp:|other:)[^}]+\}/', '', (string) $text);

        return $text;
    }

    private static function buildDefaults(string $fromName, string $fromEmail): array
    {
        $defaults = [];

        foreach (['donation-receipt', 'new-donation'] as $type) {
            $config = self::$typeConfig[$type];
            $def    = self::$defaultNotifications[$type];

            $defaults[] = [
                'title'           => $config['title'],
                'status'          => 'active',
                'sending_action'  => $config['sending_action'],
                'email_to'        => $config['email_to'],
                'email_subject'   => $def['subject'],
                'email_body'      => $def['message'],
                'from_name'       => $fromName,
                'from_email'      => $fromEmail,
                'reply_to'        => '',
                'bcc_to'          => '',
                'cc_to'           => '',
                'email_footer'    => '',
                'attachments'     => [],
                'pdf_attachments' => [],
                'conditionals'    => [
                    'status'     => false,
                    'type'       => 'All',
                    'conditions' => [],
                ],
            ];
        }

        return $defaults;
    }
}
