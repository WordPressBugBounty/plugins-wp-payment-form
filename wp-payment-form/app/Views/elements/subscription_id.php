<?php

if (!isset($submission->subscriptions[0])) {
    return '';
}
$wppayform_subs_id = $submission->subscriptions[0]->vendor_subscriptipn_id;
echo esc_html($wppayform_subs_id);
