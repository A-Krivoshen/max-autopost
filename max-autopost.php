<?php
/**
 * Plugin Name: MAX Autopost (Free)
 * Description: Автопостинг из WordPress в MAX (platform-api2.max.ru): одно сообщение (IMAGE + TEXT + КНОПКА), корректный upload image (полный payload), очередь WP-Cron, retry, логи.
 * Version: 1.11.2
 * Author: Dr.Slon
 * Requires PHP: 8.0
 * Update URI: https://github.com/A-Krivoshen/max-autopost/
 */

if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/class-krv-max-github-updater.php';

final class KRV_MAX_Autopost {

    private const OPT     = 'krv_max_autopost';
    private const LOG_OPT = 'krv_max_autopost_logs';
    private const VER_OPT = 'krv_max_autopost_ver';
    private const CUTOFF_OPT = 'krv_max_autopost_queue_cutoff';
    private const INSTALL_STAMP_OPT = 'krv_max_autopost_install_stamp';
    private const WORKER_ENABLED_OPT = 'krv_max_autopost_worker_enabled';

    private const VERSION = '1.11.2';
    private const UPDATE_REPO_URL = 'https://github.com/A-Krivoshen/max-autopost/';
    /** MAX Bot API host (migration from platform-api.max.ru → platform-api2.max.ru before 2026-07-19). */
    private const API_HOST = 'https://platform-api2.max.ru';

    private const META_STATUS   = '_krv_max_status';   // queued|sent|partial_success|error
    private const META_ERROR    = '_krv_max_error';
    private const META_ATTEMPTS = '_krv_max_attempts';
    private const META_NEXTTRY  = '_krv_max_next_try';
    private const META_QUEUEDAT = '_krv_max_queued_at';
    private const META_QSTAMP   = '_krv_max_queue_stamp';
    private const META_SENTHASH = '_krv_max_sent_hash';
    private const META_TARGET_RESULTS = '_krv_max_target_results';

    private const META_DISABLE  = '_krv_max_disable';
    private const META_OVERRIDE = '_krv_max_override';

    private const CRON_HOOK     = 'krv_max_autopost_cron';
    private const CRON_SCHEDULE = 'krv_max_minute';
    private const CRON_LOCK_KEY = 'krv_max_autopost_lock';
    private const UPGRADE_NOTICE_OPT = 'krv_max_autopost_upgrade_notice';

    private const GITHUB_REPO_URL = 'https://github.com/A-Krivoshen/max-autopost';

    private const MIN_TEXT   = 200;
    private const MAX_TEXT   = 3900;
    private const BATCH_LIMIT = 1;
    private const LOG_LIMIT   = 50;
    /** Max posts touched per bulk queue/requeue click (avoid timeouts / accidental mass send). */
    private const REQUEUE_BATCH = 50;

    // Retry backoff (attempt 1..N). After last element -> error.
    private static array $backoff = [60, 180, 600, 1800, 3600];

    public static function init(): void {
        self::maybe_handle_upgrade();
        self::init_update_checker();

        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);

        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);

        add_action('transition_post_status', [__CLASS__, 'queue_on_publish'], 10, 3);
        add_action('future_to_publish', [__CLASS__, 'queue_on_future_publish'], 10, 1);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);

        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('save_post', [__CLASS__, 'save_metabox'], 10, 2);

        add_action('admin_post_krv_max_send_test', [__CLASS__, 'handle_send_test']);
        add_action('admin_post_krv_max_run_queue', [__CLASS__, 'handle_run_queue']);
        add_action('admin_post_krv_max_requeue_errors', [__CLASS__, 'handle_requeue_errors']);
        add_action('admin_post_krv_max_send_now', [__CLASS__, 'handle_send_now']);
        add_action('admin_post_krv_max_queue_now', [__CLASS__, 'handle_queue_now']);
        add_action('admin_post_krv_max_queue_all_published', [__CLASS__, 'handle_queue_all_published']);
        add_action('admin_post_krv_max_requeue_published_current_settings', [__CLASS__, 'handle_requeue_published_current_settings']);
        add_action('admin_post_krv_max_worker_enable', [__CLASS__, 'handle_worker_enable']);
        add_action('admin_post_krv_max_worker_disable', [__CLASS__, 'handle_worker_disable']);
        add_action('admin_post_krv_max_clear_logs', [__CLASS__, 'handle_clear_logs']);
        add_action('admin_post_krv_max_dismiss_upgrade_notice', [__CLASS__, 'handle_dismiss_upgrade_notice']);

        // Register row/bulk hooks after all CPTs are registered.
        add_action('init', [__CLASS__, 'register_post_type_hooks'], 20);
    }

    private static function init_update_checker(): void {
        if (!class_exists('KRV_MAX_GitHub_Updater')) return;
        KRV_MAX_GitHub_Updater::init(self::UPDATE_REPO_URL, __FILE__, 'max-autopost');
    }

    public static function register_post_type_hooks(): void {
        static $registered = false;
        if ($registered) return;

        foreach (self::supported_post_types() as $post_type) {
            add_filter($post_type . '_row_actions', [__CLASS__, 'row_action'], 10, 2);
            add_filter('bulk_actions-edit-' . $post_type, [__CLASS__, 'bulk_action']);
            add_filter('handle_bulk_actions-edit-' . $post_type, [__CLASS__, 'handle_bulk'], 10, 3);
        }

        $registered = true;
    }

    public static function activate(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }

        $cutoff = time();
        update_option(self::VER_OPT, self::VERSION, false);
        update_option(self::CUTOFF_OPT, $cutoff, false);
        update_option(self::INSTALL_STAMP_OPT, self::new_install_stamp(), false);
        update_option(self::WORKER_ENABLED_OPT, 0, false);
        update_option(self::UPGRADE_NOTICE_OPT, self::VERSION, false);
        self::quarantine_stale_queue($cutoff);
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
        // Clear pending single-events for this hook.
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* ================= SETTINGS ================= */


    private static function maybe_handle_upgrade(): void {
        $stored = (string)get_option(self::VER_OPT, '');
        if ($stored === self::VERSION) {
            return;
        }

        $from = $stored !== '' ? $stored : 'unknown';
        $cutoff = time();
        update_option(self::VER_OPT, self::VERSION, false);
        update_option(self::CUTOFF_OPT, $cutoff, false);
        update_option(self::INSTALL_STAMP_OPT, self::new_install_stamp(), false);
        update_option(self::WORKER_ENABLED_OPT, 0, false);
        update_option(self::UPGRADE_NOTICE_OPT, self::VERSION . '|' . $from, false);
        // Drop leftover single-event spam from older versions.
        wp_clear_scheduled_hook(self::CRON_HOOK);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
        self::quarantine_stale_queue($cutoff);
    }

    private static function new_install_stamp(): string {
        return wp_generate_uuid4() . '-' . (string)time();
    }

    private static function is_worker_enabled(): bool {
        return (int)get_option(self::WORKER_ENABLED_OPT, 0) === 1;
    }

    private static function current_install_stamp(): string {
        $stamp = (string)get_option(self::INSTALL_STAMP_OPT, '');
        if ($stamp !== '') {
            return $stamp;
        }

        $stamp = self::new_install_stamp();
        update_option(self::INSTALL_STAMP_OPT, $stamp, false);
        return $stamp;
    }

    private static function quarantine_stale_queue(int $cutoff): void {
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_query' => [
                ['key' => self::META_STATUS, 'value' => 'queued'],
                [
                    'relation' => 'OR',
                    ['key' => self::META_QUEUEDAT, 'compare' => 'NOT EXISTS'],
                    ['key' => self::META_QUEUEDAT, 'value' => $cutoff, 'type' => 'NUMERIC', 'compare' => '<'],
                ],
            ],
        ]);

        foreach ($posts as $post_id) {
            $post_id = (int)$post_id;
            update_post_meta($post_id, self::META_STATUS, 'error');
            update_post_meta($post_id, self::META_ERROR, 'Старая очередь заблокирована после установки/обновления. Поставьте пост в очередь вручную.');
            update_post_meta($post_id, self::META_NEXTTRY, 0);
            delete_post_meta($post_id, self::META_QSTAMP);
        }
    }


    private static function defaults(): array {
        return [
            'token'         => '',
            'chat_id'       => '',
            'additional_chat_ids' => '',
            'include_image' => 1,
            'image_source_mode' => 'post_or_site',
            'add_button'    => 1,
            'button_text'   => 'Читать',
            'add_subscribe_button' => 0,
            'subscribe_button_text' => 'Подписаться',
            'subscribe_button_url'  => '',
            'max_text_limit' => self::MAX_TEXT,
            'message_format' => 'plain_text',
            'bold_title' => 1,
            'append_in_limit' => 1,
            'post_append_text' => '',
            'publish_custom_fields' => 0,
            'enabled_post_types'    => ['post'],
            'custom_fields_map'     => '',
            'notify'        => 1,
            'debug'         => 0,
            'enable_worker_after_test' => 0,
        ];
    }

    private static function get_settings(): array {
        $raw = get_option(self::OPT, []);
        $raw = is_array($raw) ? $raw : [];
        return wp_parse_args($raw, self::defaults());
    }

    public static function register_settings(): void {
        register_setting(self::OPT, self::OPT, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default'           => self::defaults(),
            'capability'        => 'manage_options',
        ]);
    }

    public static function sanitize_settings($in): array {
        $d = self::defaults();
        $in = is_array($in) ? $in : [];
        $prev = get_option(self::OPT, []);
        $prev = is_array($prev) ? $prev : [];

        $out = [];
        // Empty token field = keep previously saved token (so password field can stay blank).
        $token_in = isset($in['token']) ? self::sanitize_token_value((string)$in['token']) : '';
        if ($token_in === '' || $token_in === '********') {
            $out['token'] = self::sanitize_token_value((string)($prev['token'] ?? $d['token']));
        } else {
            $out['token'] = $token_in;
        }
        $out['chat_id'] = self::sanitize_chat_id(isset($in['chat_id']) ? (string)$in['chat_id'] : $d['chat_id']);
        $out['additional_chat_ids'] = self::normalize_chat_ids_text(isset($in['additional_chat_ids']) ? (string)$in['additional_chat_ids'] : $d['additional_chat_ids']);

        $out['include_image'] = !empty($in['include_image']) ? 1 : 0;

        $mode = isset($in['image_source_mode']) ? sanitize_key((string)$in['image_source_mode']) : (string)$d['image_source_mode'];
        $out['image_source_mode'] = self::normalize_image_source_mode($mode);

        $out['add_button']    = !empty($in['add_button']) ? 1 : 0;

        $out['button_text'] = isset($in['button_text']) ? sanitize_text_field((string)$in['button_text']) : $d['button_text'];
        if ($out['button_text'] === '') $out['button_text'] = $d['button_text'];
        $out['add_subscribe_button'] = !empty($in['add_subscribe_button']) ? 1 : 0;
        $out['subscribe_button_text'] = isset($in['subscribe_button_text']) ? sanitize_text_field((string)$in['subscribe_button_text']) : $d['subscribe_button_text'];
        if ($out['subscribe_button_text'] === '') $out['subscribe_button_text'] = $d['subscribe_button_text'];
        $out['subscribe_button_url'] = isset($in['subscribe_button_url']) ? self::sanitize_http_url((string)$in['subscribe_button_url']) : $d['subscribe_button_url'];

        $text_limit = isset($in['max_text_limit']) ? (int)$in['max_text_limit'] : (int)$d['max_text_limit'];
        $out['max_text_limit'] = self::normalize_text_limit($text_limit);
        $format = isset($in['message_format']) ? sanitize_key((string)$in['message_format']) : (string)$d['message_format'];
        $out['message_format'] = self::normalize_message_format($format);
        $out['bold_title'] = !empty($in['bold_title']) ? 1 : 0;
        $out['append_in_limit'] = !empty($in['append_in_limit']) ? 1 : 0;
        $append_raw = isset($in['post_append_text']) ? (string)$in['post_append_text'] : $d['post_append_text'];
        $out['post_append_text'] = trim((string)wp_kses($append_raw, self::append_allowed_html_tags()));

        $out['publish_custom_fields'] = !empty($in['publish_custom_fields']) ? 1 : 0;

        $types_in = isset($in['enabled_post_types']) && is_array($in['enabled_post_types']) ? $in['enabled_post_types'] : [];
        $types = array_values(array_unique(array_filter(array_map(static fn($k) => sanitize_key((string)$k), $types_in))));

        $allowed = array_keys(self::available_post_types());
        $types = array_values(array_intersect($types, $allowed));
        if (empty($types)) $types = ['post'];
        $out['enabled_post_types'] = $types;

        $out['custom_fields_map'] = isset($in['custom_fields_map']) ? sanitize_textarea_field((string)$in['custom_fields_map']) : $d['custom_fields_map'];

        $out['notify'] = !empty($in['notify']) ? 1 : 0;
        $out['debug']  = !empty($in['debug']) ? 1 : 0;
        $out['enable_worker_after_test'] = !empty($in['enable_worker_after_test']) ? 1 : 0;

        return $out;
    }

    private static function sanitize_token_value(string $token): string {
        $token = preg_replace('/[\x00-\x1F\x7F]+/u', '', $token);
        return trim((string)$token);
    }

    private static function sanitize_http_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = esc_url_raw($url, ['http', 'https']);
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string)(wp_parse_url($url, PHP_URL_SCHEME) ?: ''));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    private static function token(array $s): string {
        if (defined('KRV_MAX_TOKEN') && is_string(KRV_MAX_TOKEN) && trim(KRV_MAX_TOKEN) !== '') {
            return self::sanitize_token_value((string)KRV_MAX_TOKEN);
        }
        return self::sanitize_token_value((string)$s['token']);
    }

    private static function chat_id(array $s): string {
        if (defined('KRV_MAX_CHAT_ID') && is_string(KRV_MAX_CHAT_ID) && trim(KRV_MAX_CHAT_ID) !== '') {
            return self::sanitize_chat_id((string)KRV_MAX_CHAT_ID);
        }
        return self::sanitize_chat_id((string)$s['chat_id']);
    }

    private static function target_chat_ids(array $s): array {
        $targets = [];

        $primary = self::chat_id($s);
        if ($primary !== '') {
            $targets[] = $primary;
        }

        $extra = self::normalize_chat_ids_text((string)($s['additional_chat_ids'] ?? ''));
        if ($extra !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $extra) ?: [] as $row) {
                $id = self::sanitize_chat_id((string)$row);
                if ($id !== '') {
                    $targets[] = $id;
                }
            }
        }

        $targets = array_values(array_unique($targets));
        return array_values(array_filter($targets, static fn($id) => $id !== ''));
    }

    /* ================= ADMIN UI ================= */

    public static function admin_menu(): void {
        add_menu_page('MAX Autopost','MAX Autopost','manage_options','krv-max-autopost',[__CLASS__,'render_page'],'dashicons-megaphone',59);
    }

    public static function admin_notices(): void {
        if (!current_user_can('manage_options')) return;

        $msg = get_transient('krv_max_notice');
        if (is_array($msg) && !empty($msg['text'])) {
            $type = in_array(($msg['type'] ?? ''), ['success','error','warning','info'], true) ? $msg['type'] : 'info';
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg['text']) . '</p></div>';
            delete_transient('krv_max_notice');
        }

        $upgrade = (string)get_option(self::UPGRADE_NOTICE_OPT, '');
        if ($upgrade !== '') {
            $parts = explode('|', $upgrade, 2);
            $ver = $parts[0] !== '' ? $parts[0] : self::VERSION;
            $dismiss = wp_nonce_url(
                admin_url('admin-post.php?action=krv_max_dismiss_upgrade_notice'),
                'krv_max_dismiss_upgrade_notice'
            );
            echo '<div class="notice notice-info"><p><strong>MAX Autopost '.esc_html($ver).'</strong> — плагин обновлён. ';
            echo 'API: <code>platform-api2.max.ru</code>. Автоворкер выключен, старая очередь заблокирована (защита от массовой рассылки). ';
            echo 'Проверьте <a href="'.esc_url(admin_url('admin.php?page=krv-max-autopost&tab=settings')).'">настройки</a> и «Отправить тест». ';
            echo 'Если SSL-ошибка — сертификат Минцифры на хостинге: <a href="https://www.gosuslugi.ru/crt" target="_blank" rel="noopener noreferrer">gosuslugi.ru/crt</a>. ';
            echo '<a href="'.esc_url($dismiss).'">Скрыть</a>.</p></div>';
        }
    }

    private static function notice(string $type, string $text): void {
        set_transient('krv_max_notice', ['type'=>$type,'text'=>$text], 60);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;

        $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'settings';
        $s = self::get_settings();

        echo '<div class="wrap"><h1>MAX Autopost (Free) ' . esc_html(self::VERSION) . '</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        echo self::tab_link('settings','Настройки',$tab);
        echo self::tab_link('queue','Очередь',$tab);
        echo self::tab_link('logs','Логи',$tab);
        echo self::tab_link('help','Техпомощь',$tab);
        echo '</h2>';

        if ($tab === 'settings') self::tab_settings($s);
        elseif ($tab === 'queue') self::tab_queue();
        elseif ($tab === 'logs') self::tab_logs();
        else self::tab_help($s);

        self::render_support_block();
        echo '</div>';
    }


    private static function render_support_block(): void {
        echo '<hr style="margin:22px 0 16px;">';

        echo '<div style="max-width:980px;background:#fff;border:1px solid #dcdcde;padding:14px;margin-bottom:12px;">';
        echo '<p style="margin:0;font-size:14px;"><strong>Поддержка плагина:</strong> по всем вопросам пишите <a href="mailto:aleksey@krivoshein.site">aleksey@krivoshein.site</a>.</p>';
        echo '</div>';

        echo '<div style="max-width:980px;background:#fff;border:1px solid #dcdcde;padding:14px;">';
        echo '<p style="margin-top:0;"><strong>Реклама</strong> · партнёрский блок FirstVDS.</p>';
        echo '<p style="margin:0 0 12px;">VPS/VDS-хостинг для сайтов, проектов и серверных задач.</p>';
        echo '<p style="margin:0;"><a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="'.esc_url('https://firstvds.ru/?from=1168822').'">Перейти на FirstVDS</a></p>';
        echo '</div>';
    }

    private static function settings_section(string $title, string $desc = ''): void {
        echo '</tbody></table>';
        echo '<h2 style="margin:22px 0 8px;">'.esc_html($title).'</h2>';
        if ($desc !== '') {
            echo '<p class="description" style="margin-top:0;">'.esc_html($desc).'</p>';
        }
        echo '<table class="form-table" role="presentation"><tbody>';
    }

    private static function tab_link(string $tab, string $label, string $active): string {
        $cls = ($tab === $active) ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url = admin_url('admin.php?page=krv-max-autopost&tab=' . $tab);
        return '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private static function tab_settings(array $s): void {
        $targets = self::target_chat_ids($s);
        $targets_n = count($targets);
        $token_from_config = defined('KRV_MAX_TOKEN') && is_string(KRV_MAX_TOKEN) && trim(KRV_MAX_TOKEN) !== '';
        $has_saved_token = !$token_from_config && self::sanitize_token_value((string)($s['token'] ?? '')) !== '';

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPT);

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th colspan="2" style="padding-bottom:0;"><h2 style="margin:0;">Подключение</h2></th></tr>';

        echo '<tr><th>Token</th><td>';
        if ($token_from_config) {
            echo '<input type="password" class="regular-text" value="" disabled placeholder="Задан в wp-config.php">';
            echo '<p class="description">Используется <code>define(\'KRV_MAX_TOKEN\', \'...\');</code> — это предпочтительный и более безопасный способ.</p>';
        } else {
            echo '<input type="password" class="regular-text" name="'.esc_attr(self::OPT).'[token]" value="" autocomplete="new-password" placeholder="'.esc_attr($has_saved_token ? '•••• сохранён (оставьте пустым, чтобы не менять)' : 'Вставьте token бота').'">';
            echo '<p class="description" style="color:#996800;"><strong>Безопасность:</strong> token в настройках хранится в базе WordPress (таблица options). Это удобно, но при утечке бэкапа/БД ключ скомпрометирован. Надёжнее вынести в <code>wp-config.php</code>: <code>define(\'KRV_MAX_TOKEN\', \'ваш_токен\');</code> — тогда поле в админке можно не заполнять.</p>';
        }
        echo '</td></tr>';

        echo '<tr><th>Основной Chat ID</th><td><input type="text" class="regular-text" name="'.esc_attr(self::OPT).'[chat_id]" value="'.esc_attr($s['chat_id']).'">';
        echo '<p class="description">Можно вынести в wp-config.php: <code>define(\'KRV_MAX_CHAT_ID\', \'...\');</code></p></td></tr>';
        echo '<tr><th>Дополнительные Chat ID</th><td><textarea name="'.esc_attr(self::OPT).'[additional_chat_ids]" class="large-text code" rows="5" placeholder="123456
-100987654
chat_abcd123">'.esc_textarea((string)$s['additional_chat_ids']).'</textarea>';
        echo '<p class="description">По одному ID на строку (каналы и группы). Пустые строки и дубликаты отбрасываются.</p>';
        if ($targets_n > 0) {
            $warn = $targets_n > 1
                ? ' <strong style="color:#b32d2e;">Каждый пост уйдёт в '.$targets_n.' чата(ов) = до '.$targets_n.' сообщений в MAX.</strong>'
                : '';
            echo '<p class="description"><strong>Целей сейчас: '.esc_html((string)$targets_n).'.</strong>'.$warn.'</p>';
        } else {
            echo '<p class="description" style="color:#b32d2e;"><strong>Целей: 0.</strong> Укажите основной или дополнительные Chat ID.</p>';
        }
        echo '</td></tr>';

        self::settings_section('Контент и картинка', 'Что попадает в текст сообщения MAX.');

        echo '<tr><th>Картинка</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[include_image]" value="1" '.checked((int)$s['include_image'],1,false).'> Включить изображение</label>';
        echo '<div style="margin-top:8px;">';
        echo '<label>Источник изображения: ';
        echo '<select name="'.esc_attr(self::OPT).'[image_source_mode]">';
        echo '<option value="post_or_site" '.selected((string)$s['image_source_mode'], 'post_or_site', false).'>Из новости, иначе изображение сайта</option>';
        echo '<option value="post_only" '.selected((string)$s['image_source_mode'], 'post_only', false).'>Только изображение новости (без подмены)</option>';
        echo '<option value="site_only" '.selected((string)$s['image_source_mode'], 'site_only', false).'>Всегда изображение сайта</option>';
        echo '</select>';
        echo '</label>';
        echo '</div>';
        echo '<p class="description">Можно отключить подстановку изображения сайта, если у записи нет своей картинки.</p>';
        echo '</td></tr>';

        self::settings_section('Кнопки', 'Inline-кнопки в сообщении MAX (attachment после картинки).');

        echo '<tr><th>Кнопка «Читать»</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[add_button]" value="1" '.checked((int)$s['add_button'],1,false).'> Включить кнопку «Читать»</label><br>';
        echo '<input type="text" name="'.esc_attr(self::OPT).'[button_text]" value="'.esc_attr($s['button_text']).'" style="width:220px;">';
        echo '<p class="description">Ссылка ведёт на запись сайта.</p>';
        echo '</td></tr>';
        echo '<tr><th>Кнопка подписки</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[add_subscribe_button]" value="1" '.checked((int)$s['add_subscribe_button'],1,false).'> Включить кнопку «Подписаться»</label><br>';
        echo '<input type="text" name="'.esc_attr(self::OPT).'[subscribe_button_text]" value="'.esc_attr($s['subscribe_button_text']).'" style="width:220px;" placeholder="Подписаться"> ';
        echo '<input type="url" class="regular-text" name="'.esc_attr(self::OPT).'[subscribe_button_url]" value="'.esc_attr($s['subscribe_button_url']).'" placeholder="https://max.ru/...">';
        echo '<p class="description">Отдельная кнопка. Не связана с «Текстом после записи».</p>';
        echo '</td></tr>';

        self::settings_section('Текст и формат');

        $append_preview = self::get_append_text_variants($s);
        $append_len = mb_strlen((string)$append_preview['plain'], 'UTF-8');
        $limit_ui = self::normalize_text_limit((int)$s['max_text_limit']);
        $append_in_limit_ui = !empty($s['append_in_limit']);
        $sep = ($append_len > 0) ? 2 : 0;
        $main_budget_ui = $append_in_limit_ui
            ? max(0, $limit_ui - $append_len - $sep)
            : $limit_ui;

        echo '<tr><th>Длина текста</th><td>';
        echo '<input type="number" min="'.esc_attr((string)self::MIN_TEXT).'" max="'.esc_attr((string)self::MAX_TEXT).'" step="1" name="'.esc_attr(self::OPT).'[max_text_limit]" value="'.esc_attr((string)(int)$s['max_text_limit']).'" style="width:130px;">';
        echo '<p class="description">Лимит для MAX: от '.esc_html((string)self::MIN_TEXT).' до '.esc_html((string)self::MAX_TEXT).' символов (plain-длина без HTML-тегов).</p>';
        if ($append_len > 0) {
            if ($append_in_limit_ui) {
                echo '<p class="description"><strong>Справка:</strong> подпись ≈ <code>'.esc_html((string)$append_len).'</code>, основной текст ≤ <code>'.esc_html((string)$main_budget_ui).'</code>, <strong>итого ≤ '.esc_html((string)$limit_ui).'</strong>.</p>';
            } else {
                $est = min(self::MAX_TEXT, $limit_ui + $sep + $append_len);
                echo '<p class="description"><strong>Справка:</strong> основной ≤ <code>'.esc_html((string)$limit_ui).'</code> + подпись ≈ <code>'.esc_html((string)$append_len).'</code> → ориентир <code>'.esc_html((string)$est).'</code> (потолок API '.esc_html((string)self::MAX_TEXT).').</p>';
            }
        }
        echo '</td></tr>';

        echo '<tr><th>Формат сообщения</th><td>';
        echo '<select name="'.esc_attr(self::OPT).'[message_format]">';
        echo '<option value="plain_text" '.selected((string)$s['message_format'], 'plain_text', false).'>plain text</option>';
        echo '<option value="formatted" '.selected((string)$s['message_format'], 'formatted', false).'>formatted</option>';
        echo '<option value="excerpt_plain" '.selected((string)$s['message_format'], 'excerpt_plain', false).'>excerpt plain</option>';
        echo '<option value="title_only" '.selected((string)$s['message_format'], 'title_only', false).'>только заголовок</option>';
        echo '</select>';
        echo '<p class="description">plain text — совместимый режим; formatted — HTML; excerpt plain — короткий анонс; <strong>только заголовок</strong> — title + картинка + подпись + кнопки (без текста записи).</p>';
        echo '<label style="display:block;margin-top:8px;"><input type="checkbox" name="'.esc_attr(self::OPT).'[bold_title]" value="1" '.checked((int)($s['bold_title'] ?? 1), 1, false).'> Выделять заголовок поста <strong>жирным</strong></label>';
        echo '<p class="description">В formatted/plain/excerpt/title_only при включённой галочке заголовок уходит как <code>&lt;strong&gt;</code> (format=html).</p>';
        echo '</td></tr>';

        echo '<tr><th>Текст после записи</th><td>';
        echo '<textarea name="'.esc_attr(self::OPT).'[post_append_text]" class="large-text code" rows="4" placeholder="<a href=&quot;https://max.ru/...&quot;>Подписаться на канал</a>">'.esc_textarea((string)$s['post_append_text']).'</textarea>';
        echo '<p class="description">Дополнительный текст в конце. В formatted: whitelist <code>a</code>, <code>br</code>. В plain/excerpt — plain (ссылки как текст + URL).</p>';
        echo '<label style="display:block;margin-top:8px;"><input type="checkbox" name="'.esc_attr(self::OPT).'[append_in_limit]" value="1" '.checked((int)($s['append_in_limit'] ?? 1), 1, false).'> Учитывать «Текст после записи» в общем лимите сообщения</label>';
        echo '<p class="description"><strong>Вкл:</strong> «Длина текста» = лимит всего сообщения; основной анонс ужимается под подпись. <strong>Выкл:</strong> лимит только на основной текст, подпись добавляется сверху (итог может быть больше, жёсткий потолок '.esc_html((string)self::MAX_TEXT).').</p>';
        echo '</td></tr>';

        $post_types = self::available_post_types();
        $enabled_types = isset($s['enabled_post_types']) && is_array($s['enabled_post_types']) ? $s['enabled_post_types'] : ['post'];

        echo '<tr><th>Типы записей</th><td>';
        echo '<p style="margin-top:0;">Выберите, какие типы записей (включая кастомные) отправлять в MAX:</p>';
        foreach ($post_types as $pt_key => $pt_label) {
            $checked = in_array($pt_key, $enabled_types, true) ? ' checked="checked"' : '';
            echo '<label style="display:block;margin:0 0 6px;"><input type="checkbox" name="'.esc_attr(self::OPT).'[enabled_post_types][]" value="'.esc_attr($pt_key).'"'.$checked.'> '.esc_html($pt_label).' <code>('.esc_html($pt_key).')</code></label>';
        }
        echo '<p class="description">Если не выбрать ни один тип — будет использоваться <code>post</code>.</p>';
        echo '</td></tr>';

        echo '<tr><th>Кастомные поля</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[publish_custom_fields]" value="1" '.checked((int)$s['publish_custom_fields'],1,false).'> Публиковать значения выбранных полей</label>';
        echo '<textarea name="'.esc_attr(self::OPT).'[custom_fields_map]" class="large-text code" rows="5" placeholder="price|Цена
sku|Артикул">'.esc_textarea((string)$s['custom_fields_map']).'</textarea>';
        echo '<p class="description">По одному полю на строку: <code>meta_key|Подпись</code> или только <code>meta_key</code>. Непустые значения добавляются в конец текста публикации.</p>';
        echo '</td></tr>';

        self::settings_section('Очередь и отладка');

        echo '<tr><th>Уведомления MAX</th><td><label><input type="checkbox" name="'.esc_attr(self::OPT).'[notify]" value="1" '.checked((int)$s['notify'],1,false).'> Отправлять с notify (пуш подписчикам)</label></td></tr>';
        echo '<tr><th>Отладка</th><td><label><input type="checkbox" name="'.esc_attr(self::OPT).'[debug]" value="1" '.checked((int)$s['debug'],1,false).'> Расширенные логи (token/URL маскируются)</label></td></tr>';
        echo '<tr><th>После теста</th><td><label><input type="checkbox" name="'.esc_attr(self::OPT).'[enable_worker_after_test]" value="1" '.checked((int)($s['enable_worker_after_test'] ?? 0),1,false).'> Включить автоворкер только если тест успешен</label>';
        echo '<p class="description">По умолчанию <strong>выкл</strong>: успешный тест больше сам не запускает авторассылку очереди.</p></td></tr>';
        echo '</tbody></table>';

        submit_button('Сохранить');
        echo '</form>';

        echo '<hr><h2>Тест отправки</h2>';
        if ($targets_n > 1) {
            echo '<p class="description" style="color:#b32d2e;">Тест уйдёт в <strong>'.esc_html((string)$targets_n).'</strong> чата(ов).</p>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_send_test');
        echo '<input type="hidden" name="action" value="krv_max_send_test">';
        submit_button('Отправить тест','secondary','submit',false);
        echo '</form>';
    }

    private static function count_published_supported(): int {
        $q = new WP_Query([
            'post_type' => self::supported_post_types(),
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        return (int)$q->found_posts;
    }

    private static function queue_status_count(?string $status = null): int {
        $args = [
            'post_type' => self::supported_post_types(),
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if ($status !== null && $status !== '') {
            $args['meta_query'] = [
                ['key' => self::META_STATUS, 'value' => $status],
            ];
            $args['meta_key'] = self::META_STATUS;
        }

        $q = new WP_Query($args);
        $count = (int)$q->found_posts;
        wp_reset_postdata();
        return $count;
    }

    private static function tab_queue(): void {
        $worker_on = self::is_worker_enabled();
        $status_text = $worker_on ? 'ВКЛ' : 'ВЫКЛ';
        $status_color = $worker_on ? '#2e7d32' : '#b71c1c';
        $targets_n = count(self::target_chat_ids(self::get_settings()));
        $pub_count = self::count_published_supported();
        $err_count = self::queue_status_count('error');
        $batch = self::REQUEUE_BATCH;
        $est_all = $pub_count * max(1, $targets_n);
        $est_batch = min($pub_count, $batch) * max(1, $targets_n);

        $status_filter = isset($_GET['qstatus']) ? sanitize_key((string)$_GET['qstatus']) : 'all';
        if (!in_array($status_filter, ['all', 'queued', 'error', 'partial_success', 'sent'], true)) {
            $status_filter = 'all';
        }

        echo '<div style="margin:8px 0 12px;padding:8px 12px;background:#fff;border-left:4px solid '.esc_attr($status_color).';">';
        echo '<strong>Автоворкер:</strong> <span style="color:'.esc_attr($status_color).';font-weight:700;">'.esc_html($status_text).'</span>';
        echo $worker_on
            ? '<span style="margin-left:8px;color:#555;">очередь обрабатывается автоматически (1 пост / тик).</span>'
            : '<span style="margin-left:8px;color:#555;">автоотправка выключена — только ручной запуск или включение воркера.</span>';
        echo '<br><span class="description">Целей (chat ID): <strong>'.esc_html((string)$targets_n).'</strong>. Опубликовано подходящих записей: <strong>'.esc_html((string)$pub_count).'</strong>. ';
        if ($targets_n > 1) {
            echo '1 пост → до <strong>'.esc_html((string)$targets_n).'</strong> сообщений в MAX.';
        }
        echo '</span></div>';

        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0;">';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_run_queue');
        echo '<input type="hidden" name="action" value="krv_max_run_queue">';
        submit_button('Запустить очередь сейчас','secondary','submit',false);
        echo '</form>';

        if ($worker_on) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('krv_max_worker_disable');
            echo '<input type="hidden" name="action" value="krv_max_worker_disable">';
            submit_button('Выключить автоворкер','secondary','submit',false);
            echo '</form>';
        } else {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('krv_max_worker_enable');
            echo '<input type="hidden" name="action" value="krv_max_worker_enable">';
            submit_button('Включить автоворкер','secondary','submit',false);
            echo '</form>';
        }

        echo '<p class="description" style="margin:6px 0 0;flex-basis:100%;">Сохранение Token/Chat ID не гоняет старую очередь. Ручной запуск — 1 элемент. Массовые кнопки ниже берут пачками по '.esc_html((string)$batch).' постов.</p>';

        $confirm_err = 'Вернуть в очередь ошибочные посты ('.(int)$err_count.')? Целей: '.(int)$targets_n.'.';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_requeue_errors');
        echo '<input type="hidden" name="action" value="krv_max_requeue_errors">';
        submit_button('Вернуть ошибки в очередь','secondary','submit',false,['onclick'=>'return confirm('.wp_json_encode($confirm_err).');']);
        echo '</form>';

        $confirm_all = 'Поставить в очередь до '.$batch.' опубликованных (из '.(int)$pub_count.')? Целей: '.(int)$targets_n.', ориентир до '.(int)$est_batch.' сообщений в MAX. Повторите кнопку для следующей пачки.';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_queue_all_published');
        echo '<input type="hidden" name="action" value="krv_max_queue_all_published">';
        submit_button('В очередь: published (пачка '.$batch.')','secondary','submit',false,['onclick'=>'return confirm('.wp_json_encode($confirm_all).');']);
        echo '</form>';

        $confirm_re = 'Переочередь до '.$batch.' published с текущими настройками (сброс sent-hash). Целей: '.(int)$targets_n.', ориентир до '.(int)$est_batch.' сообщений. Это может переотправить посты в MAX!';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_requeue_published_current_settings');
        echo '<input type="hidden" name="action" value="krv_max_requeue_published_current_settings">';
        submit_button('Переочередь published (пачка '.$batch.')','secondary','submit',false,['onclick'=>'return confirm('.wp_json_encode($confirm_re).');']);
        echo '</form>';
        echo '</div>';

        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 12px;">';
        echo '<strong>Фильтр очереди:</strong>';
        foreach (['all' => 'Все', 'queued' => 'В очереди', 'error' => 'Ошибка', 'partial_success' => 'Частично', 'sent' => 'Отправлено'] as $key => $label) {
            $url = add_query_arg([
                'page' => 'krv-max-autopost',
                'tab' => 'queue',
                'qstatus' => $key,
            ], admin_url('admin.php'));
            $cls = $status_filter === $key ? 'button button-primary' : 'button';
            echo '<a class="'.esc_attr($cls).'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</div>';

        $counts = [
            'all' => self::queue_status_count(null),
            'queued' => self::queue_status_count('queued'),
            'error' => self::queue_status_count('error'),
            'partial_success' => self::queue_status_count('partial_success'),
            'sent' => self::queue_status_count('sent'),
        ];

        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 14px;">';
        foreach (['all' => 'Все', 'queued' => 'В очереди', 'error' => 'Ошибка', 'partial_success' => 'Частично', 'sent' => 'Отправлено'] as $key => $label) {
            $url = add_query_arg([
                'page' => 'krv-max-autopost',
                'tab' => 'queue',
                'qstatus' => $key,
            ], admin_url('admin.php'));
            $border = $status_filter === $key ? '#2271b1' : '#dcdcde';
            echo '<a href="'.esc_url($url).'" style="text-decoration:none;background:#fff;border:1px solid '.esc_attr($border).';border-radius:6px;padding:8px 10px;min-width:90px;display:inline-block;">';
            echo '<div style="font-size:12px;color:#646970;">'.esc_html($label).'</div>';
            echo '<div style="font-size:18px;font-weight:700;color:#1d2327;">'.esc_html((string)($counts[$key] ?? 0)).'</div>';
            echo '</a>';
        }
        echo '</div>';

        $query_args = [
            'post_type'=>self::supported_post_types(),
            'post_status'=>'any',
            'posts_per_page'=>50,
            'orderby'=>'date',
            'order'=>'DESC',
        ];

        if ($status_filter !== 'all') {
            $query_args['meta_query'] = [
                ['key'=>self::META_STATUS, 'value'=>$status_filter],
            ];
            $query_args['meta_key'] = self::META_STATUS;
        }

        $q = new WP_Query($query_args);

        echo '<table class="widefat striped"><thead><tr><th>Пост</th><th>Тип</th><th>Статус</th><th>Попытки</th><th>Next try</th><th>Результат по целям</th><th>Ошибка</th><th>Действия</th></tr></thead><tbody>';
        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                $id = get_the_ID();
                $st  = (string)get_post_meta($id,self::META_STATUS,true);
                $att = (int)get_post_meta($id,self::META_ATTEMPTS,true);
                $nt  = (int)get_post_meta($id,self::META_NEXTTRY,true);
                $err = (string)get_post_meta($id,self::META_ERROR,true);
                $results = get_post_meta($id, self::META_TARGET_RESULTS, true);
                $targets_summary = self::target_results_summary($results);

                echo '<tr>';
                echo '<td><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html(get_the_title()).'</a></td>';
                echo '<td>'.esc_html(get_post_type($id) ?: '-').'</td>';
                echo '<td>'.esc_html($st ?: '-').'</td>';
                echo '<td>'.esc_html((string)$att).'</td>';
                echo '<td>'.esc_html($nt ? wp_date('Y-m-d H:i:s',$nt) : '-').'</td>';
                echo '<td title="'.esc_attr($targets_summary).'" style="max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'.esc_html($targets_summary).'</td>';
                echo '<td title="'.esc_attr($err).'" style="max-width:520px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'.esc_html($err).'</td>';

                echo '<td style="white-space:nowrap;">';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;">';
                wp_nonce_field('krv_max_send_now_'.(int)$id);
                echo '<input type="hidden" name="action" value="krv_max_send_now">';
                echo '<input type="hidden" name="post_id" value="'.esc_attr((string)(int)$id).'">';
                echo '<button type="submit" class="button button-small">Отправить</button></form> ';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;">';
                wp_nonce_field('krv_max_queue_now_'.(int)$id);
                echo '<input type="hidden" name="action" value="krv_max_queue_now">';
                echo '<input type="hidden" name="post_id" value="'.esc_attr((string)(int)$id).'">';
                echo '<button type="submit" class="button button-small">В очередь</button></form>';
                echo '</td>';
                echo '</tr>';
            }
            wp_reset_postdata();
        } else {
            echo '<tr><td colspan="8">Очередь пуста.</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function tab_logs(): void {
        $logs = get_option(self::LOG_OPT, []);
        $logs = is_array($logs) ? $logs : [];

        echo '<h2>Логи</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0 0 12px;">';
        wp_nonce_field('krv_max_clear_logs');
        echo '<input type="hidden" name="action" value="krv_max_clear_logs">';
        submit_button('Очистить логи','secondary','submit',false,['onclick'=>"return confirm('Очистить все логи плагина?');"]);
        echo '</form>';
        echo '<table class="widefat striped"><thead><tr><th>Время</th><th>Пост</th><th>Шаг</th><th>HTTP</th><th>Сообщение</th></tr></thead><tbody>';
        if (!empty($logs)) {
            foreach ($logs as $row) {
                $t = (int)($row['time'] ?? 0);
                $pid = (int)($row['post_id'] ?? 0);
                $step = (string)($row['step'] ?? '');
                $http = (string)($row['http'] ?? '');
                $msg = (string)($row['msg'] ?? '');

                echo '<tr>';
                echo '<td>'.esc_html($t ? wp_date('Y-m-d H:i:s',$t) : '-').'</td>';
                echo '<td>'.esc_html((string)$pid).'</td>';
                echo '<td>'.esc_html($step).'</td>';
                echo '<td>'.esc_html($http).'</td>';
                echo '<td title="'.esc_attr($msg).'" style="max-width:760px;word-break:break-word;">'.esc_html($msg).'</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="5">Логи пустые.</td></tr>';
        }
        echo '</tbody></table>';
    }


    private static function tab_help(array $s): void {
        echo '<div style="max-width:980px;background:#fff;border:1px solid #dcdcde;padding:16px;margin-top:14px;">';
        echo '<h2 style="margin-top:0;">Как получить Token и Chat ID для MAX</h2>';
        echo '<ol style="line-height:1.6;">';
        echo '<li>Создайте чат-бота в MAX для партнёров (раздел <strong>Чат-бот и мини-приложение</strong>).</li>';
        echo '<li>В разделе <strong>Интеграция</strong> получите токен. Безопаснее: <code>define(\'KRV_MAX_TOKEN\', \'...\');</code> в <code>wp-config.php</code>. В админке token хранится в БД — удобно, но менее безопасно при утечке бэкапа.</li>';
        echo '<li>Добавьте бота в нужную группу/канал в MAX, где будут публикации.</li>';
        echo '<li>Отправьте любое сообщение в эту группу (чтобы чат появился в списке API).</li>';
        echo '<li>Ниже нажмите кнопку поиска — плагин попробует показать доступные Chat ID.</li>';
        echo '</ol>';
        echo '<p><strong>Теперь плагин поддерживает отправку:</strong> в канал, в группу/групповой чат и одновременно в несколько каналов/групп.</p>';
        echo '<p><strong>Формат дополнительных Chat ID:</strong> по одному значению на строку (например: <code>123456</code>, <code>-100987654</code>, <code>chat_abcd123</code>).</p>';
        echo '<p><strong>Важно:</strong> если список пуст, проверьте права бота в группе и отправьте тестовое сообщение в чат ещё раз.</p>';

        echo '<h3>API MAX (с 19 июля 2026)</h3>';
        echo '<p>Плагин обращается к <code>'.esc_html(self::api_base()).'</code> (миграция с <code>platform-api.max.ru</code>).</p>';
        echo '<p>По требованиям MAX на стороне <strong>сервера WordPress</strong> (хостинг) в доверенные корневые сертификаты должен быть установлен <strong>сертификат Минцифры</strong>. Пользователю сайта обычно ничего ставить на ПК не нужно — это задача хостинга/администратора сервера. Инструкция: <a href="https://www.gosuslugi.ru/crt" target="_blank" rel="noopener noreferrer">gosuslugi.ru/crt</a>.</p>';
        echo '<p>Если после обновления тест падает с ошибкой SSL/certificate — напишите в поддержку хостинга: «нужно добавить корневые сертификаты Минцифры (НЦУЦ) в системное хранилище CA».</p>';

        $token = self::token($s);
        if ($token === '') {
            echo '<div class="notice notice-warning inline"><p>Сначала укажите Token на вкладке «Настройки», затем вернитесь сюда.</p></div>';
        } else {
            $res = self::discover_chats($token);
            if (!empty($res['error'])) {
                echo '<div class="notice notice-error inline"><p>Не удалось получить чаты: '.esc_html((string)$res['error']).'</p></div>';
            } else {
                $items = $res['items'] ?? [];
                if (!empty($items)) {
                    echo '<h3>Найденные Chat ID</h3>';
                    echo '<table class="widefat striped" style="max-width:940px;"><thead><tr><th>Chat ID</th><th>Название</th><th>Тип</th></tr></thead><tbody>';
                    foreach ($items as $it) {
                        echo '<tr>';
                        echo '<td><code>'.esc_html((string)($it['id'] ?? '')).'</code></td>';
                        echo '<td>'.esc_html((string)($it['title'] ?? '-')).'</td>';
                        echo '<td>'.esc_html((string)($it['type'] ?? '-')).'</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<div class="notice notice-info inline"><p>Чаты не найдены. Добавьте бота в группу, отправьте туда сообщение и обновите страницу.</p></div>';
                }
            }
        }

        echo '<h3>Контакты</h3>';
        echo '<p>По всем вопросам: <a href="mailto:aleksey@krivoshein.site">aleksey@krivoshein.site</a>.</p>';
        echo '<p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="'.esc_url(self::GITHUB_REPO_URL).'">Оставить звезду на GitHub</a></p>';
        echo '<p class="description" style="margin-top:0;">Откроется страница репозитория — нажмите кнопку <strong>Star</strong> справа сверху.</p>';
        echo '</div>';
    }

    /* ================= METABOX ================= */

    public static function add_metabox(): void {
        foreach (self::supported_post_types() as $post_type) {
            add_meta_box('krv_max_box','MAX Autopost',[__CLASS__,'render_metabox'],$post_type,'side');
        }
    }

    public static function render_metabox(WP_Post $post): void {
        if (!current_user_can('edit_post', $post->ID)) return;

        wp_nonce_field('krv_max_metabox','krv_max_metabox_nonce');

        $disable = (int)get_post_meta($post->ID,self::META_DISABLE,true);
        $override = (string)get_post_meta($post->ID,self::META_OVERRIDE,true);
        $status = (string)get_post_meta($post->ID, self::META_STATUS, true);
        $err = (string)get_post_meta($post->ID, self::META_ERROR, true);
        $targets = get_post_meta($post->ID, self::META_TARGET_RESULTS, true);
        $targets_summary = '';
        if (is_array($targets) && !empty($targets)) {
            $bits = [];
            foreach ($targets as $row) {
                if (!is_array($row)) continue;
                $bits[] = (string)($row['chat_id'] ?? '?') . ':' . (string)($row['status'] ?? '?');
            }
            $targets_summary = implode('; ', $bits);
        }

        $status_labels = [
            'queued' => 'В очереди',
            'sent' => 'Отправлено',
            'error' => 'Ошибка',
            'partial_success' => 'Частично',
        ];
        $status_label = $status_labels[$status] ?? ($status !== '' ? $status : '—');

        echo '<p><strong>Статус MAX:</strong> '.esc_html($status_label).'</p>';
        if ($targets_summary !== '') {
            echo '<p class="description" style="margin-top:0;" title="'.esc_attr($targets_summary).'">Цели: '.esc_html(mb_strlen($targets_summary) > 120 ? mb_substr($targets_summary, 0, 120).'…' : $targets_summary).'</p>';
        }
        if ($err !== '') {
            echo '<p class="description" style="color:#b32d2e;margin-top:0;" title="'.esc_attr($err).'">'.esc_html(mb_strlen($err) > 160 ? mb_substr($err, 0, 160).'…' : $err).'</p>';
        }

        echo '<p><label><input type="checkbox" name="krv_max_disable" value="1" '.checked($disable,1,false).'> Не отправлять в MAX</label></p>';
        echo '<p><strong>Свой текст (override)</strong>:</p>';
        echo '<textarea name="krv_max_override" class="widefat" style="min-height:80px;">'.esc_textarea($override).'</textarea>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:10px;">';
        wp_nonce_field('krv_max_send_now_'.(int)$post->ID);
        echo '<input type="hidden" name="action" value="krv_max_send_now">';
        echo '<input type="hidden" name="post_id" value="'.esc_attr((string)(int)$post->ID).'">';
        echo '<button type="submit" class="button button-secondary">Отправить сейчас</button>';
        echo '</form>';
    }

    public static function save_metabox(int $post_id, WP_Post $post): void {
        if (!self::is_supported_post_type($post->post_type)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        if (!isset($_POST['krv_max_metabox_nonce']) || !wp_verify_nonce((string)$_POST['krv_max_metabox_nonce'],'krv_max_metabox')) return;
        if (!current_user_can('edit_post',$post_id)) return;

        $disable = !empty($_POST['krv_max_disable']) ? 1 : 0;
        if ($disable) update_post_meta($post_id,self::META_DISABLE,1);
        else delete_post_meta($post_id,self::META_DISABLE);

        if (isset($_POST['krv_max_override'])) {
            $txt = sanitize_textarea_field((string)$_POST['krv_max_override']);
            if ($txt !== '') update_post_meta($post_id,self::META_OVERRIDE,$txt);
            else delete_post_meta($post_id,self::META_OVERRIDE);
        }
    }

    /* ================= PUBLISH → QUEUE ================= */

    public static function queue_on_publish(string $new_status, string $old_status, WP_Post $post): void {
        if (!self::is_supported_post_type($post->post_type)) return;
        if ($new_status !== 'publish') return;
        if ($old_status === 'publish') return;

        $post_id = (int)$post->ID;
        if ((int)get_post_meta($post_id,self::META_DISABLE,true) === 1) return;

        self::queue_post($post_id,'Auto queue on publish');
        self::trigger_queue_worker();
    }

    public static function queue_on_future_publish(WP_Post $post): void {
        self::queue_on_publish('publish', 'future', $post);
    }

    private static function queue_post(int $post_id, string $why=''): void {
        update_post_meta($post_id,self::META_STATUS,'queued');
        delete_post_meta($post_id,self::META_ERROR);
        delete_post_meta($post_id,self::META_TARGET_RESULTS);
        update_post_meta($post_id,self::META_ATTEMPTS,0);
        $now = time();
        update_post_meta($post_id,self::META_NEXTTRY,$now);
        update_post_meta($post_id,self::META_QUEUEDAT,$now);
        update_post_meta($post_id,self::META_QSTAMP,self::current_install_stamp());
        self::log('queue',0,$post_id,$why ?: 'queued');
    }

    private static function requeue_post_with_current_settings(int $post_id, string $why=''): void {
        delete_post_meta($post_id,self::META_SENTHASH);
        delete_post_meta($post_id,self::META_ERROR);
        delete_post_meta($post_id,self::META_TARGET_RESULTS);
        self::queue_post($post_id, $why);
    }

    private static function trigger_queue_worker(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }

        // Avoid piling up many single-events (was a source of burst sends after restart).
        $next_single = wp_next_scheduled(self::CRON_HOOK);
        $crons = _get_cron_array();
        $has_near_single = false;
        if (is_array($crons)) {
            $now = time();
            foreach ($crons as $ts => $hooks) {
                if (!is_array($hooks) || !isset($hooks[self::CRON_HOOK])) {
                    continue;
                }
                if ((int)$ts <= $now + 30) {
                    $has_near_single = true;
                    break;
                }
            }
        }
        if (!$has_near_single) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }
        self::spawn_cron();
    }

    private static function spawn_cron(): void {
        $url = site_url('wp-cron.php?doing_wp_cron=' . urlencode((string)microtime(true)));
        wp_remote_post($url, ['timeout'=>1,'blocking'=>false]);
    }

    /* ================= CRON ================= */

    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = ['interval'=>60,'display'=>'Every Minute (MAX Autopost)'];
        }
        return $schedules;
    }

    public static function process_queue(bool $force = false): void {
        if (!$force && !self::is_worker_enabled()) return;
        if (get_transient(self::CRON_LOCK_KEY)) return;
        set_transient(self::CRON_LOCK_KEY, 1, 55);

        $now = time();
        $cutoff = (int)get_option(self::CUTOFF_OPT, 0);
        $install_stamp = self::current_install_stamp();

        try {
            $q = new WP_Query([
                'post_type'=>self::supported_post_types(),
                'post_status'=>'publish',
                'posts_per_page'=>self::BATCH_LIMIT,
                'orderby'=>'meta_value_num',
                'meta_key'=>self::META_NEXTTRY,
                'order'=>'ASC',
                'no_found_rows'=>true,
                'update_post_meta_cache'=>false,
                'update_post_term_cache'=>false,
                'meta_query'=>[
                    ['key'=>self::META_STATUS,'value'=>'queued'],
                    [
                        'relation'=>'OR',
                        ['key'=>self::META_NEXTTRY,'compare'=>'NOT EXISTS'],
                        ['key'=>self::META_NEXTTRY,'value'=>$now,'type'=>'NUMERIC','compare'=>'<='],
                    ],
                    ['key'=>self::META_QUEUEDAT,'value'=>$cutoff,'type'=>'NUMERIC','compare'=>'>='],
                    ['key'=>self::META_QSTAMP,'value'=>$install_stamp],
                ],
            ]);

            foreach ($q->posts as $p) {
                $post_id = (int)$p->ID;
                $res = self::send($post_id);
                update_post_meta($post_id, self::META_TARGET_RESULTS, $res['results']);

                if ($res['status'] === 'success') {
                    update_post_meta($post_id,self::META_STATUS,'sent');
                    delete_post_meta($post_id,self::META_ERROR);
                    delete_post_meta($post_id,self::META_ATTEMPTS);
                    delete_post_meta($post_id,self::META_NEXTTRY);
                    delete_post_meta($post_id,self::META_QUEUEDAT);
                    delete_post_meta($post_id,self::META_QSTAMP);
                    continue;
                }

                if ($res['status'] === 'partial_success') {
                    update_post_meta($post_id,self::META_STATUS,'partial_success');
                    update_post_meta($post_id,self::META_ERROR,(string)$res['message']);
                    delete_post_meta($post_id,self::META_ATTEMPTS);
                    delete_post_meta($post_id,self::META_NEXTTRY);
                    delete_post_meta($post_id,self::META_QUEUEDAT);
                    delete_post_meta($post_id,self::META_QSTAMP);
                    continue;
                }

                $attempts = (int)get_post_meta($post_id,self::META_ATTEMPTS,true);
                $attempts++;
                update_post_meta($post_id,self::META_ATTEMPTS,$attempts);
                update_post_meta($post_id,self::META_ERROR,(string)$res['message']);

                $delay = self::retry_delay($attempts);
                if ($delay === null) {
                    update_post_meta($post_id,self::META_STATUS,'error');
                    update_post_meta($post_id,self::META_NEXTTRY,0);
                } else {
                    update_post_meta($post_id,self::META_STATUS,'queued');
                    update_post_meta($post_id,self::META_NEXTTRY,$now + $delay);
                }
            }
        } finally {
            delete_transient(self::CRON_LOCK_KEY);
        }
    }

    private static function retry_delay(int $attempt): ?int {
        $i = $attempt - 1;
        return array_key_exists($i, self::$backoff) ? (int)self::$backoff[$i] : null;
    }

    /* ================= ADMIN ACTIONS ================= */

public static function handle_send_test(): void {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('krv_max_send_test');

    $s = self::get_settings();
    $token = self::token($s);
    $targets = self::target_chat_ids($s);

    if ($token === '' || empty($targets)) {
        self::notice('error','Не задан token или chat_id.');
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=settings'));
        exit;
    }

    $test_content = self::build_test_content($s);
    $payload = [
        'text'   => (string)$test_content['text'],
        'notify' => (bool)$s['notify'],
    ];

    if (!empty($test_content['parse_mode'])) {
        $payload['parse_mode'] = (string)$test_content['parse_mode'];
    }
    if (!empty($test_content['format'])) {
        $payload['format'] = (string)$test_content['format'];
    }
    if (!empty($s['debug'])) {
        self::log('test_payload', 0, 0, 'mode='.(string)$test_content['mode'].'; format='.(string)($payload['format'] ?? '').'; parse_mode='.(string)($payload['parse_mode'] ?? '').'; text='.self::short((string)$payload['text']));
    }

    $attachments = [];
    $test_has_image = false;
    $test_send_mode = 'test_text_only';

    if (!empty($s['include_image']) && function_exists('curl_init')) {
        $mode = (string)($s['image_source_mode'] ?? 'post_or_site');
        if (self::normalize_image_source_mode($mode) !== 'post_only') {
            $site_icon = (int)get_option('site_icon');
            if ($site_icon) {
                $file = get_attached_file($site_icon);
                if (is_string($file) && $file && file_exists($file)) {
                    $up = self::upload($file, $token, 0);

                    if ($up !== false && self::has_media_payload_markers($up)) {
                        $attachments[] = [
                            'type'    => 'image',
                            'payload' => $up,
                        ];
                        $test_has_image = true;
                    } else {
                        self::log('test_image_skip', 0, 0, 'Upload вернул невалидный media payload, тест отправлен как text-only');
                    }
                } else {
                    self::log('test_image_skip', 0, 0, 'Файл site_icon не найден, тест отправлен как text-only');
                }
            } else {
                self::log('test_image_skip', 0, 0, 'site_icon не задан, тест отправлен как text-only');
            }
        } else {
            self::log('test_image_skip', 0, 0, 'Режим post_only для теста без поста, тест отправлен как text-only');
        }
    }

    $buttons = self::build_inline_keyboard_buttons($s, home_url('/'));
    if (!empty($buttons)) {
        $attachments[] = [
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => [$buttons],
            ],
        ];
    }

    if (!empty($attachments)) {
        $payload['attachments'] = $attachments;
        $test_send_mode = $test_has_image ? 'test_with_image' : 'test_with_keyboard';
    }
    if (!empty($s['debug'])) {
        self::log('test_attachments', 0, 0, 'attachments_count='.(int)count($attachments).'; button_count='.(int)count($buttons).'; has_image='.(int)$test_has_image);
    }
    self::log('test_send_mode', 0, 0, $test_send_mode);

    $dispatch = self::dispatch_to_targets(
        $payload,
        $targets,
        $token,
        0,
        (bool)$s['debug'],
        (string)$test_content['mode'],
        (string)$test_content['plain_fallback']
    );

    $worker_msg = '';
    if (($dispatch['status'] === 'success' || $dispatch['status'] === 'partial_success')
        && !empty($s['enable_worker_after_test'])) {
        update_option(self::WORKER_ENABLED_OPT, 1, false);
        $worker_msg = ' Автоворкер включён (как выбрано в настройках).';
    }

    $notice_type = $dispatch['status'] === 'error'
        ? 'error'
        : ($dispatch['status'] === 'partial_success' ? 'warning' : 'success');

    self::notice($notice_type, 'Тест: '.$dispatch['message'].$worker_msg);

    wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=settings'));
    exit;
}

    public static function handle_clear_logs(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_clear_logs');
        delete_option(self::LOG_OPT);
        self::notice('success', 'Логи очищены.');
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=logs'));
        exit;
    }

    public static function handle_dismiss_upgrade_notice(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_dismiss_upgrade_notice');
        delete_option(self::UPGRADE_NOTICE_OPT);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=krv-max-autopost'));
        exit;
    }
    public static function handle_run_queue(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_run_queue');
        self::process_queue(true);
        self::notice('success','Очередь запущена вручную (1 элемент).');
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=queue'));
        exit;
    }

    public static function handle_worker_enable(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_worker_enable');
        update_option(self::WORKER_ENABLED_OPT, 1, false);
        self::notice('success','Автоворкер включен.');
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=queue'));
        exit;
    }

    public static function handle_worker_disable(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_worker_disable');
        update_option(self::WORKER_ENABLED_OPT, 0, false);
        self::notice('success','Автоворкер выключен.');
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=queue'));
        exit;
    }

    public static function handle_requeue_errors(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_requeue_errors');

        $posts = get_posts([
            'post_type'=>self::supported_post_types(),
            'post_status'=>'any',
            'numberposts'=>self::REQUEUE_BATCH,
            'fields'=>'ids',
            'meta_key'=>self::META_STATUS,
            'meta_value'=>'error',
            'orderby'=>'ID',
            'order'=>'DESC',
        ]);

        foreach ($posts as $id) {
            self::queue_post((int)$id,'Requeue error batch');
        }

        $n = count($posts);
        if ($n > 0) {
            self::trigger_queue_worker();
        }
        self::notice('success','В очередь из ошибок: '.$n.' (пачка до '.self::REQUEUE_BATCH.'). Повторите для следующей пачки.');
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=queue'));
        exit;
    }

    public static function handle_send_now(): void {
        if (!current_user_can('edit_posts')) wp_die('Forbidden');

        // Prefer POST; allow GET only with valid nonce (row actions / bookmarks).
        $post_id = 0;
        if (isset($_POST['post_id'])) {
            $post_id = (int)$_POST['post_id'];
        } elseif (isset($_GET['post_id'])) {
            $post_id = (int)$_GET['post_id'];
        }
        if (!$post_id) wp_die('Bad request');
        $post = get_post($post_id);
        if (!$post) wp_die('Bad request');
        if (!current_user_can('edit_post', $post_id)) wp_die('Forbidden');
        check_admin_referer('krv_max_send_now_'.$post_id);

        $res = self::send($post_id);
        update_post_meta($post_id, self::META_TARGET_RESULTS, $res['results']);

        if ($res['status'] === 'success') {
            update_post_meta($post_id,self::META_STATUS,'sent');
            delete_post_meta($post_id,self::META_ERROR);
            delete_post_meta($post_id,self::META_ATTEMPTS);
            delete_post_meta($post_id,self::META_NEXTTRY);
            delete_post_meta($post_id,self::META_QUEUEDAT);
            delete_post_meta($post_id,self::META_QSTAMP);
            self::notice('success','Отправлено в MAX.');
        } elseif ($res['status'] === 'partial_success') {
            update_post_meta($post_id,self::META_STATUS,'partial_success');
            update_post_meta($post_id,self::META_ERROR,(string)$res['message']);
            delete_post_meta($post_id,self::META_ATTEMPTS);
            delete_post_meta($post_id,self::META_NEXTTRY);
            delete_post_meta($post_id,self::META_QUEUEDAT);
            delete_post_meta($post_id,self::META_QSTAMP);
            self::notice('warning','Частично отправлено: '.self::short((string)$res['message']));
        } else {
            update_post_meta($post_id,self::META_STATUS,'error');
            update_post_meta($post_id,self::META_ERROR,(string)$res['message']);
            self::notice('error','Ошибка: '.self::short((string)$res['message']));
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php'));
        exit;
    }


    public static function handle_queue_now(): void {
        if (!current_user_can('edit_posts')) wp_die('Forbidden');

        $post_id = 0;
        if (isset($_POST['post_id'])) {
            $post_id = (int)$_POST['post_id'];
        } elseif (isset($_GET['post_id'])) {
            $post_id = (int)$_GET['post_id'];
        }
        if (!$post_id) wp_die('Bad request');
        $post = get_post($post_id);
        if (!$post) wp_die('Bad request');
        if (!current_user_can('edit_post', $post_id)) wp_die('Forbidden');
        check_admin_referer('krv_max_queue_now_'.$post_id);
        if (!self::is_supported_post_type($post->post_type)) wp_die('Bad request');

        self::queue_post($post_id,'Manual queue');
        self::trigger_queue_worker();
        self::notice('success','Материал поставлен в очередь MAX.');

        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php'));
        exit;
    }

    public static function handle_queue_all_published(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_queue_all_published');

        $posts = get_posts([
            'post_type'=>self::supported_post_types(),
            'post_status'=>'publish',
            'numberposts'=>self::REQUEUE_BATCH,
            'fields'=>'ids',
            'orderby'=>'ID',
            'order'=>'DESC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::META_STATUS, 'compare' => 'NOT EXISTS'],
                ['key' => self::META_STATUS, 'value' => 'queued', 'compare' => '!='],
            ],
        ]);

        foreach ($posts as $id) {
            self::queue_post((int)$id,'Bulk queue published batch');
        }

        $n = count($posts);
        $targets_n = count(self::target_chat_ids(self::get_settings()));
        if ($n > 0) {
            self::trigger_queue_worker();
        }
        self::notice(
            'success',
            'В очередь добавлено: '.$n.' (пачка до '.self::REQUEUE_BATCH.'). Целей: '.$targets_n.
            '. Ориентир до '.($n * max(1, $targets_n)).' сообщений. Повторите кнопку для следующей пачки.'
        );
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=queue'));
        exit;
    }

    public static function handle_requeue_published_current_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_requeue_published_current_settings');

        $posts = get_posts([
            'post_type'=>self::supported_post_types(),
            'post_status'=>'publish',
            'numberposts'=>self::REQUEUE_BATCH,
            'fields'=>'ids',
            'orderby'=>'ID',
            'order'=>'DESC',
        ]);

        $requeued = 0;
        $skipped_disabled = 0;

        foreach ($posts as $id) {
            $post_id = (int)$id;
            if ((int)get_post_meta($post_id,self::META_DISABLE,true) === 1) {
                $skipped_disabled++;
                continue;
            }

            self::requeue_post_with_current_settings($post_id,'Requeue published batch with current settings');
            $requeued++;
        }

        if ($requeued > 0) {
            self::trigger_queue_worker();
        }

        $targets_n = count(self::target_chat_ids(self::get_settings()));
        $message = 'Переочередь (пачка): '.$requeued.' постов, целей: '.$targets_n.
            ', ориентир до '.($requeued * max(1, $targets_n)).' сообщений. Повторите для следующей пачки.';
        if ($skipped_disabled > 0) {
            $message .= ' Пропущено «Не отправлять»: '.$skipped_disabled.'.';
        }

        self::notice('success', $message);
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=queue'));
        exit;
    }

    /* ================= ROW / BULK ================= */

    public static function row_action(array $actions, WP_Post $post): array {
        if (!self::is_supported_post_type($post->post_type)) return $actions;
        if (!current_user_can('edit_post', $post->ID)) return $actions;

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=krv_max_send_now&post_id='.(int)$post->ID),
            'krv_max_send_now_'.(int)$post->ID
        );
        $queue_url = wp_nonce_url(
            admin_url('admin-post.php?action=krv_max_queue_now&post_id='.(int)$post->ID),
            'krv_max_queue_now_'.(int)$post->ID
        );
        $actions['krv_max_send'] = '<a href="'.esc_url($url).'">Отправить в MAX</a>';
        $actions['krv_max_queue'] = '<a href="'.esc_url($queue_url).'">В очередь MAX</a>';
        return $actions;
    }

    public static function bulk_action(array $actions): array {
        $actions['krv_max_bulk'] = 'Поставить в очередь MAX';
        return $actions;
    }

    public static function handle_bulk(string $redirect_to, string $doaction, array $post_ids): string {
        if ($doaction !== 'krv_max_bulk') return $redirect_to;

        $queued = 0;

        foreach ($post_ids as $id) {
            $post = get_post((int)$id);
            if (!$post || !self::is_supported_post_type($post->post_type) || !current_user_can('edit_post', (int)$id)) {
                continue;
            }
            self::queue_post((int)$id,'Bulk queue');
            $queued++;
        }

        if ($queued > 0) {
            self::trigger_queue_worker();
            self::notice('success','Посты добавлены в очередь.');
        } else {
            self::notice('warning','Нет доступных постов для постановки в очередь.');
        }
        return $redirect_to;
    }

    /* ================= CORE: send / upload / api ================= */

    private static function send(int $post_id): array {
        $s = self::get_settings();
        $token = self::token($s);
        $targets = self::target_chat_ids($s);

        if ($token === '' || empty($targets)) return ['status'=>'error', 'message'=>'Не задан token/chat_id', 'results'=>[]];

        $post = get_post($post_id);
        if (!$post) return ['status'=>'error', 'message'=>'Пост не найден', 'results'=>[]];
        if (!self::is_supported_post_type($post->post_type)) return ['status'=>'error', 'message'=>'Тип записи не поддерживается', 'results'=>[]];
        if ($post->post_status !== 'publish') return ['status'=>'error', 'message'=>'Можно отправлять только опубликованные материалы', 'results'=>[]];

        if ((int)get_post_meta($post_id,self::META_DISABLE,true) === 1) return ['status'=>'error', 'message'=>'Отключено в метабоксе.', 'results'=>[]];

        $message = self::build_message_content($post_id, $s);
        $text = (string)$message['text'];
        $url  = get_permalink($post_id);
        $append = self::get_append_text_variants($s);
        if (!empty($s['debug'])) {
            self::log('send_mode', 0, $post_id, 'mode='.(string)$message['mode'].'; format='.(string)($message['format'] ?? '').'; append='.(int)($append['raw'] !== ''));
            self::log('send_payload', 0, $post_id, 'parse_mode='.(string)($message['parse_mode'] ?? '').'; format='.(string)($message['format'] ?? '').'; text='.self::short($text));
        }

        $payload = ['text'=>$text,'notify'=>(bool)$s['notify']];
        if (!empty($message['parse_mode'])) {
            $payload['parse_mode'] = (string)$message['parse_mode'];
        }
        if (!empty($message['format'])) {
            $payload['format'] = (string)$message['format'];
        }
        $attachments = [];

        // IMAGE first
        if (!empty($s['include_image']) && function_exists('curl_init')) {
            $file = self::resolve_image_file($post_id, $s);
            if ($file) {
                $up = self::upload($file, $token, $post_id);
                if ($up === false) return ['status'=>'error', 'message'=>'Upload failed (see logs)', 'results'=>[]];
                $attachments[] = ['type'=>'image','payload'=>$up]; // IMPORTANT: full JSON
            }
        }

        // BUTTON second
        $buttons = self::build_inline_keyboard_buttons($s, (string)$url);
        if (!empty($buttons)) {
            $attachments[] = [
                'type'=>'inline_keyboard',
                'payload'=>[
                    'buttons'=>[$buttons],
                ],
            ];
        }

        if (!empty($attachments)) $payload['attachments'] = $attachments;
        if (!empty($s['debug'])) {
            self::log('send_attachments', 0, $post_id, 'attachments_count='.(int)count($attachments).'; has_image='.(int)(isset($attachments[0]) && ($attachments[0]['type'] ?? '') === 'image').'; button_count='.(int)count($buttons));
        }

        // Dedupe (stable hash w/o upload payload)
        $sig = [
            'text'=>$payload['text'],
            'notify'=>$payload['notify'],
            'has_image'=>(int)(isset($attachments[0]) && $attachments[0]['type']==='image'),
            'has_button'=>(int)!empty($s['add_button']),
            'button_text'=>(string)$s['button_text'],
            'url'=>$url,
            'has_subscribe_button'=>(int)!empty($s['add_subscribe_button']),
            'subscribe_button_text'=>(string)($s['subscribe_button_text'] ?? ''),
            'subscribe_button_url'=>(string)($s['subscribe_button_url'] ?? ''),
            'targets'=>$targets,
            'post_modified_gmt'=>get_post_modified_time('U', true, $post_id),
        ];
        $hash = hash('sha256', wp_json_encode($sig, JSON_UNESCAPED_UNICODE));
        $prev = (string)get_post_meta($post_id,self::META_SENTHASH,true);
        if ($prev && hash_equals($prev,$hash)) {
            $prev_results = get_post_meta($post_id, self::META_TARGET_RESULTS, true);
            $prev_results = is_array($prev_results) ? $prev_results : [];
            return ['status'=>'success', 'message'=>'Уже отправлено ранее (dedupe).', 'results'=>$prev_results];
        }

        $dispatch = self::dispatch_to_targets(
            $payload,
            $targets,
            $token,
            $post_id,
            (bool)$s['debug'],
            (string)$message['mode'],
            (string)$message['plain_fallback']
        );
        if ($dispatch['status'] === 'success' || $dispatch['status'] === 'partial_success') {
            update_post_meta($post_id,self::META_SENTHASH,$hash);
        }
        return $dispatch;
    }

    /**
     * Upload flow:
     * 1) POST /uploads?type=image -> {url,type}
     * 2) POST upload_url multipart (data=@file) -> JSON (may be {token,url,type} OR {"photos":{...}} etc.)
     * IMPORTANT: For MAX we must pass FULL JSON response from step2 into image.payload.
     */
    private static function upload(string $file, string $token, int $post_id) {
        if (!file_exists($file)) {
            self::log('upload_step0', 0, $post_id, 'File not found: '.$file);
            return false;
        }

        // step1
        $r1 = wp_remote_post(self::api_url('/uploads?type=image'), [
            'headers'=>[
                'Authorization'=>$token,
                'Content-Type'=>'application/json',
            ],
            'body'=>'{}',
            'timeout'=>20,
        ]);

        if (is_wp_error($r1)) {
            self::log('upload_step1', 0, $post_id, self::sanitize_upload_log_text($r1->get_error_message()));
            return false;
        }

        $code1 = (int)wp_remote_retrieve_response_code($r1);
        $body1 = (string)wp_remote_retrieve_body($r1);

        if ($code1 < 200 || $code1 >= 300) {
            self::log('upload_step1', $code1, $post_id, 'HTTP '.$code1.': '.self::sanitize_upload_log_text($body1));
            return false;
        }

        $j1 = json_decode($body1, true);
        if (!is_array($j1) || empty($j1['url'])) {
            self::log('upload_step1', $code1, $post_id, 'Bad JSON: '.self::sanitize_upload_log_text($body1));
            return false;
        }

        // step2
        $upload_url = (string)$j1['url'];
        if (!self::is_allowed_upload_url($upload_url)) {
            self::log('upload_step2', 0, $post_id, 'Rejected upload_url (SSRF guard): '.self::sanitize_upload_log_text($upload_url));
            return false;
        }

        $ch = curl_init($upload_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['data'=>new CURLFile($file)]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        if (defined('CURLPROTO_HTTP')) {
            // HTTPS only — do not allow http redirect targets.
        }

        $out = curl_exec($ch);
        $code2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            self::log('upload_step2', $code2 ?: 0, $post_id, 'cURL error: '.self::sanitize_upload_log_text($cerr));
            return false;
        }

        if ($code2 < 200 || $code2 >= 300) {
            self::log('upload_step2', $code2, $post_id, 'HTTP '.$code2.': '.self::sanitize_upload_log_text((string)$out));
            return false;
        }

        $j2 = json_decode((string)$out, true);

        // ✅ FIX (your log case): MAX may return nested objects, e.g. {"photos":{...:{token:"..."}}}
        // We must accept any valid JSON object/array and pass it as-is into image.payload.
        if (!is_array($j2) || empty($j2)) {
            self::log('upload_step2', $code2, $post_id, 'Bad JSON: '.self::sanitize_upload_log_text((string)$out));
            return false;
        }

        return $j2;
    }


    private static function discover_chats(string $token): array {
        $endpoints = [
            self::api_url('/chats?limit=50'),
            self::api_url('/chats'),
        ];

        $last_error = '';

        foreach ($endpoints as $url) {
            $r = self::max_get_json($url, $token);
            if (!empty($r['error'])) {
                $last_error = (string)$r['error'];
                continue;
            }

            $items = self::normalize_chats_payload($r['json'] ?? null);
            return ['items' => $items, 'error' => ''];
        }

        return ['items'=>[], 'error'=> $last_error !== '' ? $last_error : 'Неизвестная ошибка'];
    }

    private static function max_get_json(string $url, string $token): array {
        $r = wp_remote_get($url, [
            'headers'=>[
                'Authorization'=>$token,
                'Accept'=>'application/json',
            ],
            'timeout'=>15,
        ]);

        if (is_wp_error($r)) {
            return ['error'=>$r->get_error_message(), 'json'=>null];
        }

        $code = (int)wp_remote_retrieve_response_code($r);
        $body = (string)wp_remote_retrieve_body($r);
        if ($code < 200 || $code >= 300) {
            return ['error'=>'HTTP '.$code.': '.self::short($body), 'json'=>null];
        }

        $j = json_decode($body, true);
        if (!is_array($j)) {
            return ['error'=>'Bad JSON: '.self::short($body), 'json'=>null];
        }

        return ['error'=>'', 'json'=>$j];
    }

    private static function is_list_array(array $arr): bool {
        $i = 0;
        foreach (array_keys($arr) as $k) {
            if ($k !== $i++) return false;
        }
        return true;
    }

    private static function normalize_chats_payload($json): array {
        if (!is_array($json)) return [];

        $list = [];
        if (isset($json['chats']) && is_array($json['chats'])) {
            $list = $json['chats'];
        } elseif (isset($json['items']) && is_array($json['items'])) {
            $list = $json['items'];
        } elseif (self::is_list_array($json)) {
            $list = $json;
        }

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) continue;

            $id = '';
            foreach (['chat_id','id','chatId'] as $k) {
                if (isset($row[$k]) && (string)$row[$k] !== '') {
                    $id = (string)$row[$k];
                    break;
                }
            }
            if ($id === '') continue;

            $title = '';
            foreach (['title','name','chat_title'] as $k) {
                if (isset($row[$k]) && (string)$row[$k] !== '') {
                    $title = (string)$row[$k];
                    break;
                }
            }

            $type = '';
            foreach (['type','chat_type'] as $k) {
                if (isset($row[$k]) && (string)$row[$k] !== '') {
                    $type = (string)$row[$k];
                    break;
                }
            }

            $out[] = ['id'=>$id,'title'=>$title,'type'=>$type];
        }

        return $out;
    }

    /**
     * Base URL for MAX platform API. Filter: krv_max_api_host
     */
    private static function api_base(): string {
        $base = (string)apply_filters('krv_max_api_host', self::API_HOST);
        $base = rtrim(trim($base), '/');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            $base = self::API_HOST;
        }
        return $base;
    }

    private static function api_url(string $path): string {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        return self::api_base() . $path;
    }

    /**
     * SSRF guard for MAX upload step2 URL returned by the API.
     */
    private static function is_allowed_upload_url(string $url): bool {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return false;
        }

        $host = strtolower((string)(wp_parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }

        $allowed_suffixes = [
            'max.ru',
            'oneme.ru',
            'okcdn.ru',
            'mycdn.me',
            'vkuserphoto.ru',
            'userapi.com',
        ];

        $ok = false;
        foreach ($allowed_suffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                $ok = true;
                break;
            }
        }

        /**
         * Filter allowed upload hosts check. Return true to allow.
         *
         * @param bool   $ok
         * @param string $host
         * @param string $url
         */
        return (bool)apply_filters('krv_max_allow_upload_url', $ok, $host, $url);
    }

    private static function api(array $payload, string $chat_id, string $token, int $post_id, bool $debug): array {
        $url = self::api_url('/messages?chat_id=' . rawurlencode($chat_id));

        $r = wp_remote_post($url, [
            'headers'=>[
                'Authorization'=>$token,
                'Content-Type'=>'application/json',
            ],
            'body'=>wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout'=>20,
        ]);

        if (is_wp_error($r)) {
            $msg = $r->get_error_message();
            self::log('send', 0, $post_id, '[chat_id='.self::mask_chat_id_for_log($chat_id).'] WP_Error: '.$msg);
            return ['ok'=>false, 'message'=>$msg, 'message_id'=>'', 'http'=>0];
        }

        $code = (int)wp_remote_retrieve_response_code($r);
        $body = (string)wp_remote_retrieve_body($r);

        if ($code >= 200 && $code < 300) {
            if ($debug && $body !== '') self::log('send', $code, $post_id, '[chat_id='.self::mask_chat_id_for_log($chat_id).'] Response: '.self::short($body));
            $decoded = json_decode($body, true);
            $message_id = '';
            if (is_array($decoded)) {
                foreach (['message_id', 'id', 'msg_id'] as $key) {
                    if (!empty($decoded[$key])) {
                        $message_id = (string)$decoded[$key];
                        break;
                    }
                }
            }
            return ['ok'=>true, 'message'=>'success', 'message_id'=>$message_id, 'http'=>$code];
        }

        $error = 'HTTP '.$code.': '.self::short($body);
        self::log('send', $code, $post_id, '[chat_id='.self::mask_chat_id_for_log($chat_id).'] '.$error);
        return ['ok'=>false, 'message'=>$error, 'message_id'=>'', 'http'=>$code];
    }

    private static function send_to_target_with_fallback(array $payload, string $chat_id, string $token, int $post_id, bool $debug, string $format_mode, string $plain_fallback): array {
    $primary = self::api($payload, $chat_id, $token, $post_id, $debug);
    $primary['fallback_used'] = false;
    $primary['parse_mode'] = (string)($payload['parse_mode'] ?? '');
    $primary['format'] = (string)($payload['format'] ?? '');

    // Fallback for full formatted mode and for light HTML (e.g. bold title in plain/excerpt).
    $used_html = ($format_mode === 'formatted')
        || ((string)($payload['format'] ?? '') !== '')
        || ((string)($payload['parse_mode'] ?? '') !== '');

    if (!empty($primary['ok']) || !$used_html) {
        return $primary;
    }

    $fallback_payload = [
        'text'   => $plain_fallback !== '' ? $plain_fallback : self::limit_text(self::clean_publish_text((string)($payload['text'] ?? '')), self::get_settings()),
        'notify' => (bool)($payload['notify'] ?? true),
    ];

    self::log(
        'fallback',
        (int)($primary['http'] ?? 0),
        $post_id,
        '[chat_id='.self::mask_chat_id_for_log($chat_id).'] html/formatted failed, fallback to plain text without attachments: '.self::short((string)($primary['message'] ?? 'unknown error'))
    );

    $retry = self::api($fallback_payload, $chat_id, $token, $post_id, $debug);
    $retry['fallback_used'] = true;
    $retry['parse_mode'] = '';
    $retry['format'] = '';
    return $retry;
}

    /* ================= HELPERS ================= */
    private static function build_inline_keyboard_buttons(array $settings, string $default_url = ''): array {
        $buttons = [];

        if (!empty($settings['add_button']) && $default_url !== '') {
            $buttons[] = [
                'type' => 'link',
                'text' => (string)$settings['button_text'],
                'url'  => $default_url,
            ];
        }

        if (!empty($settings['add_subscribe_button'])) {
            $sub_url = self::sanitize_http_url((string)($settings['subscribe_button_url'] ?? ''));
            if ($sub_url !== '') {
                $buttons[] = [
                    'type' => 'link',
                    'text' => (string)($settings['subscribe_button_text'] ?? 'Подписаться'),
                    'url'  => $sub_url,
                ];
            }
        }

        return $buttons;
    }

    private static function supported_post_types(): array {
        $s = self::get_settings();
        $types = isset($s['enabled_post_types']) && is_array($s['enabled_post_types']) ? $s['enabled_post_types'] : [];
        $types = array_values(array_unique(array_filter(array_map(static fn($k) => sanitize_key((string)$k), $types))));

        $allowed = array_keys(self::available_post_types());
        $types = array_values(array_intersect($types, $allowed));

        return !empty($types) ? $types : ['post'];
    }

    private static function is_supported_post_type(string $post_type): bool {
        return in_array($post_type, self::supported_post_types(), true);
    }
    private static function available_post_types(): array {
        $objs = get_post_types([
            'public' => true,
            'show_ui' => true,
        ], 'objects');

        $out = [];
        foreach ($objs as $obj) {
            $name = sanitize_key((string)$obj->name);
            if ($name === '' || in_array($name, ['attachment', 'revision', 'nav_menu_item'], true)) continue;
            $label = is_string($obj->labels->singular_name ?? null) && $obj->labels->singular_name !== ''
                ? (string)$obj->labels->singular_name
                : (string)$obj->label;
            $out[$name] = $label !== '' ? $label : $name;
        }

        if (!isset($out['post'])) $out['post'] = 'Записи';
        return $out;
    }

    private static function normalize_image_source_mode(string $mode): string {
        return in_array($mode, ['post_or_site', 'post_only', 'site_only'], true) ? $mode : 'post_or_site';
    }

    private static function normalize_text_limit(int $limit): int {
        if ($limit < self::MIN_TEXT) return self::MIN_TEXT;
        if ($limit > self::MAX_TEXT) return self::MAX_TEXT;
        return $limit;
    }

    private static function normalize_message_format(string $mode): string {
        return in_array($mode, ['plain_text', 'formatted', 'excerpt_plain', 'title_only'], true) ? $mode : 'plain_text';
    }

    private static function is_bold_title_enabled(array $settings): bool {
        return !empty($settings['bold_title']);
    }

    private static function is_append_in_limit_enabled(array $settings): bool {
        return !empty($settings['append_in_limit']);
    }

    /**
     * Convert plain text to MAX-safe HTML line breaks.
     * MAX HTML mode often ignores raw \n / \n\n — use explicit <br> / <br><br>.
     */
    private static function plain_text_to_max_html(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Escape first so user content cannot inject tags.
        $text = esc_html($text);
        // Paragraph breaks first, then single line breaks.
        $text = preg_replace("/\n{2,}/u", '<br><br>', $text);
        $text = str_replace("\n", '<br>', (string)$text);
        return $text;
    }

    /**
     * MAX HTML often ignores <p>; convert block paragraphs to <br><br> for readable spacing.
     */
    private static function max_html_normalize_blocks(string $html): string {
        $html = preg_replace('/<\s*br\s*\/?\s*>/i', '<br>', $html);
        // </p><p> → visual paragraph
        $html = preg_replace('/<\/\s*p\s*>\s*<\s*p(?:\s[^>]*)?>/i', '<br><br>', (string)$html);
        // Remaining open/close p
        $html = preg_replace('/<\s*\/?\s*p(?:\s[^>]*)?>/i', '', (string)$html);
        // Collapse accidental 3+ breaks
        $html = preg_replace('/(?:<br>){3,}/i', '<br><br>', (string)$html);
        return trim((string)$html);
    }

    /**
     * Build MAX HTML with bold title and guaranteed visual gap before body.
     * Always: <strong>Title</strong><br><br>BodyWithBr
     */
    private static function compose_bold_title_html(string $title, string $body_plain): string {
        $title = self::clean_publish_text($title);
        $body_plain = ltrim(str_replace(["\r\n", "\r"], "\n", $body_plain), "\n");

        $html = '<strong>' . esc_html($title) . '</strong>';
        if ($body_plain !== '') {
            // Strict visual separator for MAX HTML mode.
            $html .= '<br><br>' . self::plain_text_to_max_html($body_plain);
        }
        return $html;
    }

    /**
     * When bold_title is on, convert a finished plain message into MAX HTML with:
     * - <strong>title</strong>
     * - <br><br> before the rest
     * - body newlines as <br>/<br><br>
     *
     * Returns null when bold is off, title empty, or plain text is an override
     * that does not start with the post title (keep as plain).
     */
    private static function maybe_bold_title_html(string $plain_text, int $post_id, array $settings): ?string {
        if (!self::is_bold_title_enabled($settings)) {
            return null;
        }

        $title = self::clean_publish_text((string)get_the_title($post_id));
        if ($title === '') {
            return null;
        }

        $plain_text = str_replace(["\r\n", "\r"], "\n", (string)$plain_text);
        $plain_text = self::clean_publish_text($plain_text);

        // Title-only (or title + nothing after limits).
        if ($plain_text === $title) {
            return self::compose_bold_title_html($title, '');
        }

        $title_len = mb_strlen($title, 'UTF-8');

        // Preferred compose from build_*: "Title\n\nBody..."
        if (mb_substr($plain_text, 0, $title_len, 'UTF-8') === $title) {
            $rest = mb_substr($plain_text, $title_len, null, 'UTF-8');
            // Drop any leading blank lines after the title so we control spacing via <br><br> only.
            $rest = preg_replace('/^\n+/u', '', (string)$rest);
            $rest = (string)$rest;
            return self::compose_bold_title_html($title, $rest);
        }

        // Override / custom text that does not begin with the post title — do not force bold HTML.
        return null;
    }

    private static function sanitize_chat_id(string $chat_id): string {
        return trim(sanitize_text_field($chat_id));
    }

    private static function mask_chat_id_for_log(string $chat_id): string {
        $chat_id = self::sanitize_chat_id($chat_id);
        $len = strlen($chat_id);

        if ($chat_id === '') {
            return '[empty]';
        }
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        if ($len <= 8) {
            return substr($chat_id, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($chat_id, -1);
        }

        return substr($chat_id, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($chat_id, -2);
    }

    private static function sanitize_upload_log_text(string $text): string {
        $text = preg_replace('/"((?:token|authorization|upload_url|download_url|url))"\s*:\s*"[^"]*"/i', '"$1":"[redacted]"', $text);
        $text = preg_replace("/'((?:token|authorization|upload_url|download_url|url))'\\s*:\\s*'[^']*'/i", "'$1':'[redacted]'", $text);
        $text = preg_replace('/\b((?:token|authorization|upload_url|download_url|url))\b\s*=\s*([^\s,&;]+)/i', '$1=[redacted]', $text);
        $text = preg_replace('/\b(Bearer)\s+[A-Za-z0-9\-_\.\=:\/\+]+/i', '$1 [redacted]', $text);
        $text = preg_replace('~https?://[^\s"\'<>]+~i', '[redacted_url]', $text);
        return (string)$text;
    }

    private static function normalize_chat_ids_text(string $raw): string {
        $rows = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $normalized = [];
        foreach ($rows as $row) {
            $id = self::sanitize_chat_id((string)$row);
            if ($id !== '') {
                $normalized[] = $id;
            }
        }
        $normalized = array_values(array_unique($normalized));
        return implode("\n", $normalized);
    }
private static function has_media_payload_markers($payload): bool {
    if (!is_array($payload) || empty($payload)) {
        return false;
    }

    if (!empty($payload['photos']) || !empty($payload['url']) || !empty($payload['token'])) {
        return true;
    }

    foreach ($payload as $value) {
        if (is_array($value) && self::has_media_payload_markers($value)) {
            return true;
        }
    }

    return false;
}
    private static function dispatch_to_targets(array $payload, array $targets, string $token, int $post_id, bool $debug, string $format_mode, string $plain_fallback): array {
        $results = [];
        $success = 0;

        foreach ($targets as $chat_id) {
            $chat_id = self::sanitize_chat_id((string)$chat_id);
            if ($chat_id === '') {
                continue;
            }

            $send = self::send_to_target_with_fallback($payload, $chat_id, $token, $post_id, $debug, $format_mode, $plain_fallback);
            $row = [
                'chat_id' => $chat_id,
                'status' => !empty($send['ok']) ? 'success' : 'error',
                'message_id' => (string)($send['message_id'] ?? ''),
                'error' => !empty($send['ok']) ? '' : (string)($send['message'] ?? 'Unknown error'),
            ];
            $results[] = $row;
            self::log_target_result($post_id, $row);
            if ($debug) {
                self::log('dispatch_debug', 0, $post_id, '[chat_id='.self::mask_chat_id_for_log($chat_id).'] format_mode='.$format_mode.'; parse_mode='.(string)($send['parse_mode'] ?? '').'; fallback='.(int)!empty($send['fallback_used']));
            }

            if ($row['status'] === 'success') {
                $success++;
            }
        }

        $total = count($results);
        if ($total === 0) {
            return ['status'=>'error', 'message'=>'Нет валидных Chat ID для отправки.', 'results'=>[]];
        }

        if ($success === 0) {
            return ['status'=>'error', 'message'=>'Не удалось отправить ни в один target (0/'.$total.').', 'results'=>$results];
        }

        if ($success < $total) {
            return ['status'=>'partial_success', 'message'=>'Отправлено частично: '.$success.'/'.$total.' target.', 'results'=>$results];
        }

        return ['status'=>'success', 'message'=>'Успешно отправлено во все target: '.$success.'/'.$total.'.', 'results'=>$results];
    }

    private static function log_target_result(int $post_id, array $result): void {
        $chat_id = (string)($result['chat_id'] ?? '');
        $status = (string)($result['status'] ?? '');
        $message_id = (string)($result['message_id'] ?? '');
        $error = (string)($result['error'] ?? '');

        $msg = '[chat_id='.self::mask_chat_id_for_log($chat_id).'] status='.$status;
        if ($message_id !== '') {
            $msg .= '; message_id='.$message_id;
        }
        if ($error !== '') {
            $msg .= '; error='.self::short($error);
        } else {
            $msg .= '; success';
        }
        self::log('dispatch', 0, $post_id, $msg);
    }

    private static function target_results_summary($results): string {
        if (!is_array($results) || empty($results)) {
            return '-';
        }

        $parts = [];
        foreach ($results as $row) {
            if (!is_array($row)) continue;
            $chat_id = self::sanitize_chat_id((string)($row['chat_id'] ?? ''));
            $status = sanitize_key((string)($row['status'] ?? ''));
            if ($chat_id === '' || $status === '') continue;
            $parts[] = $chat_id . ': ' . $status;
        }

        return !empty($parts) ? implode(' | ', $parts) : '-';
    }

    private static function clean_publish_text(string $text): string {
        $text = str_replace(["
", "
"], "
", $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace([" ", " ", " "], ' ', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text);
        $text = preg_replace('/[ 	]+/u', ' ', $text);
        $text = preg_replace('/ *
 */u', "
", $text);
        return trim((string)$text);
    }

    private static function build_message_content(int $post_id, array $settings): array {
        $mode = self::normalize_message_format((string)($settings['message_format'] ?? 'plain_text'));

        if ($mode === 'formatted') {
            $formatted = self::build_formatted_text($post_id, $settings);
            $plain = self::build_text($post_id, array_merge($settings, ['message_format' => 'plain_text']));
            return [
                'mode' => 'formatted',
                'text' => $formatted,
                'parse_mode' => 'HTML',
                'format' => 'html',
                'plain_fallback' => $plain,
            ];
        }

        if ($mode === 'excerpt_plain') {
            $excerpt = self::build_excerpt_plain_text($post_id, $settings);
            return self::wrap_plain_message_result('excerpt_plain', $excerpt, $post_id, $settings);
        }

        if ($mode === 'title_only') {
            $title_text = self::build_title_only_text($post_id, $settings);
            return self::wrap_plain_message_result('title_only', $title_text, $post_id, $settings);
        }

        $plain = self::build_text($post_id, $settings);
        return self::wrap_plain_message_result('plain_text', $plain, $post_id, $settings);
    }

    /**
     * Final envelope for plain-like modes (plain_text / excerpt_plain / title_only).
     * When bold_title is enabled, always send HTML (format=html) with <br><br> after the title.
     *
     * @return array{mode:string,text:string,parse_mode:string,format?:string,plain_fallback:string}
     */
    private static function wrap_plain_message_result(string $mode, string $plain, int $post_id, array $settings): array {
        // bold_title → MAX HTML so title stays bold and line breaks remain visible.
        $bold_html = self::maybe_bold_title_html($plain, $post_id, $settings);
        if ($bold_html !== null) {
            return [
                'mode' => $mode,
                'text' => $bold_html,
                'parse_mode' => 'HTML',
                'format' => 'html',
                'plain_fallback' => $plain, // keep original plain for API fallback
            ];
        }
        return [
            'mode' => $mode,
            'text' => $plain,
            'parse_mode' => '',
            'plain_fallback' => $plain,
        ];
    }

private static function build_test_content(array $settings): array {
    $mode = self::normalize_message_format((string)($settings['message_format'] ?? 'plain_text'));
    $url = home_url('/');
    $append = self::get_append_text_variants($settings);

    $plain_tail = "\n\nЭто тестовое сообщение плагина MAX Autopost. Оно специально сделано длиннее, чтобы пройти ограничение платформы MAX по минимальной длине текста. Здесь проверяются базовая отправка, форматирование, fallback в plain text и общая работа тестового режима. Если вы видите это сообщение в MAX, значит тестовая отправка работает корректно.";
    $html_tail  = "<br><br>Это тестовое сообщение плагина MAX Autopost. Оно специально сделано длиннее, чтобы пройти ограничение платформы MAX по минимальной длине текста. Здесь проверяются базовая отправка, форматирование, fallback в plain text и общая работа тестового режима. Если вы видите это сообщение в MAX, значит тестовая отправка работает корректно.";

    if ($mode === 'formatted') {
        $title_html = self::is_bold_title_enabled($settings)
            ? '<strong>MAX Autopost: тест форматирования</strong>'
            : 'MAX Autopost: тест форматирования';
        $formatted = $title_html."<br><br><em>Курсивный текст</em><br><a href=\"".esc_url($url)."\">Ссылка на сайт</a><br><br>• Элемент списка 1<br>• Элемент списка 2<br><br><code>code_example()</code>".$html_tail;
        if ($append['html'] !== '') {
            $formatted .= '<br><br>' . $append['html'];
        }
        $plain = "MAX Autopost: тест форматирования\n\nКурсивный текст\nСсылка: ".$url."\n\n• Элемент списка 1\n• Элемент списка 2\n\ncode_example()".$plain_tail;
        $plain = self::append_plain_tail_preserving_end($plain, (string)$append['plain'], $settings);

        return [
            'mode' => 'formatted',
            'text' => $formatted,
            'parse_mode' => 'HTML',
            'format' => 'html',
            'plain_fallback' => $plain,
        ];
    }

    if ($mode === 'title_only') {
        $title = 'MAX Autopost: тест (только заголовок)';
        $plain = self::append_plain_tail_preserving_end($title . $plain_tail, (string)$append['plain'], $settings);
        if (self::is_bold_title_enabled($settings)) {
            return [
                'mode' => 'title_only',
                'text' => '<strong>'.esc_html($title).'</strong>'.$html_tail.($append['html'] !== '' ? '<br><br>'.$append['html'] : ''),
                'parse_mode' => 'HTML',
                'format' => 'html',
                'plain_fallback' => $plain,
            ];
        }
        return [
            'mode' => 'title_only',
            'text' => $plain,
            'parse_mode' => '',
            'plain_fallback' => $plain,
        ];
    }

    $plain = self::append_plain_tail_preserving_end("MAX Autopost: тест\n\n".$url.$plain_tail, (string)$append['plain'], $settings);
    if (self::is_bold_title_enabled($settings)) {
        $html = '<strong>MAX Autopost: тест</strong><br><br>'.esc_html($url).$html_tail;
        if ($append['html'] !== '') {
            $html .= '<br><br>' . $append['html'];
        }
        return [
            'mode' => $mode,
            'text' => $html,
            'parse_mode' => 'HTML',
            'format' => 'html',
            'plain_fallback' => $plain,
        ];
    }

    return [
        'mode' => $mode,
        'text' => $plain,
        'parse_mode' => '',
        'plain_fallback' => $plain,
    ];
}

    private static function build_title_only_text(int $post_id, array $settings): string {
        $override = trim((string)get_post_meta($post_id, self::META_OVERRIDE, true));
        $override = str_replace(["\r\n", "\r"], "\n", $override);
        $append = self::get_append_text_variants($settings);

        if ($override !== '') {
            // Override has priority even in title_only (manual text for this post).
            $text = self::clean_publish_text($override);
            return self::append_plain_tail_preserving_end($text, (string)$append['plain'], $settings);
        }

        $title = self::clean_publish_text((string)get_the_title($post_id));
        return self::append_plain_tail_preserving_end($title, (string)$append['plain'], $settings);
    }

    private static function build_excerpt_plain_text(int $post_id, array $settings): string {
        $title = self::clean_publish_text((string)get_the_title($post_id));
        $excerpt = get_the_excerpt($post_id);
        if ($excerpt === '') {
            $excerpt = wp_strip_all_tags(strip_shortcodes((string)get_post_field('post_content', $post_id)));
        }
        $excerpt = self::clean_publish_text((string)$excerpt);
        $excerpt = wp_trim_words($excerpt, 55, '…');
        $text = trim($title . "\n\n" . $excerpt);
        $append = self::get_append_text_variants($settings);
        return self::append_plain_tail_preserving_end($text, (string)$append['plain'], $settings);
    }

    private static function build_formatted_text(int $post_id, array $settings): string {
        $override = trim((string)get_post_meta($post_id, self::META_OVERRIDE, true));
        $source = $override !== '' ? $override : (string)get_post_field('post_content', $post_id);
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $normalized = self::normalize_wp_html_for_max($source);
        // MAX HTML: prefer <br> spacing over <p> (often ignored by the client).
        $normalized = self::max_html_normalize_blocks($normalized);
        $title_plain = self::clean_publish_text((string)get_the_title($post_id));
        $title = esc_html($title_plain);
        $body = trim($normalized);

        // Title + strict visual gap before body (same rule as bold plain path).
        if (self::is_bold_title_enabled($settings)) {
            $composed = '<strong>' . $title . '</strong>';
        } else {
            $composed = $title;
        }
        if ($body !== '') {
            $composed .= '<br><br>' . $body;
        }

        $with_fields = self::append_custom_fields_formatted($composed, $post_id, $settings);
        $append = self::get_append_text_variants($settings);
        $base_html = $with_fields;
        if ($append['html'] !== '') {
            // Append block also separated by <br><br> for MAX readability.
            $with_fields .= '<br><br>' . $append['html'];
        }

        $user_max = self::normalize_text_limit(isset($settings['max_text_limit']) ? (int)$settings['max_text_limit'] : self::MAX_TEXT);
        $budget = self::is_append_in_limit_enabled($settings) ? $user_max : self::MAX_TEXT;
        $plain_len = mb_strlen(self::clean_publish_text(wp_strip_all_tags($with_fields)), 'UTF-8');

        if ($plain_len > $budget) {
            $base_plain = self::clean_publish_text(wp_strip_all_tags($base_html));
            return self::append_plain_tail_preserving_end($base_plain, (string)$append['plain'], $settings);
        }
        return $with_fields;
    }

    private static function append_allowed_html_tags(): array {
        return [
            'a' => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
            'br' => [],
        ];
    }

    private static function get_append_text_variants(array $settings): array {
        $raw = isset($settings['post_append_text']) ? (string)$settings['post_append_text'] : '';
        $raw = trim($raw);
        if ($raw === '') {
            return ['raw' => '', 'html' => '', 'plain' => ''];
        }

        $html = trim((string)wp_kses($raw, self::append_allowed_html_tags()));
        $plain = self::append_html_to_plain_text($html);

        return [
            'raw' => $raw,
            'html' => $html,
            'plain' => $plain,
        ];
    }

    private static function append_html_to_plain_text(string $html): string {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace_callback('/<a\b([^>]*)>(.*?)<\/a>/is', static function($m) {
            $attrs = (string)($m[1] ?? '');
            $label = self::clean_publish_text(wp_strip_all_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string)($m[2] ?? ''))));
            $url = '';

            if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $href_match)) {
                $url = (string)$href_match[2];
            } elseif (preg_match('/\bhref\s*=\s*([^\s>]+)/i', $attrs, $href_match)) {
                $url = (string)$href_match[1];
            }

            $url = esc_url_raw(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url === '') {
                return $label;
            }
            if ($label === '' || $label === $url) {
                return $url;
            }

            return $label . "\n" . $url;
        }, $html);

        $html = str_replace(['<br>', '<br/>', '<br />'], "\n", (string)$html);
        return self::clean_publish_text(wp_strip_all_tags($html));
    }

    private static function normalize_wp_html_for_max(string $html): string {
        $html = preg_replace('/<!--\s*\/?wp:.*?-->/is', '', $html);
        $html = preg_replace('/<\/?(div|section|article)[^>]*class="[^"]*wp-block[^"]*"[^>]*>/i', '', $html);
        $html = str_replace(['<p>&nbsp;</p>', '<p> </p>'], '', $html);
        $html = self::convert_html_lists_to_text($html);

        $allowed = [
            'b' => [],
            'strong' => [],
            'i' => [],
            'em' => [],
            'a' => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
            'code' => [],
            'pre' => [],
            'br' => [],
            'p' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'blockquote' => [],
        ];

        $html = wp_kses($html, $allowed);
        $html = preg_replace('/<(\/?)p>/i', '<$1p>', $html);
        $html = preg_replace('/(?:<br\s*\/?>\s*){3,}/i', '<br><br>', $html);
        return trim((string)$html);
    }

    private static function convert_html_lists_to_text(string $html): string {
        $html = preg_replace_callback('/<ul[^>]*>(.*?)<\/ul>/is', static function($m) {
            $items = [];
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', (string)$m[1], $li_matches)) {
                foreach ($li_matches[1] as $raw) {
                    $items[] = '• ' . trim(wp_strip_all_tags((string)$raw));
                }
            }
            return !empty($items) ? '<p>' . implode("<br>", $items) . '</p>' : '';
        }, $html);

        $html = preg_replace_callback('/<ol[^>]*>(.*?)<\/ol>/is', static function($m) {
            $items = [];
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', (string)$m[1], $li_matches)) {
                $i = 1;
                foreach ($li_matches[1] as $raw) {
                    $items[] = $i++ . '. ' . trim(wp_strip_all_tags((string)$raw));
                }
            }
            return !empty($items) ? '<p>' . implode("<br>", $items) . '</p>' : '';
        }, $html);

        return $html;
    }

    private static function append_custom_fields_formatted(string $html, int $post_id, array $settings): string {
        if (empty($settings['publish_custom_fields'])) {
            return $html;
        }

        $fields = self::parse_custom_fields_map((string)($settings['custom_fields_map'] ?? ''));
        if (empty($fields)) return $html;

        $lines = [];
        foreach ($fields as $field) {
            $value = get_post_meta($post_id, $field['key'], true);
            if (is_array($value)) $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            $value = self::clean_publish_text((string)$value);
            if ($value === '') continue;
            $safe_value = esc_html($value);
            $safe_label = esc_html((string)$field['label']);
            $lines[] = $safe_label !== '' ? ('<strong>'.$safe_label.':</strong> '.$safe_value) : $safe_value;
        }

        if (empty($lines)) return $html;
        return $html . '<br><br>' . implode('<br>', $lines);
    }

    private static function build_text(int $post_id, array $settings): string {
        $override = trim((string)get_post_meta($post_id,self::META_OVERRIDE,true));
        $override = str_replace(["\r\n","\r"], "\n", $override);
        $append = self::get_append_text_variants($settings);
        if ($override !== '') {
            $text = self::append_custom_fields($override, $post_id, $settings);
            return self::append_plain_tail_preserving_end($text, (string)$append['plain'], $settings);
        }

        $title = self::clean_publish_text((string)get_the_title($post_id));

        $excerpt = has_excerpt($post_id)
            ? get_the_excerpt($post_id)
            : wp_strip_all_tags(strip_shortcodes((string)get_post_field('post_content',$post_id)));

        $excerpt = self::clean_publish_text((string)$excerpt);
        $max = self::normalize_text_limit(isset($settings['max_text_limit']) ? (int)$settings['max_text_limit'] : self::MAX_TEXT);
        $word_limit = max(20, min(300, (int)floor($max / 8)));
        $excerpt = wp_trim_words($excerpt, $word_limit, '…');

        $base = trim($title . "\n\n" . $excerpt);
        $text = self::append_custom_fields($base, $post_id, $settings);
        return self::append_plain_tail_preserving_end($text, (string)$append['plain'], $settings);
    }

    private static function append_plain_tail_preserving_end(string $base_text, string $append_plain, array $settings): string {
        $append_plain = self::clean_publish_text($append_plain);
        if ($append_plain === '') {
            return self::limit_text($base_text, $settings);
        }

        $user_max = self::normalize_text_limit(isset($settings['max_text_limit']) ? (int)$settings['max_text_limit'] : self::MAX_TEXT);
        $hard_max = self::MAX_TEXT;
        $tail = "\n\n" . $append_plain;
        $tail_len = mb_strlen($tail, 'UTF-8');

        // OFF: limit applies to main text only; append is added fully; hard-cap total at 3900.
        if (!self::is_append_in_limit_enabled($settings)) {
            $base = self::limit_text($base_text, array_merge($settings, ['max_text_limit' => $user_max]));
            $combined = trim($base . $tail);
            return self::limit_text($combined, array_merge($settings, ['max_text_limit' => $hard_max]));
        }

        // ON: user max is the total budget for main + append.
        $base_budget = max(0, $user_max - $tail_len);
        if ($base_budget <= 0) {
            return self::limit_text($append_plain, array_merge($settings, ['max_text_limit' => $user_max]));
        }

        $base = self::limit_text($base_text, array_merge($settings, ['max_text_limit' => $base_budget]));
        return self::limit_text(trim($base . $tail), array_merge($settings, ['max_text_limit' => $user_max]));
    }

    private static function append_custom_fields(string $text, int $post_id, array $settings): string {
        if (empty($settings['publish_custom_fields'])) {
            return self::limit_text($text, $settings);
        }

        $fields = self::parse_custom_fields_map((string)($settings['custom_fields_map'] ?? ''));
        if (empty($fields)) {
            return self::limit_text($text, $settings);
        }

        $lines = [];
        foreach ($fields as $field) {
            $value = get_post_meta($post_id, $field['key'], true);
            if (is_array($value)) {
                $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $value = self::clean_publish_text((string)$value);
            if ($value === '') {
                continue;
            }

            $lines[] = $field['label'] !== '' ? ($field['label'] . ': ' . $value) : $value;
        }

        if (empty($lines)) {
            return self::limit_text($text, $settings);
        }

        return self::limit_text($text . "\n\n" . implode("\n", $lines), $settings);
    }

    private static function parse_custom_fields_map(string $map): array {
        $rows = preg_split('/\r\n|\r|\n/', trim($map)) ?: [];
        $result = [];

        foreach ($rows as $row) {
            $row = trim((string)$row);
            if ($row === '') {
                continue;
            }

            [$key, $label] = array_pad(explode('|', $row, 2), 2, '');
            $key = sanitize_key(trim((string)$key));
            $label = sanitize_text_field(trim((string)$label));

            if ($key === '') {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => $label,
            ];
        }

        return $result;
    }

    private static function limit_text(string $text, array $settings): string {
        $text = self::clean_publish_text($text);
        $max = self::normalize_text_limit(isset($settings['max_text_limit']) ? (int)$settings['max_text_limit'] : self::MAX_TEXT);

        if (mb_strlen($text, 'UTF-8') > $max) {
            $text = rtrim(mb_substr($text, 0, max(1, $max - 1), 'UTF-8')) . '…';
        }

        return $text;
    }

    private static function resolve_image_file(int $post_id, array $settings): ?string {
        $mode = self::normalize_image_source_mode((string)($settings['image_source_mode'] ?? 'post_or_site'));

        if ($mode !== 'site_only') {
            $thumb_id = (int)get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $file = get_attached_file($thumb_id);
                if (is_string($file) && $file && file_exists($file)) return $file;
            }

            $content = (string)get_post_field('post_content', $post_id);
            if ($content && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m)) {
                $src = (string)$m[1];
                $u = wp_upload_dir();
                if (!empty($u['baseurl']) && !empty($u['basedir']) && str_starts_with($src, (string)$u['baseurl'])) {
                    $path = wp_normalize_path(str_replace((string)$u['baseurl'], (string)$u['basedir'], $src));
                    if (file_exists($path)) return $path;
                }
            }
        }

        if ($mode !== 'post_only') {
            $site_icon_id = (int)get_option('site_icon');
            if ($site_icon_id) {
                $file = get_attached_file($site_icon_id);
                if (is_string($file) && $file && file_exists($file)) return $file;
            }
        }

        return null;
    }

    private static function log(string $step, int $http, int $post_id, string $msg): void {
        $logs = get_option(self::LOG_OPT, []);
        $logs = is_array($logs) ? $logs : [];

        array_unshift($logs, [
            'time'=>time(),
            'post_id'=>$post_id,
            'step'=>$step,
            'http'=>$http,
            'msg'=>self::short($msg),
        ]);

        if (count($logs) > self::LOG_LIMIT) $logs = array_slice($logs, 0, self::LOG_LIMIT);
        update_option(self::LOG_OPT, $logs, false);
    }

    private static function short(string $s): string {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if (mb_strlen($s) > 320) $s = mb_substr($s, 0, 320) . '…';
        return $s;
    }
}

KRV_MAX_Autopost::init();

register_activation_hook(__FILE__, ['KRV_MAX_Autopost', 'activate']);
register_deactivation_hook(__FILE__, ['KRV_MAX_Autopost', 'deactivate']);
