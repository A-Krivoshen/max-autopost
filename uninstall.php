<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('krv_max_autopost');
delete_option('krv_max_autopost_logs');
delete_option('krv_max_autopost_ver');
delete_option('krv_max_autopost_queue_cutoff');
delete_option('krv_max_autopost_install_stamp');
delete_option('krv_max_autopost_worker_enabled');
delete_option('krv_max_autopost_upgrade_notice');

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_krv_max_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_krv_max_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_krv_max_%'");

// Remove cached combined CA bundle (uploads/krv-max-autopost/).
$upload = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : null;
if (is_array($upload) && empty($upload['error']) && !empty($upload['basedir'])) {
    $dir = trailingslashit((string)$upload['basedir']) . 'krv-max-autopost';
    if (is_dir($dir)) {
        foreach (glob($dir . '/ca-combined-*.pem') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
