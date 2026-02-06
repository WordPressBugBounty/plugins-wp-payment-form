<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="profile" href="https://gmpg.org/xfn/11"/>
    <title><?php echo esc_html($title); ?></title>
    <?php if ($site_logo): ?>
        <link rel="icon" type="image/png" href="<?php echo esc_url($site_logo); ?>">
    <?php endif; ?>
    <?php foreach ($css_files as $wppayform_file): 
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
        echo "<link rel='stylesheet' href='" . esc_url($wppayform_file) . "' type='text/css' media='all'/>";    
    ?>
    <?php endforeach; ?>

    <?php foreach ($js_files as $wppayform_file): 
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
        echo "<script type='text/javascript' src='" . esc_url($wppayform_file) . "'></script>";
        ?>
    <?php endforeach; ?>

    <?php do_action('wppayform/frameless_header', $action); ?>
</head>
<body>

<div class="wppayform_header_logo">
    <?php if ($site_logo): ?>
        <a class="wppayform_site_logo" href="<?php echo esc_url(site_url()); ?>">
            <img alt="<?php echo esc_attr($company_name); ?>" src="<?php echo esc_url($site_logo); ?>"/>
        </a>
    <?php else: ?>
        <a class="wppayform_site_name" href="<?php echo esc_url(site_url()); ?>"><?php echo esc_html($company_name); ?></a>
    <?php endif; ?>
</div>

<div class="wppayform_frameless_body_start">

