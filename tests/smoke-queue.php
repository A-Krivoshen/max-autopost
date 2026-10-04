<?php
/**
 * Disposable-stand smoke for MAX Autopost 1.12.0.
 *
 *   KRV_MAX_SMOKE_ALLOW=1 wp eval-file tests/smoke-queue.php --skip-plugins
 *
 * Refuses krivoshein.site and any host that is not local/ddev.
 * Refuses a database that already has queued posts.
 * Restores plugin options on shutdown, including a fatal.
 * HTTP to *.max.ru is denied unless a scenario arms the mock.
 * Does not fork: the per-post lock is proved with INSERT IGNORE in this process.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with: KRV_MAX_SMOKE_ALLOW=1 wp eval-file tests/smoke-queue.php --skip-plugins\n");
    exit(1);
}

if (getenv('KRV_MAX_SMOKE_ALLOW') !== '1') {
    fwrite(STDERR, "Refusing: set KRV_MAX_SMOKE_ALLOW=1. This script writes options and posts on the current site.\n");
    exit(1);
}

$host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
$local_host = $host === 'localhost'
    || $host === '127.0.0.1'
    || ($host !== '' && (str_ends_with($host, '.ddev.site') || str_ends_with($host, '.ddev.local')));
if (!$local_host || str_contains($host, 'krivoshein.site')) {
    fwrite(STDERR, "Refusing: smoke runs only on a local disposable site.\n");
    exit(1);
}

if (class_exists('KRV_MAX_Autopost', false)) {
    fwrite(STDERR, "MAX Autopost is already loaded. Re-run with --skip-plugins so this file loads the repo copy.\n");
    exit(1);
}

global $wpdb;
$prequeued = (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_krv_max_status' AND meta_value = 'queued'");
if ($prequeued > 0) {
    fwrite(STDERR, "Refusing: this database already has {$prequeued} queued posts.\n");
    exit(1);
}

$GLOBALS['krv_smoke_posts'] = [];
$GLOBALS['krv_smoke_restore'] = [];
$GLOBALS['krv_http_mode'] = 'deny';
$GLOBALS['krv_http_messages'] = 0;
$GLOBALS['krv_fake_token'] = 'smokeTokValue99';
$GLOBALS['krv_fake_chat'] = 'smokeChat77xx';
$GLOBALS['krv_upgrade_ids'] = [];
$GLOBALS['krv_queue_ids'] = [];
$GLOBALS['krv_requeue_ids'] = [];
$GLOBALS['krv_queue_all_ids'] = [];

function krv_secret_leaked(string $text): bool {
    $needles = [$GLOBALS['krv_fake_token'], $GLOBALS['krv_fake_chat']];
    if (defined('KRV_MAX_TOKEN') && is_string(KRV_MAX_TOKEN) && strlen(KRV_MAX_TOKEN) >= 6) {
        $needles[] = KRV_MAX_TOKEN;
    }
    if (defined('KRV_MAX_CHAT_ID') && is_string(KRV_MAX_CHAT_ID) && strlen(KRV_MAX_CHAT_ID) >= 6) {
        $needles[] = KRV_MAX_CHAT_ID;
    }
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($text, $needle)) {
            return true;
        }
    }
    return false;
}

function krv_fail(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    $detail = (string) ($GLOBALS['krv_last_cli'] ?? '');
    if ($detail !== '' && !krv_secret_leaked($detail)) {
        fwrite(STDERR, $detail . "\n");
    }
    exit(1);
}

function krv_ok(string $message): void {
    echo "ok {$message}\n";
}

function krv_option_raw(string $key): ?string {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key));
    return $value === null ? null : (string) $value;
}

function krv_meta_raw(int $post_id, string $key): ?string {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
        $post_id,
        $key
    ));
    return $value === null ? null : (string) $value;
}

function krv_meta_snapshot(int $post_id): array {
    $keys = [
        '_krv_max_status',
        '_krv_max_error',
        '_krv_max_attempts',
        '_krv_max_next_try',
        '_krv_max_queued_at',
        '_krv_max_queue_stamp',
        '_krv_max_sent_hash',
        '_krv_max_sent_hash_prev',
        '_krv_max_target_results',
        '_krv_max_disable',
    ];
    $out = [];
    foreach ($keys as $key) {
        $out[$key] = krv_meta_raw($post_id, $key);
    }
    return $out;
}

function krv_call(string $method, array $args = []) {
    $ref = new ReflectionMethod(KRV_MAX_Autopost::class, $method);
    return $ref->invokeArgs(null, $args);
}

function krv_settings(array $over = []): void {
    $base = [
        'token' => $GLOBALS['krv_fake_token'],
        'chat_id' => $GLOBALS['krv_fake_chat'],
        'additional_chat_ids' => '',
        'include_image' => 0,
        'image_source_mode' => 'post_only',
        'add_button' => 0,
        'button_text' => 'Читать',
        'add_subscribe_button' => 0,
        'subscribe_button_text' => 'Подписаться',
        'subscribe_button_url' => '',
        'max_text_limit' => 3900,
        'message_format' => 'plain_text',
        'bold_title' => 0,
        'append_in_limit' => 1,
        'post_append_text' => '',
        'publish_custom_fields' => 0,
        'enabled_post_types' => ['post'],
        'custom_fields_map' => '',
        'notify' => 1,
        'debug' => 0,
        'enable_worker_after_test' => 0,
        'batch_per_run' => 1,
        'send_interval_sec' => 0,
    ];
    update_option('krv_max_autopost', array_merge($base, $over), false);
}

function krv_make(string $title): int {
    $id = wp_insert_post([
        'post_title' => $title,
        'post_content' => 'Smoke body for ' . $title . '. Короткий текст для мока MAX, без картинки и без кнопки.',
        'post_status' => 'draft',
        'post_type' => 'post',
    ], true);
    if (is_wp_error($id) || (int) $id <= 0) {
        krv_fail('cannot create post');
    }
    $id = (int) $id;
    $GLOBALS['krv_smoke_posts'][] = $id;
    return $id;
}

function krv_publish(int $post_id): void {
    remove_action('transition_post_status', ['KRV_MAX_Autopost', 'queue_on_publish'], 10);
    $result = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
    add_action('transition_post_status', ['KRV_MAX_Autopost', 'queue_on_publish'], 10, 3);
    if (is_wp_error($result)) {
        krv_fail('cannot publish ' . $post_id);
    }
}

function krv_logs_matching(int $post_id, string $step, string $msg_part): int {
    $logs = get_option('krv_max_autopost_logs', []);
    if (!is_array($logs)) {
        return 0;
    }
    $n = 0;
    foreach ($logs as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($post_id > 0 && (int) ($row['post_id'] ?? 0) !== $post_id) {
            continue;
        }
        if ($step !== '' && (string) ($row['step'] ?? '') !== $step) {
            continue;
        }
        if ($msg_part !== '' && !str_contains((string) ($row['msg'] ?? ''), $msg_part)) {
            continue;
        }
        $n++;
    }
    return $n;
}

function krv_http_reset(string $mode): void {
    $GLOBALS['krv_http_mode'] = $mode;
    $GLOBALS['krv_http_messages'] = 0;
}

function krv_cli(string $command): array {
    try {
        $result = WP_CLI::runcommand($command, [
            'return' => 'all',
            'launch' => false,
            'exit_error' => false,
        ]);
    } catch (Throwable $e) {
        krv_fail('cli failed: ' . $command);
    }
    if (is_object($result)) {
        $result = [
            'stdout' => (string) ($result->stdout ?? ''),
            'stderr' => (string) ($result->stderr ?? ''),
            'return_code' => (int) ($result->return_code ?? -1),
        ];
    }
    if (is_string($result)) {
        $result = ['stdout' => $result, 'stderr' => '', 'return_code' => 0];
    }
    if (!is_array($result)) {
        krv_fail('cli returned an unexpected result: ' . $command);
    }
    $stdout = (string) ($result['stdout'] ?? '');
    $stderr = (string) ($result['stderr'] ?? '');
    if (krv_secret_leaked($stdout) || krv_secret_leaked($stderr)) {
        krv_fail('cli output contains a raw secret: ' . $command);
    }
    $packed = [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'return_code' => (int) ($result['return_code'] ?? -1),
    ];
    $GLOBALS['krv_last_cli'] = $command . ' code=' . $packed['return_code'] . "\nSTDOUT:\n" . $stdout . "\nSTDERR:\n" . $stderr;
    return $packed;
}

function krv_lines(string $stdout): string {
    $lines = preg_split('/\r\n|\r|\n/', $stdout) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $kept[] = $line;
        }
    }
    return implode("\n", $kept);
}

$option_keys = [
    'krv_max_autopost',
    'krv_max_autopost_logs',
    'krv_max_autopost_ver',
    'krv_max_autopost_queue_cutoff',
    'krv_max_autopost_install_stamp',
    'krv_max_autopost_worker_enabled',
    'krv_max_autopost_upgrade_notice',
    'cron',
];
foreach ($option_keys as $key) {
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        $key
    ), ARRAY_A);
    if (!is_array($row)) {
        $GLOBALS['krv_smoke_restore'][$key] = ['exists' => false];
        continue;
    }
    $GLOBALS['krv_smoke_restore'][$key] = [
        'exists' => true,
        'value' => (string) $row['option_value'],
        'autoload' => (string) $row['autoload'],
    ];
}

$GLOBALS['krv_smoke_done'] = false;
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['krv_smoke_done'])) {
        return;
    }
    $GLOBALS['krv_smoke_done'] = true;
    global $wpdb;
    foreach ($GLOBALS['krv_smoke_posts'] as $post_id) {
        wp_delete_post((int) $post_id, true);
    }
    foreach ($GLOBALS['krv_smoke_restore'] as $key => $snap) {
        if (empty($snap['exists'])) {
            delete_option($key);
            continue;
        }
        $updated = $wpdb->update(
            $wpdb->options,
            ['option_value' => $snap['value'], 'autoload' => $snap['autoload']],
            ['option_name' => $key]
        );
        if ($updated === 0 || $updated === false) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $key));
            if (!$exists) {
                $wpdb->insert($wpdb->options, [
                    'option_name' => $key,
                    'option_value' => $snap['value'],
                    'autoload' => $snap['autoload'],
                ]);
            }
        }
        wp_cache_delete($key, 'options');
    }
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    delete_transient('krv_max_queue_counts');
    delete_transient('krv_max_queue_stuck');
    delete_transient('krv_max_autopost_lock');
    delete_transient('krv_max_notice');
    foreach ($GLOBALS['krv_smoke_posts'] as $post_id) {
        delete_transient('krv_max_send_lock_' . (int) $post_id);
    }
    delete_option('_transient_krv_max_smoke_lock');
    delete_option('_transient_timeout_krv_max_smoke_lock');
});

update_option('krv_max_autopost_ver', '1.12.0', false);

$plugin = dirname(__DIR__) . '/max-autopost.php';
if (!is_readable($plugin)) {
    krv_fail('plugin file is missing next to tests/');
}
require_once $plugin;
if (!method_exists(KRV_MAX_Autopost::class, 'send_post_now')) {
    krv_fail('loaded class has no send_post_now');
}

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $url = (string) $url;
    if (str_contains($url, 'wp-cron.php')) {
        return [
            'headers' => [],
            'body' => '',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    $remote_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $is_max = $remote_host === 'max.ru' || ($remote_host !== '' && str_ends_with($remote_host, '.max.ru'));
    if (!$is_max) {
        return $pre;
    }
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    if ($method === 'POST' && str_contains($url, '/messages')) {
        $GLOBALS['krv_http_messages']++;
    }
    $mode = (string) $GLOBALS['krv_http_mode'];
    if ($mode === '200' && $method === 'POST' && str_contains($url, '/messages')) {
        return [
            'headers' => [],
            'body' => '{"message_id":"smoke-1"}',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($mode === '400' && $method === 'POST' && str_contains($url, '/messages')) {
        return [
            'headers' => [],
            'body' => '{"error":"bad request"}',
            'response' => ['code' => 400, 'message' => 'Bad Request'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    if ($mode === '429' && $method === 'POST' && str_contains($url, '/messages')) {
        return [
            'headers' => ['retry-after' => '30'],
            'body' => '{"error":"rate"}',
            'response' => ['code' => 429, 'message' => 'Too Many Requests'],
            'cookies' => [],
            'filename' => null,
        ];
    }
    return new WP_Error('krv_smoke_deny', 'blocked max.ru');
}, 1, 3);

add_filter('krv_max_autopost_upgrade_queue_ids', static function () {
    return isset($GLOBALS['krv_upgrade_ids']) && is_array($GLOBALS['krv_upgrade_ids'])
        ? $GLOBALS['krv_upgrade_ids']
        : [];
});
add_filter('krv_max_autopost_process_queue_ids', static function () {
    return isset($GLOBALS['krv_queue_ids']) && is_array($GLOBALS['krv_queue_ids'])
        ? $GLOBALS['krv_queue_ids']
        : [];
});
add_filter('krv_max_autopost_requeue_candidate_ids', static function () {
    return isset($GLOBALS['krv_requeue_ids']) && is_array($GLOBALS['krv_requeue_ids'])
        ? $GLOBALS['krv_requeue_ids']
        : [];
});
add_filter('krv_max_autopost_queue_all_candidate_ids', static function () {
    return isset($GLOBALS['krv_queue_all_ids']) && is_array($GLOBALS['krv_queue_all_ids'])
        ? $GLOBALS['krv_queue_all_ids']
        : [];
});

add_filter('wp_die_handler', static function () {
    return static function ($message) {
        $text = is_string($message) ? $message : (is_wp_error($message) ? $message->get_error_message() : 'die');
        throw new RuntimeException('wp_die:' . $text);
    };
});

krv_settings();
update_option('krv_max_autopost_worker_enabled', 0, false);
update_option('krv_max_autopost_logs', [], false);
$stamp = 'current-stamp';
$cutoff = time() - (int) DAY_IN_SECONDS;
update_option('krv_max_autopost_install_stamp', $stamp, false);
update_option('krv_max_autopost_queue_cutoff', $cutoff, false);

$admin_id = 0;
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => ['ID']]);
if (is_array($admins) && isset($admins[0]->ID)) {
    $admin_id = (int) $admins[0]->ID;
}
if ($admin_id <= 0) {
    $user = get_user_by('id', 1);
    if ($user && user_can($user, 'manage_options')) {
        $admin_id = 1;
    }
}
if ($admin_id <= 0) {
    krv_fail('no administrator on this stand');
}
wp_set_current_user($admin_id);

/* (a) upgrade keeps worker, stamp, cutoff; restamps fresh queue; errors only the stale row. */
$now = time();
$fresh_a = krv_make('KRV-SMOKE-FRESH-A');
$fresh_b = krv_make('KRV-SMOKE-FRESH-B');
$stale = krv_make('KRV-SMOKE-STALE');
$missing_at = krv_make('KRV-SMOKE-MISSING-AT');
$sent = krv_make('KRV-SMOKE-SENT');
$decoy = krv_make('KRV-SMOKE-DECOY');
foreach ([$fresh_a, $fresh_b, $stale, $missing_at, $sent, $decoy] as $id) {
    krv_publish($id);
}

update_post_meta($fresh_a, '_krv_max_status', 'queued');
update_post_meta($fresh_a, '_krv_max_queue_stamp', 'old-stamp');
update_post_meta($fresh_a, '_krv_max_queued_at', $now - 2 * (int) DAY_IN_SECONDS);
update_post_meta($fresh_a, '_krv_max_next_try', $now);
update_post_meta($fresh_a, '_krv_max_attempts', 3);
update_post_meta($fresh_a, '_krv_max_error', 'keep-error');
update_post_meta($fresh_a, '_krv_max_sent_hash', 'keep-hash-fresh');

update_post_meta($fresh_b, '_krv_max_status', 'queued');
update_post_meta($fresh_b, '_krv_max_queue_stamp', 'old-stamp');
update_post_meta($fresh_b, '_krv_max_queued_at', $now - 2 * (int) DAY_IN_SECONDS);
update_post_meta($fresh_b, '_krv_max_next_try', $now - 10);

update_post_meta($stale, '_krv_max_status', 'queued');
update_post_meta($stale, '_krv_max_queue_stamp', 'old-stamp');
update_post_meta($stale, '_krv_max_queued_at', $now - 15 * (int) DAY_IN_SECONDS);
update_post_meta($stale, '_krv_max_next_try', $now);
update_post_meta($stale, '_krv_max_sent_hash', 'keep-hash-stale');
update_post_meta($stale, '_krv_max_target_results', ['status' => 'untouched']);
$stale_queued_at = krv_meta_raw($stale, '_krv_max_queued_at');
$stale_hash = krv_meta_raw($stale, '_krv_max_sent_hash');
$stale_results = krv_meta_raw($stale, '_krv_max_target_results');

update_post_meta($missing_at, '_krv_max_status', 'queued');
update_post_meta($missing_at, '_krv_max_queue_stamp', 'old-stamp');
update_post_meta($missing_at, '_krv_max_attempts', 4);
delete_post_meta($missing_at, '_krv_max_queued_at');

update_post_meta($sent, '_krv_max_status', 'sent');
update_post_meta($sent, '_krv_max_sent_hash', 'keep-hash-sent');
update_post_meta($sent, '_krv_max_target_results', ['message_id' => 'kept']);
$sent_before = [
    'status' => krv_meta_raw($sent, '_krv_max_status'),
    'hash' => krv_meta_raw($sent, '_krv_max_sent_hash'),
    'results' => krv_meta_raw($sent, '_krv_max_target_results'),
];

update_post_meta($decoy, '_krv_max_status', 'queued');
update_post_meta($decoy, '_krv_max_queue_stamp', 'old-stamp');
update_post_meta($decoy, '_krv_max_queued_at', $now - 2 * (int) DAY_IN_SECONDS);

update_option('krv_max_autopost_worker_enabled', 1, false);
update_option('krv_max_autopost_ver', '1.11.9', false);
$worker_raw = krv_option_raw('krv_max_autopost_worker_enabled');
$cutoff_raw = krv_option_raw('krv_max_autopost_queue_cutoff');
$stamp_raw = krv_option_raw('krv_max_autopost_install_stamp');
$GLOBALS['krv_upgrade_ids'] = [$fresh_a, $fresh_b, $stale, $missing_at];
krv_call('maybe_handle_upgrade');
$GLOBALS['krv_upgrade_ids'] = [];

if (krv_option_raw('krv_max_autopost_worker_enabled') !== $worker_raw) {
    krv_fail('upgrade rewrote worker_enabled');
}
if ((int) get_option('krv_max_autopost_worker_enabled') !== 1) {
    krv_fail('worker did not stay on');
}
if (krv_option_raw('krv_max_autopost_queue_cutoff') !== $cutoff_raw || krv_option_raw('krv_max_autopost_install_stamp') !== $stamp_raw) {
    krv_fail('upgrade rewrote cutoff or stamp');
}
if ((string) get_option('krv_max_autopost_ver') !== '1.12.0') {
    krv_fail('version was not set to 1.12.0');
}
foreach ([$fresh_a, $fresh_b] as $id) {
    if ((string) get_post_meta($id, '_krv_max_status', true) !== 'queued') {
        krv_fail('fresh post left the queue');
    }
    if ((string) get_post_meta($id, '_krv_max_queue_stamp', true) !== $stamp) {
        krv_fail('fresh post was not restamped');
    }
    if ((int) get_post_meta($id, '_krv_max_queued_at', true) < $cutoff) {
        krv_fail('fresh queued_at stayed below cutoff');
    }
}
if ((int) get_post_meta($fresh_a, '_krv_max_attempts', true) !== 3 || (string) get_post_meta($fresh_a, '_krv_max_error', true) !== 'keep-error') {
    krv_fail('restamp rewrote attempts or error');
}
if ((string) get_post_meta($fresh_a, '_krv_max_sent_hash', true) !== 'keep-hash-fresh') {
    krv_fail('restamp rewrote sent hash');
}
if ((string) get_post_meta($stale, '_krv_max_status', true) !== 'error') {
    krv_fail('stale post was not quarantined');
}
if (krv_meta_raw($stale, '_krv_max_sent_hash') !== $stale_hash || krv_meta_raw($stale, '_krv_max_target_results') !== $stale_results || krv_meta_raw($stale, '_krv_max_queued_at') !== $stale_queued_at) {
    krv_fail('quarantine touched hash, results, or queued_at');
}
if (krv_meta_raw($stale, '_krv_max_queue_stamp') !== null) {
    krv_fail('quarantine left the queue stamp in place');
}
if ((string) get_post_meta($missing_at, '_krv_max_status', true) !== 'queued') {
    krv_fail('missing queued_at was treated as stale');
}
if ((int) get_post_meta($missing_at, '_krv_max_queued_at', true) !== $cutoff || (string) get_post_meta($missing_at, '_krv_max_queue_stamp', true) !== $stamp) {
    krv_fail('missing queued_at was not filled up to cutoff');
}
if ((int) get_post_meta($missing_at, '_krv_max_attempts', true) !== 4) {
    krv_fail('missing queued_at restamp rewrote attempts');
}
if ((string) get_post_meta($decoy, '_krv_max_queue_stamp', true) !== 'old-stamp' || (string) get_post_meta($decoy, '_krv_max_status', true) !== 'queued') {
    krv_fail('upgrade touched a post outside the id filter');
}
foreach (['status', 'hash', 'results'] as $part) {
    $key = $part === 'status' ? '_krv_max_status' : ($part === 'hash' ? '_krv_max_sent_hash' : '_krv_max_target_results');
    if (krv_meta_raw($sent, $key) !== $sent_before[$part]) {
        krv_fail('sent meta changed during upgrade');
    }
}
krv_ok('upgrade worker on');

/* (b) worker off stays off; fresh queue is not failed. */
$fresh_off = krv_make('KRV-SMOKE-FRESH-OFF');
krv_publish($fresh_off);
update_post_meta($fresh_off, '_krv_max_status', 'queued');
update_post_meta($fresh_off, '_krv_max_queue_stamp', 'old-stamp');
update_post_meta($fresh_off, '_krv_max_queued_at', $now - 2 * (int) DAY_IN_SECONDS);
update_option('krv_max_autopost_worker_enabled', 0, false);
update_option('krv_max_autopost_ver', '1.11.9', false);
$GLOBALS['krv_upgrade_ids'] = [$fresh_off];
krv_call('maybe_handle_upgrade');
$GLOBALS['krv_upgrade_ids'] = [];
if ((int) get_option('krv_max_autopost_worker_enabled') !== 0) {
    krv_fail('upgrade turned the worker on');
}
if ((string) get_post_meta($fresh_off, '_krv_max_status', true) !== 'queued') {
    krv_fail('fresh post became an error while the worker was off');
}
foreach (['status', 'hash', 'results'] as $part) {
    $key = $part === 'status' ? '_krv_max_status' : ($part === 'hash' ? '_krv_max_sent_hash' : '_krv_max_target_results');
    if (krv_meta_raw($sent, $key) !== $sent_before[$part]) {
        krv_fail('sent meta changed during the worker-off upgrade');
    }
}
update_option('krv_max_autopost_worker_enabled', 0, false);
krv_ok('upgrade worker off');

/* Invalid stale-day filter is clamped to 1, so a 2-day queued post is quarantined. */
$clamp_id = krv_make('KRV-SMOKE-CLAMP');
krv_publish($clamp_id);
update_post_meta($clamp_id, '_krv_max_status', 'queued');
update_post_meta($clamp_id, '_krv_max_queue_stamp', 'old-stamp');
update_post_meta($clamp_id, '_krv_max_queued_at', time() - 2 * (int) DAY_IN_SECONDS);
update_post_meta($clamp_id, '_krv_max_sent_hash', 'clamp-hash');
$clamp = static function () {
    return 0;
};
add_filter('krv_max_autopost_stale_queue_days', $clamp);
update_option('krv_max_autopost_ver', '1.11.9', false);
$GLOBALS['krv_upgrade_ids'] = [$clamp_id];
krv_call('maybe_handle_upgrade');
remove_filter('krv_max_autopost_stale_queue_days', $clamp);
$GLOBALS['krv_upgrade_ids'] = [];
if ((string) get_post_meta($clamp_id, '_krv_max_status', true) !== 'error' || (string) get_post_meta($clamp_id, '_krv_max_sent_hash', true) !== 'clamp-hash') {
    krv_fail('stale-day filter was not clamped to 1');
}
krv_ok('stale days clamp');

/* (c) send lifecycle: dry-run, send, skip, edit still skips, force sends. */
$send_id = krv_make('KRV-SMOKE-SEND');
krv_publish($send_id);
update_post_meta($send_id, '_krv_max_status', 'queued');
update_post_meta($send_id, '_krv_max_queue_stamp', $stamp);
update_post_meta($send_id, '_krv_max_queued_at', time());
update_post_meta($send_id, '_krv_max_next_try', time());
$before_meta = krv_meta_snapshot($send_id);
krv_http_reset('200');
$dry = krv_cli('max-autopost send ' . $send_id . ' --dry-run');
if ($dry['return_code'] !== 0 || krv_lines($dry['stdout']) !== $send_id . ' dry_run dry_run') {
    krv_fail('dry-run cli contract');
}
if ($GLOBALS['krv_http_messages'] !== 0 || krv_meta_snapshot($send_id) !== $before_meta) {
    krv_fail('dry-run sent or wrote meta');
}
$first = KRV_MAX_Autopost::send_post_now($send_id);
if (($first['status'] ?? '') !== 'sent' || ($first['reason'] ?? '') !== 'sent') {
    krv_fail('first send did not return sent');
}
if ($GLOBALS['krv_http_messages'] !== 1) {
    krv_fail('first send did not make exactly one POST /messages');
}
if ((string) get_post_meta($send_id, '_krv_max_status', true) !== 'sent') {
    krv_fail('first send did not mark the post sent');
}
foreach (['_krv_max_error', '_krv_max_attempts', '_krv_max_next_try', '_krv_max_queued_at', '_krv_max_queue_stamp'] as $gone) {
    if (krv_meta_raw($send_id, $gone) !== null) {
        krv_fail('sent post stayed in the queue');
    }
}
$hash_one = (string) get_post_meta($send_id, '_krv_max_sent_hash', true);
if ($hash_one === '') {
    krv_fail('sent hash was not stored');
}
$second = krv_cli('max-autopost send ' . $send_id);
if ($second['return_code'] !== 0 || krv_lines($second['stdout']) !== $send_id . ' skipped already_sent') {
    krv_fail('second send was not already_sent');
}
if ($GLOBALS['krv_http_messages'] !== 1 || (string) get_post_meta($send_id, '_krv_max_sent_hash', true) !== $hash_one) {
    krv_fail('second send posted or rewrote the hash');
}
wp_update_post(['ID' => $send_id, 'post_content' => 'edited smoke body ' . time()]);
$edited = krv_cli('max-autopost send ' . $send_id);
if ($edited['return_code'] !== 0 || krv_lines($edited['stdout']) !== $send_id . ' skipped already_sent' || $GLOBALS['krv_http_messages'] !== 1) {
    krv_fail('edited post was resent without --force');
}
$forced_dry = KRV_MAX_Autopost::send_post_now($send_id, ['force' => true, 'dry_run' => true]);
if (($forced_dry['status'] ?? '') !== 'dry_run' || $GLOBALS['krv_http_messages'] !== 1 || (string) get_post_meta($send_id, '_krv_max_sent_hash', true) !== $hash_one) {
    krv_fail('force dry-run was not a no-op');
}
$forced = krv_cli('max-autopost send ' . $send_id . ' --force');
if ($forced['return_code'] !== 0 || krv_lines($forced['stdout']) !== $send_id . ' sent sent') {
    krv_fail('force send cli contract');
}
if ($GLOBALS['krv_http_messages'] !== 2) {
    krv_fail('force send did not make one more POST /messages');
}
$hash_two = (string) get_post_meta($send_id, '_krv_max_sent_hash', true);
if ($hash_two === '' || $hash_two === $hash_one) {
    krv_fail('force send did not store a new hash');
}
krv_ok('send lifecycle');

/* (d) lock: second acquire loses; a pre-set transient is not deleted by the loser. */
$lock_a = krv_call('acquire_named_lock', ['krv_max_smoke_lock', 30]);
$lock_b = krv_call('acquire_named_lock', ['krv_max_smoke_lock', 30]);
krv_call('release_named_lock', ['krv_max_smoke_lock']);
if ($lock_a !== true || $lock_b !== false) {
    krv_fail('INSERT IGNORE lock did not reject the second acquire');
}
$locked_id = krv_make('KRV-SMOKE-LOCKED');
krv_publish($locked_id);
update_post_meta($locked_id, '_krv_max_status', 'queued');
update_post_meta($locked_id, '_krv_max_queue_stamp', $stamp);
update_post_meta($locked_id, '_krv_max_queued_at', time());
$locked_meta = krv_meta_snapshot($locked_id);
set_transient('krv_max_send_lock_' . $locked_id, 1, 120);
krv_http_reset('200');
$locked = KRV_MAX_Autopost::send_post_now($locked_id);
if (($locked['status'] ?? '') !== 'skipped' || ($locked['reason'] ?? '') !== 'locked') {
    krv_fail('held lock did not skip the send');
}
if ($GLOBALS['krv_http_messages'] !== 0 || krv_meta_snapshot($locked_id) !== $locked_meta) {
    krv_fail('locked send posted or wrote meta');
}
if (get_transient('krv_max_send_lock_' . $locked_id) === false) {
    krv_fail('loser released a lock it did not acquire');
}
$locked_cli = krv_cli('max-autopost send ' . $locked_id);
if ($locked_cli['return_code'] !== 0 || krv_lines($locked_cli['stdout']) !== $locked_id . ' skipped locked') {
    krv_fail('locked send cli contract');
}
if (get_transient('krv_max_send_lock_' . $locked_id) === false) {
    krv_fail('cli send released a lock it did not acquire');
}
delete_transient('krv_max_send_lock_' . $locked_id);
krv_ok('send lock');

/* (e) single id ignores the queue head and the id filter; process_queue(true) still drains the head. */
$head = krv_make('KRV-SMOKE-HEAD');
$specific = krv_make('KRV-SMOKE-SPECIFIC');
$idle = krv_make('KRV-SMOKE-IDLE');
foreach ([$head, $specific, $idle] as $id) {
    krv_publish($id);
    update_post_meta($id, '_krv_max_status', 'queued');
    update_post_meta($id, '_krv_max_queue_stamp', $stamp);
    update_post_meta($id, '_krv_max_queued_at', time());
}
update_post_meta($head, '_krv_max_next_try', time() - 100);
update_post_meta($specific, '_krv_max_next_try', time() - 10);
update_post_meta($idle, '_krv_max_next_try', time() - 50);
krv_settings(['batch_per_run' => 1, 'send_interval_sec' => 0]);
krv_http_reset('200');
$GLOBALS['krv_queue_ids'] = [];
KRV_MAX_Autopost::process_queue(true, 0, $specific);
$GLOBALS['krv_queue_ids'] = [$head, $specific, $idle];
if ((string) get_post_meta($specific, '_krv_max_status', true) !== 'sent' || (string) get_post_meta($head, '_krv_max_status', true) !== 'queued') {
    krv_fail('process_queue(true, 0, id) did not send that id');
}
if ($GLOBALS['krv_http_messages'] !== 1) {
    krv_fail('specific send was not one POST /messages');
}
update_option('krv_max_autopost_worker_enabled', 0, false);
$held = $GLOBALS['krv_http_messages'];
KRV_MAX_Autopost::process_queue();
if ($GLOBALS['krv_http_messages'] !== $held || (string) get_post_meta($head, '_krv_max_status', true) !== 'queued') {
    krv_fail('disabled worker still drained the queue');
}
KRV_MAX_Autopost::process_queue(true);
if ((string) get_post_meta($head, '_krv_max_status', true) !== 'sent' || (string) get_post_meta($idle, '_krv_max_status', true) !== 'queued') {
    krv_fail('process_queue(true) did not send only the queue head');
}
if ($GLOBALS['krv_http_messages'] !== 2) {
    krv_fail('head send was not one additional POST /messages');
}
$run = krv_cli('max-autopost queue run --limit=1');
if ($run['return_code'] !== 0 || (string) get_post_meta($idle, '_krv_max_status', true) !== 'sent' || $GLOBALS['krv_http_messages'] !== 3) {
    krv_fail('queue run --limit=1 did not send the remaining post');
}
$GLOBALS['krv_queue_ids'] = [];
krv_ok('process_queue paths');

/* (f) one tick sends 5 of 7 with pauses; the next tick sends the rest. */
$batch_ids = [];
$batch_now = time();
for ($i = 0; $i < 7; $i++) {
    $id = krv_make('KRV-SMOKE-BATCH-' . $i);
    krv_publish($id);
    update_post_meta($id, '_krv_max_status', 'queued');
    update_post_meta($id, '_krv_max_queue_stamp', $stamp);
    update_post_meta($id, '_krv_max_queued_at', $batch_now);
    update_post_meta($id, '_krv_max_next_try', $batch_now - (100 - $i));
    $batch_ids[] = $id;
}
krv_settings(['batch_per_run' => 5, 'send_interval_sec' => 2]);
$GLOBALS['krv_queue_ids'] = $batch_ids;
foreach ($GLOBALS['krv_smoke_posts'] as $park_id) {
    if (in_array((int) $park_id, $batch_ids, true)) {
        continue;
    }
    if ((string) get_post_meta((int) $park_id, '_krv_max_status', true) === 'queued') {
        update_post_meta((int) $park_id, '_krv_max_next_try', time() + 7 * (int) DAY_IN_SECONDS);
    }
}
krv_http_reset('200');
KRV_MAX_Autopost::set_worker_enabled(true);
$t0 = microtime(true);
try {
    KRV_MAX_Autopost::process_queue();
} finally {
    KRV_MAX_Autopost::set_worker_enabled(false);
}
$elapsed = microtime(true) - $t0;
$sent_n = 0;
foreach ($batch_ids as $id) {
    if ((string) get_post_meta($id, '_krv_max_status', true) === 'sent') {
        $sent_n++;
    }
}
if ($sent_n !== 5 || $GLOBALS['krv_http_messages'] !== 5 || $elapsed < 7.5) {
    krv_fail('first tick did not send 5 posts with pauses');
}
for ($i = 0; $i < 5; $i++) {
    if ((string) get_post_meta($batch_ids[$i], '_krv_max_status', true) !== 'sent') {
        krv_fail('batch order did not follow next_try');
    }
}
krv_http_reset('200');
KRV_MAX_Autopost::set_worker_enabled(true);
$t1 = microtime(true);
try {
    KRV_MAX_Autopost::process_queue();
} finally {
    KRV_MAX_Autopost::set_worker_enabled(false);
}
$elapsed2 = microtime(true) - $t1;
if ($GLOBALS['krv_http_messages'] !== 2 || $elapsed2 < 1.9) {
    krv_fail('second tick did not send the remaining 2 posts with a pause');
}
foreach ($batch_ids as $id) {
    if ((string) get_post_meta($id, '_krv_max_status', true) !== 'sent') {
        krv_fail('a batch post was left queued');
    }
}
krv_ok('batch pauses');

/* (g) 429 stops the batch, keeps the post queued, does not increment attempts. */
$rate_a = krv_make('KRV-SMOKE-429-A');
$rate_b = krv_make('KRV-SMOKE-429-B');
foreach ([$rate_a, $rate_b] as $id) {
    krv_publish($id);
    update_post_meta($id, '_krv_max_status', 'queued');
    update_post_meta($id, '_krv_max_queue_stamp', $stamp);
    update_post_meta($id, '_krv_max_queued_at', time());
    update_post_meta($id, '_krv_max_attempts', 2);
}
update_post_meta($rate_a, '_krv_max_next_try', time() - 20);
update_post_meta($rate_b, '_krv_max_next_try', time() - 5);
$rate_b_next = krv_meta_raw($rate_b, '_krv_max_next_try');
$rate_b_attempts = krv_meta_raw($rate_b, '_krv_max_attempts');
krv_settings(['batch_per_run' => 5, 'send_interval_sec' => 0]);
$GLOBALS['krv_queue_ids'] = [$rate_a, $rate_b];
krv_http_reset('429');
$before_429 = time();
KRV_MAX_Autopost::process_queue(true);
$next_try = (int) get_post_meta($rate_a, '_krv_max_next_try', true);
if ($GLOBALS['krv_http_messages'] !== 1) {
    krv_fail('429 did not stop after one POST /messages');
}
if ((string) get_post_meta($rate_a, '_krv_max_status', true) !== 'queued' || (int) get_post_meta($rate_a, '_krv_max_attempts', true) !== 2) {
    krv_fail('429 changed status or attempts');
}
if (abs($next_try - ($before_429 + 30)) > 5) {
    krv_fail('429 did not honor Retry-After');
}
if (krv_meta_raw($rate_b, '_krv_max_next_try') !== $rate_b_next || krv_meta_raw($rate_b, '_krv_max_attempts') !== $rate_b_attempts) {
    krv_fail('429 continued the batch');
}
krv_ok('http 429');

/* (h) a normal 400 still counts as an attempt and does not fall through to a second POST. */
$bad = krv_make('KRV-SMOKE-400');
krv_publish($bad);
update_post_meta($bad, '_krv_max_status', 'queued');
update_post_meta($bad, '_krv_max_queue_stamp', $stamp);
update_post_meta($bad, '_krv_max_queued_at', time());
update_post_meta($bad, '_krv_max_next_try', time() - 1);
update_post_meta($bad, '_krv_max_attempts', 0);
krv_settings(['batch_per_run' => 1, 'send_interval_sec' => 0]);
$GLOBALS['krv_queue_ids'] = [$bad];
krv_http_reset('400');
$before_400 = time();
KRV_MAX_Autopost::process_queue(true);
$bad_next = (int) get_post_meta($bad, '_krv_max_next_try', true);
if ($GLOBALS['krv_http_messages'] !== 1 || (int) get_post_meta($bad, '_krv_max_attempts', true) !== 1 || (string) get_post_meta($bad, '_krv_max_status', true) !== 'queued') {
    krv_fail('400 did not retry once');
}
if (abs($bad_next - ($before_400 + 60)) > 5) {
    krv_fail('400 did not use the first backoff');
}
krv_ok('http 400');

/* (i) requeue preview and the execute gate. */
$rq_sent = krv_make('KRV-SMOKE-RQ-SENT');
$rq_partial = krv_make('KRV-SMOKE-RQ-PARTIAL');
$rq_error = krv_make('KRV-SMOKE-RQ-ERROR');
$rq_empty = krv_make('KRV-SMOKE-RQ-EMPTY');
$rq_disabled = krv_make('KRV-SMOKE-RQ-DISABLED');
$rq_queued = krv_make('KRV-SMOKE-RQ-QUEUED');
foreach ([$rq_sent, $rq_partial, $rq_error, $rq_empty, $rq_disabled, $rq_queued] as $id) {
    krv_publish($id);
}
update_post_meta($rq_sent, '_krv_max_status', 'sent');
update_post_meta($rq_sent, '_krv_max_sent_hash', 'sent-hash-aaa');
update_post_meta($rq_sent, '_krv_max_target_results', ['message_id' => 's']);
update_post_meta($rq_partial, '_krv_max_status', 'partial_success');
update_post_meta($rq_partial, '_krv_max_sent_hash', 'partial-hash-bbb');
update_post_meta($rq_error, '_krv_max_status', 'error');
update_post_meta($rq_error, '_krv_max_error', 'boom');
update_post_meta($rq_disabled, '_krv_max_status', 'error');
update_post_meta($rq_disabled, '_krv_max_disable', 1);
update_post_meta($rq_queued, '_krv_max_status', 'queued');
update_post_meta($rq_queued, '_krv_max_queue_stamp', $stamp);
$GLOBALS['krv_queue_all_ids'] = [$rq_sent, $rq_partial, $rq_error, $rq_empty, $rq_disabled, $rq_queued];
$queue_all = krv_call('queue_all_published_ids');
$GLOBALS['krv_queue_all_ids'] = [];
sort($queue_all);
$expect_all = [$rq_error, $rq_empty, $rq_disabled];
sort($expect_all);
if ($queue_all !== $expect_all) {
    krv_fail('queue-all did not skip sent, partial_success, and queued');
}
krv_ok('queue all ids');

$GLOBALS['krv_requeue_ids'] = [$rq_sent, $rq_partial, $rq_error, $rq_empty, $rq_disabled];
$snap = [];
foreach ($GLOBALS['krv_requeue_ids'] as $id) {
    $snap[$id] = krv_meta_snapshot($id);
}
$preview = krv_call('requeue_published_preview');
$same = true;
foreach ($GLOBALS['krv_requeue_ids'] as $id) {
    if (krv_meta_snapshot($id) !== $snap[$id]) {
        $same = false;
    }
}
if (!$same) {
    krv_fail('requeue preview wrote meta');
}
$counts = $preview['counts'] ?? [];
if ((int) ($counts['sent'] ?? -1) !== 1 || (int) ($counts['partial_success'] ?? -1) !== 1 || (int) ($counts['error'] ?? -1) !== 1 || (int) ($counts['none'] ?? -1) !== 1 || (int) ($counts['disabled'] ?? -1) !== 1) {
    krv_fail('requeue preview counts');
}
if ((int) ($preview['will_requeue'] ?? -1) !== 2 || (int) ($preview['messages'] ?? -1) !== 2) {
    krv_fail('requeue preview estimate');
}

$invoke_requeue = static function (array $post, array $request): string {
    $_POST = $post;
    $_REQUEST = array_merge($post, $request);
    $redirect = static function () {
        throw new RuntimeException('redirect');
    };
    add_filter('wp_redirect', $redirect, 1);
    try {
        KRV_MAX_Autopost::handle_requeue_published_current_settings();
        return 'returned';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    } finally {
        remove_filter('wp_redirect', $redirect, 1);
    }
};
$preview_nonce = wp_create_nonce('krv_max_requeue_published_current_settings');
$execute_nonce = wp_create_nonce('krv_max_requeue_published_execute');
$unchanged = static function () use ($snap): bool {
    foreach ($snap as $id => $meta) {
        if (krv_meta_snapshot((int) $id) !== $meta) {
            return false;
        }
    }
    return true;
};
if (!str_starts_with($invoke_requeue([], []), 'wp_die:')) {
    krv_fail('requeue without a nonce did not die');
}
if (!$unchanged()) {
    krv_fail('requeue without a nonce wrote meta');
}
if ($invoke_requeue([], ['_wpnonce' => $preview_nonce]) !== 'redirect' || !$unchanged()) {
    krv_fail('preview nonce without confirmation wrote meta');
}
if ($invoke_requeue(['krv_max_requeue_confirm' => '1'], ['_wpnonce' => $preview_nonce]) !== 'redirect' || !$unchanged()) {
    krv_fail('confirmation with only the preview nonce wrote meta');
}
if ($invoke_requeue([], ['_wpnonce' => $execute_nonce]) !== 'redirect' || !$unchanged()) {
    krv_fail('execute nonce without the checkbox wrote meta');
}
update_option('krv_max_autopost_logs', [], false);
if ($invoke_requeue(['krv_max_requeue_confirm' => '1'], ['_wpnonce' => $execute_nonce]) !== 'redirect') {
    krv_fail('confirmed requeue did not finish');
}
if ((string) get_post_meta($rq_sent, '_krv_max_status', true) !== 'sent' || (string) get_post_meta($rq_sent, '_krv_max_sent_hash', true) !== 'sent-hash-aaa') {
    krv_fail('requeue without include-sent touched a sent post');
}
if ((string) get_post_meta($rq_partial, '_krv_max_status', true) !== 'partial_success' || (string) get_post_meta($rq_partial, '_krv_max_sent_hash', true) !== 'partial-hash-bbb') {
    krv_fail('requeue without include-sent touched a partial post');
}
if ((string) get_post_meta($rq_error, '_krv_max_status', true) !== 'queued' || (string) get_post_meta($rq_empty, '_krv_max_status', true) !== 'queued') {
    krv_fail('requeue did not queue error and empty posts');
}
if ((string) get_post_meta($rq_disabled, '_krv_max_status', true) !== 'error' || (int) get_post_meta($rq_disabled, '_krv_max_disable', true) !== 1) {
    krv_fail('requeue touched a disabled post');
}
if (krv_logs_matching($rq_sent, 'requeue_reset', '') !== 0) {
    krv_fail('requeue_reset was logged for a sent post that was not included');
}
update_option('krv_max_autopost_logs', [], false);
if ($invoke_requeue(['krv_max_requeue_confirm' => '1', 'krv_max_requeue_include_sent' => '1'], ['_wpnonce' => $execute_nonce]) !== 'redirect') {
    krv_fail('include-sent requeue did not finish');
}
if ((string) get_post_meta($rq_sent, '_krv_max_sent_hash_prev', true) !== 'sent-hash-aaa' || krv_meta_raw($rq_sent, '_krv_max_sent_hash') !== null) {
    krv_fail('old sent hash was not moved to _krv_max_sent_hash_prev');
}
if ((string) get_post_meta($rq_sent, '_krv_max_status', true) !== 'queued' || krv_logs_matching($rq_sent, 'requeue_reset', '') !== 1) {
    krv_fail('include-sent requeue did not queue the post or log requeue_reset');
}
if ((string) get_post_meta($rq_partial, '_krv_max_sent_hash_prev', true) !== 'partial-hash-bbb') {
    krv_fail('partial hash was not preserved');
}
$GLOBALS['krv_requeue_ids'] = [];
krv_ok('requeue gate');

/* (k) scheduled publish queues once. */
update_option('krv_max_autopost_logs', [], false);
$future = krv_make('KRV-SMOKE-FUTURE');
$when = gmdate('Y-m-d H:i:s', time() + (int) DAY_IN_SECONDS);
wp_update_post([
    'ID' => $future,
    'post_status' => 'future',
    'post_date_gmt' => $when,
    'post_date' => get_date_from_gmt($when),
    'edit_date' => true,
]);
update_option('krv_max_autopost_logs', [], false);
wp_update_post(['ID' => $future, 'post_status' => 'publish']);
$future_post = get_post($future);
KRV_MAX_Autopost::queue_on_future_publish($future_post);
krv_call('queue_post', [$future, 'Auto queue on publish']);
if (krv_logs_matching($future, 'queue', 'Auto queue on publish') !== 1) {
    krv_fail('scheduled publish did not produce exactly one Auto queue on publish line');
}
krv_ok('single publish queue');

/* (l) stuck notices are not dismissible. */
$off = krv_call('render_stuck_notices', [false, 4, time() - (4 * 3600) - 90]);
$off_html = implode("\n", $off);
if (!str_contains($off_html, 'Автоворкер выключен') || !str_contains($off_html, '4') || !str_contains($off_html, 'Включить автоворкер') || !str_contains($off_html, 'ч') || !str_contains($off_html, 'krv_max_worker_enable')) {
    krv_fail('worker-off stuck notice is missing the count or the enable button');
}
if (str_contains($off_html, 'is-dismissible')) {
    krv_fail('stuck notice is dismissible');
}
if (krv_call('render_stuck_notices', [true, 0, 0]) !== []) {
    krv_fail('worker-on empty queue rendered a notice');
}
$cron_html = implode("\n", krv_call('render_stuck_notices', [true, 2, time() - 1900]));
if (!str_contains($cron_html, 'WP-Cron') || str_contains($cron_html, 'Включить автоворкер')) {
    krv_fail('stale cron notice was not shown on its own');
}
krv_ok('stuck notices');

/* (m) status and queue list. */
$list_id = krv_make('KRV-SMOKE-LIST');
krv_publish($list_id);
update_post_meta($list_id, '_krv_max_status', 'queued');
update_post_meta($list_id, '_krv_max_queue_stamp', $stamp);
update_post_meta($list_id, '_krv_max_queued_at', time() - 120);
update_post_meta($list_id, '_krv_max_next_try', time() - 30);
update_post_meta($list_id, '_krv_max_attempts', 1);
update_post_meta($list_id, '_krv_max_error', 'list-err');
update_option('krv_max_autopost_worker_enabled', 0, false);
$status = krv_cli('max-autopost status');
if ($status['return_code'] !== 0) {
    krv_fail('status command failed');
}
$wanted = [
    'version', 'worker', 'batch_per_run', 'send_interval_sec', 'queued', 'error', 'partial_success', 'sent',
    'oldest_queued_at', 'oldest_queued_age_sec', 'oldest_queued_age', 'next_cron', 'next_cron_ts',
    'disable_wp_cron', 'cutoff', 'stamp', 'targets', 'token', 'chat_id',
];
foreach ($wanted as $key) {
    if (!preg_match('/^' . preg_quote($key, '/') . ': /m', $status['stdout'])) {
        krv_fail('status is missing ' . $key);
    }
}
if (!str_contains($status['stdout'], 'version: 1.12.0') || !preg_match('/^token: .*\*.*$/m', $status['stdout']) || !preg_match('/^chat_id: .*\*.*$/m', $status['stdout'])) {
    krv_fail('status version or secret mask');
}
if (!str_contains($status['stdout'], 'worker: off')) {
    krv_fail('status worker was not off');
}
$list = krv_cli('max-autopost queue list --status=queued --limit=50 --format=json');
if ($list['return_code'] !== 0) {
    krv_fail('queue list failed');
}
$decoded = json_decode($list['stdout'], true);
if (!is_array($decoded)) {
    krv_fail('queue list json is not an array');
}
$found = null;
foreach ($decoded as $row) {
    if (is_array($row) && (int) ($row['ID'] ?? 0) === $list_id) {
        $found = $row;
        break;
    }
}
if (!is_array($found)) {
    krv_fail('queue list did not include the fixture');
}
foreach (['ID', 'title', 'status', 'queued_at', 'next_try', 'attempts', 'error'] as $column) {
    if (!array_key_exists($column, $found)) {
        krv_fail('queue list is missing ' . $column);
    }
}
if ((string) $found['status'] !== 'queued' || (string) $found['title'] !== 'KRV-SMOKE-LIST' || (int) $found['attempts'] !== 1) {
    krv_fail('queue list row values');
}
foreach ($GLOBALS['krv_smoke_posts'] as $park_id) {
    if ((string) get_post_meta((int) $park_id, '_krv_max_status', true) === 'queued') {
        update_post_meta((int) $park_id, '_krv_max_next_try', time() + 7 * (int) DAY_IN_SECONDS);
    }
}
$enabled = krv_cli('max-autopost worker enable');
$mid = krv_cli('max-autopost status');
$disabled = krv_cli('max-autopost worker disable');
if ($enabled['return_code'] !== 0 || !str_contains($mid['stdout'], 'worker: on') || $disabled['return_code'] !== 0) {
    krv_fail('worker enable/disable');
}
if ((int) get_option('krv_max_autopost_worker_enabled') !== 0) {
    krv_fail('worker was left on');
}
krv_ok('cli status and queue list');

if ((string) get_option('krv_max_autopost_ver') !== '1.12.0') {
    krv_fail('version drifted from 1.12.0');
}

echo "SMOKE OK\n";
exit(0);
