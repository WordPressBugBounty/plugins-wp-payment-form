<?php

namespace WPPayForm\App\Services;

use WPPayForm\App\Models\ScheduledActions;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\Form;

class AsyncRequest
{
    /**
     * $prefix The prefix for the identifier
     * @var string
     */
    protected $table = 'wpf_scheduled_actions';

    /**
     * $prefix The prefix for the identifier
     * @var string
     */
    protected $action = 'wppayform_background_process';

    public function queueFeeds($feeds)
    {
        return ScheduledActions::insert($feeds);
    }

    public function dispatchAjax($data = [])
    {
        // PM-SEC-02: Mint a short-lived one-time token tied to origin_id and pass it in
        // the loopback POST body. The handler verifies this token before processing.
        // wp_create_nonce() cannot guard the loopback because the separate HTTP request
        // runs without the submitter's session cookies, so WP nonce checks always fail.
        $originId = isset($data['origin_id']) ? absint($data['origin_id']) : 0;
        if ($originId) {
            $token = wp_generate_password(32, false);
            set_transient('wppayform_bg_' . $originId, $token, 60);
            $data['bg_token'] = $token;
        }

        $args = array(
            'timeout' => 0.1,
            'blocking' => false,
            'body' => $data,
            'cookies' => map_deep($_COOKIE, 'wp_kses_post'),
            'sslverify' => apply_filters('wppayform_https_local_ssl_verify', false),
        );

        $queryArgs = array(
            'action' => $this->action,
            'nonce' => wp_create_nonce($this->action),
        );

        $url = add_query_arg($queryArgs, admin_url('admin-ajax.php'));
        wp_remote_post(esc_url_raw($url), $args);
    }

    public function handleBackgroundCall()
    {
        // This is a server-to-server loopback request fired by dispatchAjax().
        // check_ajax_referer() cannot be used here: the loopback arrives as a separate
        // HTTP request without the submitter's session cookies, so WP nonces fail.
        // We use a short-lived transient token instead (PM-SEC-02).
        if (!wp_doing_ajax()) {
            wp_die('Invalid request', 403);
        }

        $originId = isset($_REQUEST['origin_id']) ? absint($_REQUEST['origin_id']) : 0;

        if (!$originId) {
            wp_send_json_error(['message' => __('Invalid request.', 'wp-payment-form')], 400);
            return;
        }

        $bgToken  = isset($_REQUEST['bg_token']) ? sanitize_text_field(wp_unslash($_REQUEST['bg_token'])) : '';
        $expected = get_transient('wppayform_bg_' . $originId);

        if (!$expected || !hash_equals($expected, $bgToken)) {
            wp_send_json_error(['message' => __('Invalid request.', 'wp-payment-form')], 400);
            return;
        }

        // Consume the token immediately — one request only.
        delete_transient('wppayform_bg_' . $originId);

        $this->processActions($originId);
        echo 'success';
        die();
    }

    public function processActions($originId = false)
    {
        $actionFeedQuery = ScheduledActions::where('status', 'pending');
        if ($originId) {
            $actionFeedQuery = $actionFeedQuery->where('origin_id', $originId);
        }

        $actionFeeds = $actionFeedQuery->get();

        if (!$actionFeeds) {
            return;
        }

        $formCache = [];
        $submissionCache = [];
        $entryCache = [];

        foreach ($actionFeeds as $actionFeed) {
            $action = $actionFeed->action;
            $feed = wppayform_safeUnserialize($actionFeed->data);
            $feed['scheduled_action_id'] = $actionFeed->id;
            if (isset($submissionCache[$actionFeed->origin_id])) {
                $submission = $submissionCache[$actionFeed->origin_id];
            } else {
                $submission = Submission::find($actionFeed->origin_id);
                $submissionCache[$submission->id] = $submission;
            }
            if (isset($formCache[$submission->form_id])) {
                $form = $formCache[$submission->form_id];
            } else {
                $form = Form::where('post_type', 'wp_payform')->find($submission->form_id);
                $formCache[$form->id] = $form;
            }

            if (isset($entryCache[$submission->id])) {
                $entry = $entryCache[$submission->id];
            } else {
                $entry = $this->getEntry($submission->id, $form->ID);
                $entryCache[$submission->id] = $entry;
            }

            $formData = json_decode($submission, true);

            ScheduledActions::where('id', $actionFeed->id)
                ->update([
                    'status' => 'processing',
                    'retry_count' => $actionFeed->retry_count + 1,
                    'updated_at' => current_time('mysql')
                ]);
            do_action($action, $feed, $formData, $entry, $form->ID);
        }

        if ($originId && !empty($form) && !empty($submission)) {
            do_action('wppayform_global_notify_completed', $submission->id, $form->ID);
        }
    }

    private function getEntry($submissionId, $formId)
    {
        return (new Submission())->getSubmission($submissionId);
    }
}
