<?php
if (!defined('ABSPATH')) exit;

final class KRV_MAX_GitHub_Updater {
    private static bool $initialized = false;

    public static function init(string $repo_url, string $plugin_file, string $slug): void {
        if (self::$initialized) return;
        self::$initialized = true;

        $puc_file = dirname(__DIR__) . '/lib/plugin-update-checker/plugin-update-checker.php';
        if (!is_readable($puc_file)) return;

        require_once $puc_file;

        $checker = null;
        $puc_v5 = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
        if (class_exists($puc_v5) && is_callable([$puc_v5, 'buildUpdateChecker'])) {
            $checker = $puc_v5::buildUpdateChecker($repo_url, $plugin_file, $slug);
        } elseif (class_exists('Puc_v4_Factory') && is_callable(['Puc_v4_Factory', 'buildUpdateChecker'])) {
            $checker = \Puc_v4_Factory::buildUpdateChecker($repo_url, $plugin_file, $slug);
        }

        if (!is_object($checker)) return;

        if (method_exists($checker, 'setBranch')) {
            $checker->setBranch('main');
        }

        if (!method_exists($checker, 'getVcsApi')) return;
        $api = $checker->getVcsApi();
        if (is_object($api) && method_exists($api, 'enableReleaseAssets')) {
            $api->enableReleaseAssets('/\\.zip($|[?&#])/i');
        }
    }
}
