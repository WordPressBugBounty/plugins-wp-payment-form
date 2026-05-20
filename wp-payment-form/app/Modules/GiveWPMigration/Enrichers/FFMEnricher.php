<?php

namespace WPPayForm\App\Modules\GiveWPMigration\Enrichers;

if (!defined('ABSPATH')) {
    exit;
}

use WPPayForm\App\Modules\GiveWPMigration\MigrationState;

/**
 * FFMEnricher — enriches already-migrated submissions with GiveWP Form Field Manager data.
 *
 * GiveWP's Form Field Manager (FFM) add-on allows admins to add custom fields to
 * donation forms. Custom field definitions are stored in standard wp_postmeta under
 * the meta_key 'give-form-fields' on the give_forms post. Donor responses are stored
 * in {prefix}give_donationmeta keyed by the field's `name` attribute.
 *
 * This enricher:
 *   1. Reads the field definitions from wp_postmeta for each GiveWP form.
 *   2. For each field definition, loads all response rows from give_donationmeta.
 *   3. Writes ffm_{field_name} meta rows to wpf_meta on the Paymattic submission.
 *   4. Updates wpf_submissions.form_data_formatted with a structured field map
 *      that Paymattic's admin UI can render as key-label-value triples.
 *
 * Field definitions source:
 *   get_post_meta($giveFormId, 'give-form-fields', true) — this is STANDARD wp_postmeta,
 *   NOT the give_formmeta table. The return value is either a serialized array (old FFM)
 *   or a PHP array (already unserialized by get_post_meta). We call maybe_unserialize()
 *   to normalise.
 *
 * SECURITY NOTE on serialized data:
 *   We call maybe_unserialize() on the value returned by get_post_meta() because
 *   WordPress core calls it internally before returning, making the output already
 *   a PHP array in modern WP. We NEVER call unserialize() on give_donationmeta
 *   values (donor-supplied data) — those are stored as plain strings and read as-is.
 *
 * @see §8 of the migration brief (FFM custom field migration)
 *
 * @package WPPayForm\App\Modules\GiveWPMigration\Enrichers
 * @since   4.6.21
 */
class FFMEnricher
{
    /**
     * Skipped field types — these don't produce donor-visible values and
     * storing them in Paymattic meta would add noise without value.
     *
     * @var array<string>
     */
    private static $skippedTypes = ['html', 'section', 'action_hook'];

    /**
     * GiveWP v3 block names whose inner content should be recursed into
     * (structural wrappers, not data fields).
     *
     * @var array<string>
     */
    private static $v3StructuralBlocks = [
        'givewp/section',
        'givewp/donation-amount',
        'givewp/donor-name',
        'givewp/email',
        'givewp/donation-summary',
        'givewp/payment-gateways',
    ];

    /**
     * GiveWP v3 block names to skip entirely (no donor data value).
     *
     * @var array<string>
     */
    private static $v3SkipBlocks = [
        'givewp-form-field-manager/html',
    ];

    /**
     * GiveWP v3 block name → normalized Paymattic input_type.
     *
     * @var array<string,string>
     */
    private static $v3BlockTypeMap = [
        'givewp/text'                           => 'text',
        'givewp-form-field-manager/checkbox'    => 'checkbox',
        'givewp-form-field-manager/dropdown'    => 'select',
        'givewp-form-field-manager/email'       => 'text',
        'givewp-form-field-manager/consent'     => 'consent',
        'givewp-form-field-manager/date'        => 'text',
        'givewp-form-field-manager/file-upload' => 'file_upload',
        'givewp-form-field-manager/hidden'      => 'text',
        'givewp-form-field-manager/radio'       => 'radio',
        'givewp-form-field-manager/phone'       => 'phone',
        'givewp-form-field-manager/url'         => 'text',
        'givewp-form-field-manager/textarea'    => 'textarea',
    ];

    /**
     * Return normalized field definitions for a GiveWP form.
     *
     * Tries v2 first (give-form-fields postmeta). Falls back to v3
     * (formBuilderFields JSON blocks in wp_postmeta) when v2 is empty.
     *
     * Each returned element has keys:
     *   name       — raw fieldName (used as give_donationmeta meta_key)
     *   input_type — normalized type string
     *   label      — display label (sanitized)
     *   required   — 'yes' or 'no'
     *   options    — array of ['label'=>..., 'value'=>...] (empty for non-choice fields)
     *
     * @param int $giveFormId The give_forms post ID.
     *
     * @return array Normalized field definition array (may be empty).
     */
    public static function getAllFieldDefs(int $giveFormId): array
    {
        $v2 = self::getV2FieldDefs($giveFormId);
        if (!empty($v2)) {
            return $v2;
        }

        return self::getV3FieldDefs($giveFormId);
    }

    /**
     * Read v2 FFM field definitions from give-form-fields wp_postmeta.
     *
     * @param int $giveFormId
     * @return array Normalized field defs, may be empty.
     */
    private static function getV2FieldDefs(int $giveFormId): array
    {
        $raw  = get_post_meta($giveFormId, 'give-form-fields', true);
        $defs = $raw ? wppayform_safeUnserialize($raw) : [];

        if (!is_array($defs) || empty($defs)) {
            return [];
        }

        $skipTypes = ['html', 'section', 'action_hook'];
        $fields    = [];

        foreach ($defs as $field) {
            if (!is_array($field)) {
                continue;
            }

            $inputType = sanitize_text_field($field['input_type'] ?? '');
            $name      = sanitize_text_field($field['name']       ?? '');
            $label     = sanitize_text_field($field['label']      ?? $name);

            if (empty($name) || in_array($inputType, $skipTypes, true)) {
                continue;
            }

            $rawOptions = $field['options'] ?? [];
            $options    = [];
            if (is_array($rawOptions)) {
                foreach ($rawOptions as $opt) {
                    $optLabel  = sanitize_text_field((string) $opt);
                    $options[] = ['label' => $optLabel, 'value' => $optLabel];
                }
            }

            $fields[] = [
                'name'       => $name,
                'input_type' => $inputType,
                'label'      => $label,
                'required'   => (($field['required'] ?? 'no') === 'yes') ? 'yes' : 'no',
                'options'    => $options,
            ];
        }

        return $fields;
    }

    /**
     * Read v3 field definitions from formBuilderFields wp_postmeta JSON.
     *
     * GiveWP v3 stores field config as a JSON array of block objects in
     * the formBuilderFields postmeta key. Custom fields use block names
     * under givewp-form-field-manager/* or givewp/text.
     *
     * @param int $giveFormId
     * @return array Normalized field defs, may be empty.
     */
    private static function getV3FieldDefs(int $giveFormId): array
    {
        $raw = get_post_meta($giveFormId, 'formBuilderFields', true);

        if (empty($raw)) {
            return [];
        }

        $blocks = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($blocks)) {
            return [];
        }

        return self::extractV3Blocks($blocks);
    }

    /**
     * Recursively extract normalized field defs from a GiveWP v3 block array.
     *
     * @param array $blocks Array of block objects (may include innerBlocks).
     * @return array Flat array of normalized field defs.
     */
    private static function extractV3Blocks(array $blocks): array
    {
        $fields = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $blockName   = (string) ($block['name']       ?? '');
            $attrs       = (array)  ($block['attributes'] ?? []);
            $innerBlocks = (array)  ($block['innerBlocks'] ?? []);

            // Structural wrappers: skip the block itself, recurse innerBlocks.
            if (in_array($blockName, self::$v3StructuralBlocks, true)) {
                $fields = array_merge($fields, self::extractV3Blocks($innerBlocks));
                continue;
            }

            // Explicitly skipped blocks (e.g. HTML display blocks).
            if (in_array($blockName, self::$v3SkipBlocks, true)) {
                continue;
            }

            // multi-select: fieldType attribute determines select vs checkbox.
            if ($blockName === 'givewp-form-field-manager/multi-select') {
                $normalizedType = (($attrs['fieldType'] ?? '') === 'dropdown') ? 'select' : 'checkbox';
            } elseif (isset(self::$v3BlockTypeMap[$blockName])) {
                $normalizedType = self::$v3BlockTypeMap[$blockName];
            } else {
                // Unknown block: recurse innerBlocks in case there are custom fields inside.
                if (!empty($innerBlocks)) {
                    $fields = array_merge($fields, self::extractV3Blocks($innerBlocks));
                }
                continue;
            }

            $fieldName = sanitize_text_field($attrs['fieldName'] ?? '');
            if (empty($fieldName)) {
                continue;
            }

            // Build options for choice field types.
            $options = [];
            if (!empty($attrs['options']) && is_array($attrs['options'])) {
                foreach ($attrs['options'] as $opt) {
                    $optLabel = sanitize_text_field((string) ($opt['label'] ?? ''));
                    if ($optLabel !== '') {
                        $options[] = ['label' => $optLabel, 'value' => $optLabel];
                    }
                }
            }

            $fields[] = [
                'name'       => $fieldName,
                'input_type' => $normalizedType,
                'label'      => sanitize_text_field($attrs['label'] ?? $fieldName),
                'required'   => !empty($attrs['isRequired']) ? 'yes' : 'no',
                'options'    => $options,
            ];

            // Recurse into innerBlocks if any (unusual for leaf fields but safe).
            if (!empty($innerBlocks)) {
                $fields = array_merge($fields, self::extractV3Blocks($innerBlocks));
            }
        }

        return $fields;
    }

    /**
     * Check whether the GiveWP Form Field Manager plugin is active.
     *
     * @return bool True when FFM is active and enrichment can proceed.
     */
    public static function isActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('give-form-field-manager/give-form-field-manager.php');
    }

    /**
     * Enrich a batch of already-migrated submissions with FFM custom field responses.
     *
     * For each donation in $donationIdMap, loads the field definitions for its
     * GiveWP form and writes each custom field response as a wpf_meta row.
     * Also updates wpf_submissions.form_data_formatted so Paymattic can render
     * the responses in the admin submission view.
     *
     * @param array $donationIdMap  Map of give_donation_id (int) → array with keys:
     *                              'wpf_submission_id' (int) and 'give_form_id' (int).
     *                              Example: [42 => ['wpf_submission_id' => 7, 'give_form_id' => 12], ...]
     * @param array $formMap        GiveWP form_id → Paymattic form_id lookup table.
     *                              From MigrationState::get()['form_map'].
     * @param bool  $dryRun         When true, counts enrichable records but writes nothing.
     *
     * @return array{enriched: int, skipped: int}
     *   enriched: number of submissions that received at least one FFM field row.
     *   skipped:  number skipped (no FFM fields found, or no responses, or already done).
     */
    public static function enrichBatch(array $donationIdMap, array $formMap, bool $dryRun = false): array
    {
        $enriched = 0;
        $skipped  = 0;

        global $wpdb;

        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- $wpdb->prefix is a trusted, server-controlled value.
        $donationMetaTable = $wpdb->prefix . 'give_donationmeta';
        $metaTable         = $wpdb->prefix . 'wpf_meta';
        $submissionsTable  = $wpdb->prefix . 'wpf_submissions';
        // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter
        $now               = current_time('mysql');

        // Cache field definitions per form to avoid redundant get_post_meta calls
        // when multiple donations belong to the same form.
        $fieldDefsCache = [];

        foreach ($donationIdMap as $giveDonationId => $donationEntry) {
            // $donationEntry may be a plain submission ID (int) for backward compatibility
            // or an array with 'wpf_submission_id' and 'give_form_id'.
            if (is_array($donationEntry)) {
                $wpfSubmissionId = absint($donationEntry['wpf_submission_id'] ?? 0);
                $giveFormId      = absint($donationEntry['give_form_id']      ?? 0);
            } else {
                // Fallback: plain int value — we cannot enrich without the form ID.
                $skipped++;
                continue;
            }

            $giveDonationId = absint($giveDonationId);

            if ($giveDonationId < 1 || $wpfSubmissionId < 1 || $giveFormId < 1) {
                $skipped++;
                continue;
            }

            // ------------------------------------------------------------------
            // Idempotency: check for the sentinel key 'give_ffm_enriched'.
            // Written at the end of each successful per-submission enrichment.
            // Using a specific key avoids false negatives when a submission has
            // fields whose names happen to start with 'ffm_' for other reasons.
            // ------------------------------------------------------------------

            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $metaTable composed from $wpdb->prefix only.
            $alreadyEnriched = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$metaTable}
                     WHERE meta_group = %s
                       AND option_id  = %d
                       AND meta_key   = %s
                     LIMIT 1",
                    'wpf_submissions',
                    $wpfSubmissionId,
                    'give_ffm_enriched'
                )
            );

            if ($alreadyEnriched) {
                $skipped++;
                continue;
            }

            // ------------------------------------------------------------------
            // Load field definitions for this GiveWP form.
            //
            // get_post_meta() with a string key returns the raw value.
            // WordPress calls maybe_unserialize() internally, so the return
            // value is already a PHP array when FFM stored it as a serialized string.
            // We apply maybe_unserialize() as a belt-and-suspenders guard.
            //
            // NEVER call unserialize() on donor-supplied give_donationmeta values —
            // those are always read as plain strings.
            // ------------------------------------------------------------------

            if (!isset($fieldDefsCache[$giveFormId])) {
                $fieldDefsCache[$giveFormId] = self::getAllFieldDefs($giveFormId);
            }

            $fieldDefs = $fieldDefsCache[$giveFormId];

            if (empty($fieldDefs)) {
                $skipped++;
                continue;
            }

            // ------------------------------------------------------------------
            // Look up the Paymattic form ID for wpf_meta.form_id.
            // ------------------------------------------------------------------

            $paymatticFormId = absint($formMap[$giveFormId] ?? 0);

            // ------------------------------------------------------------------
            // Process each field definition.
            //
            // Bulk-fetch all FFM meta for this donation in one query, then
            // group by meta_key in PHP. Avoids a separate DB round-trip per
            // field (donation-count × field-count query pattern).
            // ------------------------------------------------------------------

            $relevantMetaKeys = [];
            foreach ($fieldDefs as $fieldDef) {
                if (!is_array($fieldDef)) {
                    continue;
                }
                $ft  = sanitize_text_field($fieldDef['input_type'] ?? '');
                $mk  = sanitize_text_field($fieldDef['name']        ?? '');
                $fn  = sanitize_key($fieldDef['name']               ?? '');
                if (empty($fn) || empty($mk) || in_array($ft, self::$skippedTypes, true)) {
                    continue;
                }
                $relevantMetaKeys[] = $mk;
            }

            $donationMeta = [];
            if (!empty($relevantMetaKeys)) {
                $placeholders    = implode(', ', array_fill(0, count($relevantMetaKeys), '%s'));
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $donationMetaTable composed from $wpdb->prefix only.
                $bulkMetaRows    = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT meta_key, meta_value
                         FROM {$donationMetaTable}
                         WHERE donation_id = %d
                           AND meta_key IN ({$placeholders})",
                        array_merge([$giveDonationId], $relevantMetaKeys)
                    ),
                    ARRAY_A
                );
                foreach ($bulkMetaRows as $row) {
                    $donationMeta[$row['meta_key']][] = ['meta_value' => $row['meta_value']];
                }
            }

            $formDataFormatted = [];
            $wroteMeta         = false;

            foreach ($fieldDefs as $fieldDef) {
                // Each field definition is an array with at minimum 'name' and 'input_type'.
                if (!is_array($fieldDef)) {
                    continue;
                }

                $fieldType  = sanitize_text_field($fieldDef['input_type'] ?? '');
                $fieldLabel = sanitize_text_field($fieldDef['label'] ?? $fieldDef['name'] ?? '');

                // Two keys are derived from the GiveWP field name:
                //
                // $giveMetaKey — used to look up give_donationmeta rows. GiveWP stores
                //   these using the raw field['name'] value (text-sanitized only), so we
                //   must query with the same string GiveWP wrote.
                //
                // $fieldName — raw sanitize_key() of the GiveWP field name, used as the
                //   wpf_meta meta_key suffix (stored as ffm_{fieldName}).
                //   form_data_formatted uses the ffm_-prefixed form so the key matches
                //   the builder element id written by PaymatticFormWriter::buildFFMElements()
                //   and avoids collisions with core Paymattic element ids.
                $giveMetaKey = sanitize_text_field($fieldDef['name'] ?? '');
                $fieldName   = sanitize_key($fieldDef['name']         ?? '');

                // Skip structural/display-only field types — they have no donor value.
                if (empty($fieldName) || in_array($fieldType, self::$skippedTypes, true)) {
                    continue;
                }

                // Use the pre-fetched bulk result instead of a per-field query.
                $metaRows = $donationMeta[$giveMetaKey] ?? [];

                if (empty($metaRows)) {
                    continue;
                }

                // Collect all values for this field.
                $values = [];
                foreach ($metaRows as $metaRow) {
                    $rawValue = $metaRow['meta_value'];

                    if ($fieldType === 'file_upload') {
                        // File upload fields store attachment IDs.
                        // Verify the attachment exists before including it.
                        $attachmentId = absint($rawValue);
                        if ($attachmentId > 0 && get_post($attachmentId)) {
                            $values[] = (string) $attachmentId;
                        }
                        // If attachment is missing, skip this value silently
                        // (attachment may have been deleted since donation).
                        continue;
                    }

                    // For repeat fields, each row may itself be a serialized sub-array.
                    // We store the raw value and let maybe_serialize() handle wrapping.
                    if ($fieldType === 'repeat') {
                        // Attempt JSON decode first (newer FFM versions).
                        $decoded = json_decode($rawValue, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $values[] = $decoded;
                        } else {
                            $values[] = $rawValue;
                        }
                        continue;
                    }

                    // Standard field: sanitize as text.
                    $values[] = sanitize_textarea_field($rawValue);
                }

                if (empty($values)) {
                    continue;
                }

                if ($dryRun) {
                    $wroteMeta = true;
                    continue;
                }

                // ------------------------------------------------------------------
                // Write wpf_meta row: ffm_{field_name}.
                //
                // Multi-value fields (repeat, multi-select) are stored as a
                // serialized array so they can be retrieved in full.
                // Single-value fields are stored as a plain string.
                // ------------------------------------------------------------------

                $storedValue = (count($values) === 1 && $fieldType !== 'repeat')
                    ? (string) $values[0]
                    : maybe_serialize($values);

                try {
                    $inserted = $wpdb->insert(
                        $metaTable,
                        [
                            'meta_group' => 'wpf_submissions',
                            'option_id'  => $wpfSubmissionId,
                            'form_id'    => $paymatticFormId,
                            'meta_key'   => 'ffm_' . $fieldName,
                            'meta_value' => $storedValue,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
                    );

                    if ($inserted) {
                        $wroteMeta = true;
                    }
                } catch (\Exception $e) {
                    MigrationState::addError(
                        sprintf(
                            'FFMEnricher meta insert failed for wpf_submission.id=%d field=%s: %s',
                            $wpfSubmissionId,
                            $fieldName,
                            substr(sanitize_text_field($e->getMessage()), 0, 200)
                        )
                    );
                }

                // ------------------------------------------------------------------
                // Build the form_data_formatted entry for this field.
                //
                // Submission::getInputEntryData() reads $inputValues[$elementId] and:
                //   - Uses the value directly if it is a string.
                //   - If it is an array, implodes all string values with ', '.
                //
                // We store a plain string so the entry detail view shows exactly
                // the donor's answer without any label prefix bleeding in.
                // An associative array like ['label' => ..., 'value' => ...] would
                // be imploded to "$label, $value" — incorrect output.
                // ------------------------------------------------------------------

                $displayValues = [];
                foreach ($values as $v) {
                    if (is_array($v)) {
                        $displayValues[] = implode('; ', array_map('strval', array_values($v)));
                    } else {
                        $displayValues[] = (string) $v;
                    }
                }

                $formDataFormatted['ffm_' . $fieldName] = implode(', ', $displayValues);
            }

            // ------------------------------------------------------------------
            // Update wpf_submissions.form_data_formatted with the assembled map.
            //
            // We merge with any existing formatted data so that partial enrichment
            // passes (e.g. FFM running after billing address was already set) do not
            // overwrite prior data.
            //
            // We only update when at least one field was processed to avoid
            // touching the row unnecessarily.
            // ------------------------------------------------------------------

            if (!$dryRun && !empty($formDataFormatted)) {
                try {
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $submissionsTable composed from $wpdb->prefix only.
                    $existingFormatted = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT form_data_formatted FROM {$submissionsTable} WHERE id = %d LIMIT 1",
                            $wpfSubmissionId
                        )
                    );

                    $existing = [];
                    if (!empty($existingFormatted)) {
                        $unserialized = wppayform_safeUnserialize($existingFormatted);
                        if (is_array($unserialized)) {
                            $existing = $unserialized;
                        }
                    }

                    $merged = array_merge($existing, $formDataFormatted);

                    $wpdb->update(
                        $submissionsTable,
                        ['form_data_formatted' => maybe_serialize($merged)],
                        ['id' => $wpfSubmissionId],
                        ['%s'],
                        ['%d']
                    );
                } catch (\Exception $e) {
                    MigrationState::addError(
                        sprintf(
                            'FFMEnricher form_data_formatted update failed for wpf_submission.id=%d: %s',
                            $wpfSubmissionId,
                            substr(sanitize_text_field($e->getMessage()), 0, 200)
                        )
                    );
                }
            }

            if ($wroteMeta) {
                // Write sentinel so re-runs skip this submission.
                $wpdb->insert(
                    $metaTable,
                    [
                        'meta_group' => 'wpf_submissions',
                        'option_id'  => $wpfSubmissionId,
                        'form_id'    => $paymatticFormId,
                        'meta_key'   => 'give_ffm_enriched',
                        'meta_value' => '1',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['%s', '%d', '%d', '%s', '%s', '%s', '%s']
                );
                $enriched++;
            } else {
                $skipped++;
            }
        }

        return ['enriched' => $enriched, 'skipped' => $skipped];
    }
}
