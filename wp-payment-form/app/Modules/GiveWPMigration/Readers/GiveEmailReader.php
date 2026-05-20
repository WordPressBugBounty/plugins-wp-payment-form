<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Readers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GiveEmailReader — reads GiveWP email notification settings for migration.
 *
 * Loads per-form and global notification configuration (subject, message,
 * recipients) for each GiveWP notification type so they can be mapped to
 * Paymattic notifications by EmailNotificationMapper.
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Readers
 * @since   4.6.21
 */
class GiveEmailReader
{
    private static $notificationIds = [
        'donation-receipt',
        'new-donation',
        'failed-donation',
        'offline-donation-instruction',
        'new-offline-donation',
    ];

    private static $noRecipientTypes = [
        'donation-receipt',
        'offline-donation-instruction',
    ];

    public static function getFormEmailNotifications(int $formId, array $meta = []): array
    {
        $hasCustomOptions = self::resolveMeta($formId, '_give_email_options', $meta);

        $results = [];

        foreach (self::$notificationIds as $id) {
            if ($hasCustomOptions === 'enabled') {
                $status  = self::resolveMeta($formId, "_give_{$id}_notification", $meta) ?: 'global';
                $subject = self::resolveMeta($formId, "_give_{$id}_email_subject", $meta);
                $message = self::resolveMeta($formId, "_give_{$id}_email_message", $meta);
                $recipientRaw = in_array($id, self::$noRecipientTypes, true)
                    ? ''
                    : self::resolveMeta($formId, "_give_{$id}_recipient", $meta);
            } else {
                $status       = 'global';
                $subject      = '';
                $message      = '';
                $recipientRaw = '';
            }

            if ($status === 'global' || $subject === '' || $subject === false) {
                $subject = function_exists('give_get_option') ? give_get_option("{$id}_email_subject", '') : '';
            }

            if ($status === 'global' || $message === '' || $message === false) {
                $message = function_exists('give_get_option') ? give_get_option("{$id}_email_message", '') : '';
            }

            if (!in_array($id, self::$noRecipientTypes, true)) {
                if ($status === 'global' || $recipientRaw === '' || $recipientRaw === false) {
                    $recipientRaw = function_exists('give_get_option') ? give_get_option("{$id}_recipient", '') : '';
                }
            }

            $recipients = self::parseRecipients($recipientRaw);

            $fromName  = sanitize_text_field((string) self::resolveMeta($formId, '_give_from_name', $meta));
            $fromEmail = sanitize_email((string) self::resolveMeta($formId, '_give_from_email', $meta));

            $results[] = [
                'type'        => $id,
                'status'      => sanitize_text_field((string) $status),
                'subject'     => sanitize_text_field((string) $subject),
                'message'     => wp_kses_post((string) $message),
                'recipients'  => $recipients,
                'from_name'   => $fromName,
                'from_email'  => $fromEmail,
                'is_disabled' => ($status === 'disabled'),
            ];
        }

        return $results;
    }

    private static function resolveMeta(int $formId, string $key, array $meta)
    {
        if (!empty($meta)) {
            return isset($meta[$key]) ? $meta[$key] : false;
        }

        return self::getFormMeta($formId, $key);
    }

    private static function getFormMeta(int $formId, string $key)
    {
        if (function_exists('give_get_meta')) {
            return give_get_meta($formId, $key, true);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'give_formmeta';

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$table} WHERE form_id = %d AND meta_key = %s",
                $formId,
                $key
            )
        );
    }

    private static function parseRecipients($raw): array
    {
        if (empty($raw)) {
            return [];
        }

        // SEC-HIGH-003: wppayform_safeUnserialize uses allowed_classes=false
        // to block PHP Object Injection from untrusted GiveWP meta payloads.
        $unserialized = wppayform_safeUnserialize($raw);

        if (!is_array($unserialized)) {
            $email = sanitize_email((string) $raw);
            return $email !== '' ? [$email] : [];
        }

        $emails = [];
        foreach ($unserialized as $entry) {
            if (is_array($entry) && !empty($entry['email'])) {
                $email = sanitize_email((string) $entry['email']);
                if ($email !== '') {
                    $emails[] = $email;
                }
            } elseif (is_string($entry)) {
                $email = sanitize_email($entry);
                if ($email !== '') {
                    $emails[] = $email;
                }
            }
        }

        return $emails;
    }
}
