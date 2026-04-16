<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if (!isset($submission->transactions[0])) {
    return '';
}

$id = $submission->transactions[0]->charge_id;
echo esc_attr($id);
