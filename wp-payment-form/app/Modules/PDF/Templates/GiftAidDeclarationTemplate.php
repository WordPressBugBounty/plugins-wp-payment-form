<?php

namespace WPPayForm\App\Modules\PDF\Templates;

use WPPayForm\App\Models\Submission;
use WPPayForm\App\Services\GiftAidDeclarationHtml;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GiftAidDeclarationTemplate — renders the Gift Aid declaration as a PDF via the
 * Fluent PDF engine. The declaration HTML itself is built by the engine-agnostic
 * GiftAidDeclarationHtml service, so the same markup also powers the no-PDF HTML
 * fallback in GiftAidDeclarationPdf.
 *
 * Renders ONLY when the submission opted in (gift_aid_opted_in = '1').
 *
 * @package WPPayForm\App\Modules\PDF\Templates
 */
class GiftAidDeclarationTemplate extends TemplateManager
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getSettingsFields()
    {
        // No feed UI — the declaration uses fixed defaults.
        return [];
    }

    public function getDefaultSettings($form)
    {
        // The declaration content (charity name, logo, body, amounts) is owned by
        // GiftAidDeclarationHtml — this template no longer reads $feed['settings'].
        // Kept only to satisfy the TemplateManager feed shape.
        return [];
    }

    public function generatePdf($submissionId, $feed, $outPut, $fileName = '')
    {
        $submissionId = absint($submissionId);
        $submission   = (new Submission())->getSubmission($submissionId);

        if (!$submission) {
            return $this->renderNotice(__('Submission not found.', 'wp-payment-form'), $feed, $outPut, $fileName);
        }

        $builder = new GiftAidDeclarationHtml();

        // Opt-in guard — never render a declaration for a non-opted-in donor.
        if (!$builder->isOptedIn($submissionId, $submission->form_id)) {
            return $this->renderNotice(__('This donor did not opt in to Gift Aid.', 'wp-payment-form'), $feed, $outPut, $fileName);
        }

        $htmlBody = $builder->body($submissionId);

        if (!$fileName) {
            $fileName = sanitize_file_name('gift-aid-declaration-' . $submissionId);
        }

        return $this->pdfBuilder($fileName, $feed, $htmlBody, '', $outPut);
    }

    private function renderNotice($message, $feed, $outPut, $fileName)
    {
        if (!$fileName) {
            $fileName = sanitize_file_name('gift-aid-declaration');
        }
        $html = '<p>' . esc_html($message) . '</p>';

        return $this->pdfBuilder($fileName, $feed, $html, '', $outPut);
    }
}
