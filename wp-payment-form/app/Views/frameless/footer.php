</div>

<?php foreach ($js_files as $wppayform_file): 
    // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript     
    echo "<script type='text/javascript' src='" . esc_url($wppayform_file) . "'></script>";
?>
<?php endforeach; ?>

<?php do_action('wppayform/frameless_footer', $action); ?>
</body>
</html>
