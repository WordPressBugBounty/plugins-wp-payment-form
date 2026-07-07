<?php

namespace WPPayForm\App\Services;

use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Meta;
use WPPayForm\App\Models\Submission;
use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GiftAidDeclarationHtml — builds the Gift Aid declaration markup WITHOUT any
 * dependency on the Fluent PDF engine, so the declaration works even when Fluent
 * PDF is not installed:
 *
 *  - body()     : the inner declaration HTML, shared by the PDF template and the
 *                 standalone HTML view.
 *  - document() : a full, printable HTML page (browser "Save as PDF") used as the
 *                 fallback when the PDF engine is unavailable.
 *
 * Renders ONLY for opted-in donations — callers must guard with isOptedIn().
 *
 * @package WPPayForm\App\Services
 */
class GiftAidDeclarationHtml
{
    /** @var array per-instance cache of `_pdf_feeds` rows keyed by form id */
    private $feedRowsCache = [];

    /** @var array per-instance cache of a submission's wpf_meta (key => value) */
    private $metaCache = [];

    public function isOptedIn($submissionId, $formId)
    {
        return $this->getMeta(absint($submissionId), absint($formId), 'gift_aid_opted_in') === '1';
    }

    /**
     * Full standalone printable HTML page — no PDF engine required.
     */
    public function document($submissionId)
    {
        $body = $this->body($submissionId);

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php esc_html_e('Charity Gift Aid Declaration', 'wp-payment-form'); ?></title>
    <style>
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #2b2b2b; max-width: 760px; margin: 24px auto; line-height: 1.6; }
        h1 { text-align: center; }
        .wpf_ga_actions { text-align: center; margin: 24px 0; }
        .wpf_ga_actions button { padding: 8px 18px; font-size: 14px; cursor: pointer; }
        @media print { .wpf_ga_actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body is assembled and each dynamic value is escaped inside body(). ?>
    <div class="wpf_ga_actions">
        <button type="button" onclick="window.print()"><?php esc_html_e('Print / Save as PDF', 'wp-payment-form'); ?></button>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Inner declaration body — shared by the PDF template and the HTML view.
     * Assumes the submission opted in (callers guard).
     */
    public function body($submissionId)
    {
        $submissionId = absint($submissionId);
        $submission   = (new Submission())->getSubmission($submissionId);

        if (!$submission) {
            return '<p>' . esc_html__('Submission not found.', 'wp-payment-form') . '</p>';
        }

        $formId = absint($submission->form_id);

        // Gift-Aidable amount: net donation (pre-fee) when fee recovery applied
        // (pro `_wpf_net_donation_amount`), otherwise the submission total. Cents.
        $netCents    = $this->getMeta($submissionId, $formId, '_wpf_net_donation_amount');
        $amountCents = ($netCents !== '') ? absint($netCents) : absint($submission->payment_total);

        $currencySettings = Form::getCurrencyAndLocale($formId);
        $amountFormatted  = wpPayFormFormattedMoney($amountCents, $currencySettings);

        $charityName     = $this->resolveCharityName($formId);
        $declarationBody = $this->declarationBodyText();
        $logo            = $this->resolveLogo($formId);

        $donorName = trim((string) $submission->customer_name);

        $addressLines = array_filter([
            $this->getMeta($submissionId, $formId, 'gift_aid_address'),
            $this->getMeta($submissionId, $formId, 'gift_aid_address_line2'),
            trim($this->getMeta($submissionId, $formId, 'gift_aid_city') . ' ' . $this->getMeta($submissionId, $formId, 'gift_aid_postcode')),
        ], static function ($line) {
            return trim((string) $line) !== '';
        });

        $declarationDate = $submission->created_at
            ? wp_date(get_option('date_format'), strtotime($submission->created_at))
            : '';

        // Round "opted-in" badge — inline SVG so it renders as a true circle in
        // mPDF (CSS border-radius on a span background is not honoured by mPDF)
        // and renders identically in the browser HTML view.
        $checkSvg   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><circle cx="8" cy="8" r="8" fill="#2b2b2b"/><path d="M4.5 8.2L7 10.7L11.6 5.3" fill="none" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $checkBadge = '<img src="data:image/svg+xml;base64,' . base64_encode($checkSvg) . '" width="14" height="14" style="vertical-align:middle;" alt="" />';

        ob_start();
        ?>
        <div class="wpf_gift_aid_declaration" style="padding: 10px 40px;">
            <?php if ($logo) : ?>
                <div class="center-image-wrapper" style="text-align:center; margin-bottom:20px;">
                    <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($charityName); ?>" style="max-width:200px; max-height:100px;" />
                </div>
            <?php endif; ?>

            <h1 style="text-align:center; margin-bottom:30px;"><?php esc_html_e('Charity Gift Aid Declaration', 'wp-payment-form'); ?></h1>

            <p>
                <?php echo $checkBadge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline base64-encoded SVG badge built from a hardcoded string, no dynamic input. ?>
                <?php
                printf(
                    /* translators: 1: donation amount, 2: charity name */
                    esc_html__('I want to Gift Aid my donation of %1$s and any donations I make in the future or have made in the past 4 years to %2$s.', 'wp-payment-form'),
                    esc_html($amountFormatted),
                    esc_html($charityName)
                );
                ?>
            </p>

            <p><strong><?php esc_html_e('Name of Charity:', 'wp-payment-form'); ?></strong> <?php echo esc_html($charityName); ?></p>

            <div class="wpf_gift_aid_statement"><?php echo wp_kses_post(wpautop($declarationBody)); ?></div>

            <h3 style="margin-top:30px;"><?php esc_html_e('Donor Details', 'wp-payment-form'); ?></h3>

            <?php if ($donorName !== '') : ?>
                <p><strong><?php esc_html_e('Name:', 'wp-payment-form'); ?></strong> <?php echo esc_html($donorName); ?></p>
            <?php endif; ?>

            <?php if (!empty($addressLines)) : ?>
                <p>
                    <strong><?php esc_html_e('Address:', 'wp-payment-form'); ?></strong><br />
                    <?php echo esc_html(implode(', ', $addressLines)); ?><br />
                    <?php esc_html_e('United Kingdom', 'wp-payment-form'); ?>
                </p>
            <?php endif; ?>

            <p><strong><?php esc_html_e('Gift Aid Status:', 'wp-payment-form'); ?></strong> <?php esc_html_e('Accepted', 'wp-payment-form'); ?></p>

            <?php if ($declarationDate !== '') : ?>
                <p><strong><?php esc_html_e('Date:', 'wp-payment-form'); ?></strong> <?php echo esc_html($declarationDate); ?></p>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    private function declarationBodyText()
    {
        return __('I am a UK taxpayer and want this donation to qualify for Gift Aid. I understand that the charity will reclaim 25p for every £1 I donate and that I must have paid at least that amount in UK Income Tax and/or Capital Gains Tax during the tax year.', 'wp-payment-form');
    }

    /**
     * Charity name: PDF feed Business Name → WP Site Title (GiveWP's {sitename}).
     */
    private function resolveCharityName($formId)
    {
        $fromFeed = $this->feedSetting($formId, 'business_name');

        return ($fromFeed !== '') ? $fromFeed : get_bloginfo('name');
    }

    /**
     * Logo: PDF feed Business Logo → global business logo.
     */
    private function resolveLogo($formId)
    {
        $logo = $this->feedSetting($formId, 'logo');
        if (!$logo && class_exists('\WPPayForm\App\Http\Controllers\GlobalSettingsController')) {
            $paymentSettings = (new \WPPayForm\App\Http\Controllers\GlobalSettingsController())->currencies();
            $logo            = (string) Arr::get($paymentSettings, 'business_logo', '');
        }

        return $logo;
    }

    /**
     * Read one of the submission's wpf_meta values. All of a submission's meta is
     * loaded once and cached, so the several reads per render (opt-in, net amount,
     * address parts) share a single query — mirroring the feed-row cache.
     */
    private function getMeta($submissionId, $formId, $key)
    {
        $submissionId = absint($submissionId);

        if (!array_key_exists($submissionId, $this->metaCache)) {
            $rows = (new Meta())
                ->where('meta_group', 'wpf_submissions')
                ->where('option_id', $submissionId)
                ->where('form_id', absint($formId))
                ->get();

            $map = [];
            foreach ($rows as $row) {
                $map[$row->meta_key] = (string) $row->meta_value;
            }
            $this->metaCache[$submissionId] = $map;
        }

        return isset($this->metaCache[$submissionId][$key]) ? $this->metaCache[$submissionId][$key] : '';
    }

    /**
     * First non-empty `settings.{$key}` across the form's PDF feeds.
     */
    private function feedSetting($formId, $key)
    {
        foreach ($this->feedRows($formId) as $feed) {
            $data  = json_decode($feed->meta_value, true);
            $value = is_array($data) ? trim((string) Arr::get($data, 'settings.' . $key, '')) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The form's `_pdf_feeds` rows, fetched once and cached so charity name + logo
     * lookups share a single query.
     */
    private function feedRows($formId)
    {
        $formId = absint($formId);

        if (!array_key_exists($formId, $this->feedRowsCache)) {
            $this->feedRowsCache[$formId] = (new Meta())
                ->where('form_id', $formId)
                ->where('meta_key', '_pdf_feeds')
                ->get();
        }

        return $this->feedRowsCache[$formId];
    }
}
