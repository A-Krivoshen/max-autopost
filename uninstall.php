<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('krv_max_autopost');
delete_option('krv_max_autopost_logs');

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_krv_max_%'");
