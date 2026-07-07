<?php

namespace WPPayForm\App\Services;

use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Meta;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Services\AccessControl;
use WPPayForm\App\Services\Protector;
use WPPayForm\App\Modules\PDF\Templates\GiftAidDeclarationTemplate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GiftAidDeclarationPdf — surfaces the Gift Aid declaration PDF.
 *
 * Surfaces (all self-gating on gift_aid_opted_in = '1'):
 *  - Smartcode {gift_aid.declaration_link} in confirmation messages + emails →
 *    public, login-free, Protector-encrypted download link (empty when not opted in).
 *  - Admin single-entry "Download Gift Aid Declaration" button (login + cap/ownership + nonce).
 *
 * Rendering is delegated to GiftAidDeclarationTemplate (PDF module). No feed row.
 *
 * @package WPPayForm\App\Services
 */
class GiftAidDeclarationPdf
{
    public function __construct()
    {
        add_filter('wppayform/all_placeholders', [$this, 'pushPlaceholder'], 10, 2);
        // PlaceholderParser only resolves known groups; inject our value after parse.
        // This is the path used by confirmation messages (ConfirmationHelper) and
        // email notifications (GlobalNotificationManager).
        add_filter('wppayform/submission_placeholders_parsed', [$this, 'injectLink'], 10, 3);

        add_action('wp_ajax_wppayform_gift_aid_declaration_public', [$this, 'downloadPublic']);
        add_action('wp_ajax_nopriv_wppayform_gift_aid_declaration_public', [$this, 'downloadPublic']);
        add_action('wp_ajax_wppayform_gift_aid_declaration', [$this, 'downloadAdmin']);

        add_filter('wppayform_single_entry_widgets', [$this, 'pushButton'], 10, 2);
    }

    /**
     * Register the smartcode in the confirmation/email editor dropdown — only
     * for forms that actually have Gift Aid enabled.
     */
    public function pushPlaceholder($placeholders, $formId)
    {
        if (!Form::hasGiftAid(absint($formId))) {
            return $placeholders;
        }

        $placeholders['gift_aid'] = [
            'title'        => __('Gift Aid', 'wp-payment-form'),
            'placeholders' => [
                'gift_aid_declaration_link' => [
                    'id'    => 'gift_aid_declaration_link',
                    'tag'   => '{gift_aid.declaration_link}',
                    'label' => __('Gift Aid Declaration link', 'wp-payment-form'),
                ],
            ],
        ];

        return $placeholders;
    }

    /**
     * Resolve {gift_aid.declaration_link} after PlaceholderParser has run.
     * Injects an anchor to the public PDF when the submission opted in, an empty
     * string otherwise (self-gating). Only acts when the token is present.
     *
     * @param array $parseItems keyed by braced token (e.g. '{gift_aid.declaration_link}')
     * @param mixed $submission submission object/array (or Entry) passed to parse()
     * @param array $parsables  braced token => inner token
     */
    public function injectLink($parseItems, $submission, $parsables)
    {
        $token = '{gift_aid.declaration_link}';

        if (!is_array($parsables) || !array_key_exists($token, $parsables)) {
            return $parseItems;
        }

        $submissionId = $this->resolveSubmissionId($submission);
        if (!$submissionId) {
            $parseItems[$token] = '';
            return $parseItems;
        }

        $sub = (new Submission())->getSubmission($submissionId);
        if (!$sub || !$this->isOptedIn($submissionId, absint($sub->form_id))) {
            $parseItems[$token] = '';
            return $parseItems;
        }

        $url = $this->publicUrl($submissionId);

        $parseItems[$token] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
            . esc_html__('Download Gift Aid Declaration', 'wp-payment-form')
            . '</a>';

        return $parseItems;
    }

    /**
     * Robustly extract the submission id from whatever parse() was given
     * (submission object, array, or an Entry wrapper).
     */
    private function resolveSubmissionId($submission)
    {
        if (is_object($submission)) {
            if (isset($submission->id)) {
                return absint($submission->id);
            }
            if (method_exists($submission, 'getSubmission')) {
                $inner = $submission->getSubmission();
                return (is_object($inner) && isset($inner->id)) ? absint($inner->id) : 0;
            }
            return 0;
        }
        if (is_array($submission) && isset($submission['id'])) {
            return absint($submission['id']);
        }
        return 0;
    }

    /**
     * Public download — login-free. The only id source is the decrypted token,
     * which is unguessable; opt-in is re-checked before render.
     */
    public function downloadPublic()
    {
        // Lightweight per-IP throttle — a leaked token must not allow unbounded
        // mPDF re-rendering. Short-circuits before the PDF builder is touched.
        $this->throttlePublic();

        $raw = isset($_REQUEST['submission_id'])
            ? sanitize_text_field(wp_unslash($_REQUEST['submission_id']))
            : '';

        $payload = $raw ? Protector::decrypt(base64_decode($raw)) : '';
        if (!$payload || strpos($payload, '|') === false) {
            wp_send_json_error(['message' => __('Invalid request.', 'wp-payment-form')], 403);
        }

        // Token payload is "submissionId|issuedAt" — reject expired links so a
        // leaked PII-bearing URL does not grant indefinite access.
        list($idPart, $issuedPart) = array_pad(explode('|', $payload, 2), 2, '');
        $submissionId = absint($idPart);
        $issuedAt     = absint($issuedPart);

        if (!$submissionId || !$issuedAt || (time() - $issuedAt) > (7 * DAY_IN_SECONDS)) {
            wp_send_json_error(['message' => __('This download link is invalid or has expired.', 'wp-payment-form')], 403);
        }

        $this->render($submissionId);
    }

    /**
     * Per-IP rate limit for the login-free public download (transient counter).
     */
    private function throttlePublic()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($ip === '') {
            return;
        }
        $key  = 'wpf_ga_decl_' . md5($ip);
        $hits = (int) get_transient($key);
        if ($hits >= 20) {
            wp_send_json_error(['message' => __('Too many requests. Please try again later.', 'wp-payment-form')], 429);
        }
        set_transient($key, $hits + 1, 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Admin download — mirrors WPPayFormPdfBuilder::download() auth, plus nonce.
     */
    public function downloadAdmin()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Sorry! You have to login first.', 'wp-payment-form')], 403);
        }

        $nonce = isset($_REQUEST['wppayform_admin_nonce'])
            ? sanitize_text_field(wp_unslash($_REQUEST['wppayform_admin_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'wppayform_admin_nonce')) {
            wp_send_json_error(['message' => __('Invalid request.', 'wp-payment-form')], 403);
        }

        $submissionId = isset($_REQUEST['submission_id']) ? absint($_REQUEST['submission_id']) : 0;
        if (!$submissionId) {
            wp_send_json_error(['message' => __('Invalid request.', 'wp-payment-form')], 400);
        }

        if (!AccessControl::hasTopLevelMenuPermission()) {
            // Non-admins may only download their own submission's declaration.
            $submission    = (new Submission())->getSubmission($submissionId);
            $currentUserId = get_current_user_id();
            $ownerUserId   = ($submission && isset($submission->user_id)) ? absint($submission->user_id) : 0;
            if (!$submission || $ownerUserId === 0 || $ownerUserId !== $currentUserId) {
                wp_send_json_error(['message' => __("You don't have permission to download this declaration.", 'wp-payment-form')], 403);
            }
        }

        $this->render($submissionId);
    }

    /**
     * Add the entry-view download button — shown whenever the submission opted in
     * (works with or without the PDF engine; falls back to a printable HTML page).
     */
    public function pushButton($widgets, $data)
    {
        if (empty($data['submission'])) {
            return $widgets;
        }
        $submission   = $data['submission'];
        $submissionId = absint($submission->id);
        $formId       = absint($submission->form_id);

        if (!$this->isOptedIn($submissionId, $formId)) {
            return $widgets;
        }

        $url = add_query_arg([
            'action'                => 'wppayform_gift_aid_declaration',
            'wppayform_admin_nonce' => wp_create_nonce('wppayform_admin_nonce'),
            'submission_id'         => $submissionId,
        ], admin_url('admin-ajax.php'));

        $widgets['gift_aid_declaration'] = [
            'title'   => __('Gift Aid', 'wp-payment-form'),
            'type'    => 'html_content',
            'content' => '<ul class="ff_list_items"><li><a href="' . esc_url($url) . '" target="_blank" rel="noopener">'
                . '<span style="font-size: 12px;" class="dashicons dashicons-arrow-down-alt"></span>'
                . esc_html__('Download Gift Aid Declaration', 'wp-payment-form')
                . '</a></li></ul>',
        ];

        return $widgets;
    }

    /**
     * Render the declaration inline. Re-checks opt-in, then serves a PDF when the
     * Fluent PDF engine is available, otherwise a printable HTML page — so the
     * declaration works even without Fluent PDF installed.
     */
    private function render($submissionId)
    {
        $submissionId = absint($submissionId);
        $submission   = (new Submission())->getSubmission($submissionId);

        if (!$submission) {
            wp_send_json_error(['message' => __('Submission not found.', 'wp-payment-form')], 404);
        }

        $formId = absint($submission->form_id);

        if (!$this->isOptedIn($submissionId, $formId)) {
            wp_send_json_error(['message' => __('This donor did not opt in to Gift Aid.', 'wp-payment-form')], 403);
        }

        if ($this->isPdfEngineAvailable()) {
            $template = new GiftAidDeclarationTemplate();
            $feed     = [
                'name'       => 'gift-aid-declaration-' . $submissionId,
                'settings'   => [], // declaration content is owned by GiftAidDeclarationHtml
                'appearance' => $this->appearance(),
            ];

            try {
                $template->viewPDF($submissionId, $feed);
                return;
            } catch (\Throwable $e) {
                // Fall through to the HTML view rather than failing the download.
            }
        }

        $this->renderHtml($submissionId);
    }

    /**
     * Printable HTML fallback — no PDF engine required (browser "Save as PDF").
     */
    private function renderHtml($submissionId)
    {
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        // PII-bearing page — block framing (clickjacking) defence-in-depth.
        header('X-Frame-Options: DENY');

        // Document HTML is assembled with esc_* helpers inside the builder.
        echo (new GiftAidDeclarationHtml())->document($submissionId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * The Fluent PDF engine (mPDF via fluentforms-pdf) is active.
     */
    private function isPdfEngineAvailable()
    {
        return defined('FLUENT_PDF') && class_exists('\WPPayForm\App\Modules\PDF\Templates\TemplateManager');
    }

    private function publicUrl($submissionId)
    {
        // Payload carries an issued-at timestamp so downloadPublic() can expire
        // the link (see PUBLIC link TTL). Protector::encrypt is encrypt-then-HMAC,
        // so the payload is tamper-proof and unguessable.
        $payload = absint($submissionId) . '|' . time();
        $token   = base64_encode(Protector::encrypt($payload));

        return add_query_arg([
            'action'        => 'wppayform_gift_aid_declaration_public',
            'submission_id' => $token,
        ], admin_url('admin-ajax.php'));
    }

    private function isOptedIn($submissionId, $formId)
    {
        $row = (new Meta())
            ->where('meta_group', 'wpf_submissions')
            ->where('option_id', absint($submissionId))
            ->where('form_id', absint($formId))
            ->where('meta_key', 'gift_aid_opted_in')
            ->first();

        return $row && (string) $row->meta_value === '1';
    }

    /**
     * Global PDF appearance (paper/font/color) used to render the declaration.
     * Mirrors WPPayFormPdfBuilder default global settings.
     */
    private function appearance()
    {
        $defaults = [
            'paper_size'         => 'A4',
            'orientation'        => 'P',
            'font_family'        => 'dejavusans',
            'font_size'          => '14',
            'font_color'         => '#323232',
            'accent_color'       => '#989797',
            'heading_color'      => '#000000',
            'language_direction' => 'ltr',
        ];

        $option = get_option('_fluent_pdf_settings');

        return is_array($option) ? wp_parse_args($option, $defaults) : $defaults;
    }
}
