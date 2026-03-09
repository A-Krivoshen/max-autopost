<?php
/**
 * Plugin Name: MAX Autopost (Free)
 * Description: Автопостинг из WordPress в MAX (platform-api.max.ru): одно сообщение (IMAGE + TEXT + КНОПКА), корректный upload image (полный payload), очередь WP-Cron, retry, логи.
 * Version: 1.8.5
 * Author: Dr.Slon
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

final class KRV_MAX_Autopost {

    private const OPT     = 'krv_max_autopost';
    private const LOG_OPT = 'krv_max_autopost_logs';
    private const VER_OPT = 'krv_max_autopost_ver';
    private const CUTOFF_OPT = 'krv_max_autopost_queue_cutoff';
    private const INSTALL_STAMP_OPT = 'krv_max_autopost_install_stamp';
    private const WORKER_ENABLED_OPT = 'krv_max_autopost_worker_enabled';

    private const VERSION = '1.8.5';

    private const META_STATUS   = '_krv_max_status';   // queued|sent|error
    private const META_ERROR    = '_krv_max_error';
    private const META_ATTEMPTS = '_krv_max_attempts';
    private const META_NEXTTRY  = '_krv_max_next_try';
    private const META_QUEUEDAT = '_krv_max_queued_at';
    private const META_QSTAMP   = '_krv_max_queue_stamp';
    private const META_SENTHASH = '_krv_max_sent_hash';

    private const META_DISABLE  = '_krv_max_disable';
    private const META_OVERRIDE = '_krv_max_override';

    private const CRON_HOOK     = 'krv_max_autopost_cron';
    private const CRON_SCHEDULE = 'krv_max_minute';
    private const CRON_LOCK_KEY = 'krv_max_autopost_lock';

    private const GITHUB_REPO_URL = 'https://github.com/A-Krivoshen/max-autopost';

    private const MIN_TEXT   = 200;
    private const MAX_TEXT   = 3900;
    private const BATCH_LIMIT = 1;
    private const LOG_LIMIT   = 50;

    // Retry backoff (attempt 1..N). After last element -> error.
    private static array $backoff = [60, 180, 600, 1800, 3600];

    public static function init(): void {
        self::maybe_handle_upgrade();

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

        // Register row/bulk hooks after all CPTs are registered.
        add_action('init', [__CLASS__, 'register_post_type_hooks'], 20);
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
        self::quarantine_stale_queue($cutoff);
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }

    /* ================= SETTINGS ================= */


    private static function maybe_handle_upgrade(): void {
        $stored = (string)get_option(self::VER_OPT, '');
        if ($stored === self::VERSION) {
            return;
        }

        $cutoff = time();
        update_option(self::VER_OPT, self::VERSION, false);
        update_option(self::CUTOFF_OPT, $cutoff, false);
        update_option(self::INSTALL_STAMP_OPT, self::new_install_stamp(), false);
        update_option(self::WORKER_ENABLED_OPT, 0, false);
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
            'include_image' => 1,
            'image_source_mode' => 'post_or_site',
            'add_button'    => 1,
            'button_text'   => 'Читать',
            'max_text_limit' => self::MAX_TEXT,
            'publish_custom_fields' => 0,
            'enabled_post_types'    => ['post'],
            'custom_fields_map'     => '',
            'notify'        => 1,
            'debug'         => 0,
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
        ]);
    }

    public static function sanitize_settings($in): array {
        $d = self::defaults();
        $in = is_array($in) ? $in : [];

        $out = [];
        $out['token']   = isset($in['token']) ? trim((string)$in['token']) : $d['token'];
        $out['chat_id'] = isset($in['chat_id']) ? trim((string)$in['chat_id']) : $d['chat_id'];

        $out['include_image'] = !empty($in['include_image']) ? 1 : 0;

        $mode = isset($in['image_source_mode']) ? sanitize_key((string)$in['image_source_mode']) : (string)$d['image_source_mode'];
        $out['image_source_mode'] = self::normalize_image_source_mode($mode);

        $out['add_button']    = !empty($in['add_button']) ? 1 : 0;

        $out['button_text'] = isset($in['button_text']) ? sanitize_text_field((string)$in['button_text']) : $d['button_text'];
        if ($out['button_text'] === '') $out['button_text'] = $d['button_text'];

        $text_limit = isset($in['max_text_limit']) ? (int)$in['max_text_limit'] : (int)$d['max_text_limit'];
        $out['max_text_limit'] = self::normalize_text_limit($text_limit);

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

        return $out;
    }

    private static function token(array $s): string {
        if (defined('KRV_MAX_TOKEN') && is_string(KRV_MAX_TOKEN) && trim(KRV_MAX_TOKEN) !== '') {
            return trim(KRV_MAX_TOKEN);
        }
        return (string)$s['token'];
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
    }

    private static function notice(string $type, string $text): void {
        set_transient('krv_max_notice', ['type'=>$type,'text'=>$text], 60);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;

        $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'settings';
        $s = self::get_settings();

        echo '<div class="wrap"><h1>MAX Autopost (Free) 1.8.5</h1>';
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
        echo '<p style="margin-top:0;"><strong>Партнерский блок</strong> (рекламный виджет):</p>';
        echo '<script src="//wpwidget.ru/js/wps-widget-entry.min.js" async></script>';
        echo '<div class="wps-widget" data-w="//wpwidget.ru/greetings?orientation=3&pid=11291"></div>';
        echo '</div>';
    }

    private static function tab_link(string $tab, string $label, string $active): string {
        $cls = ($tab === $active) ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url = admin_url('admin.php?page=krv-max-autopost&tab=' . $tab);
        return '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private static function tab_settings(array $s): void {
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPT);

        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Token</th><td><input type="password" class="regular-text" name="'.esc_attr(self::OPT).'[token]" value="'.esc_attr($s['token']).'">';
        echo '<p class="description">Можно вынести токен в wp-config.php: <code>define(\'KRV_MAX_TOKEN\', \'...\');</code></p></td></tr>';

        echo '<tr><th>Chat ID</th><td><input type="text" class="regular-text" name="'.esc_attr(self::OPT).'[chat_id]" value="'.esc_attr($s['chat_id']).'"></td></tr>';

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

        echo '<tr><th>Кнопка</th><td>';
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[add_button]" value="1" '.checked((int)$s['add_button'],1,false).'> Включить кнопку “Читать”</label><br>';
        echo '<input type="text" name="'.esc_attr(self::OPT).'[button_text]" value="'.esc_attr($s['button_text']).'" style="width:220px;">';
        echo '<p class="description">inline_keyboard идёт <strong>вторым attachment</strong> (после image, если он есть).</p>';
        echo '</td></tr>';

        echo '<tr><th>Длина текста</th><td>';
        echo '<input type="number" min="'.esc_attr((string)self::MIN_TEXT).'" max="'.esc_attr((string)self::MAX_TEXT).'" step="1" name="'.esc_attr(self::OPT).'[max_text_limit]" value="'.esc_attr((string)(int)$s['max_text_limit']).'" style="width:130px;">';
        echo '<p class="description">Максимальная длина текста для MAX: от '.esc_html((string)self::MIN_TEXT).' до '.esc_html((string)self::MAX_TEXT).' символов.</p>';
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

        echo '<tr><th>Notify</th><td><label><input type="checkbox" name="'.esc_attr(self::OPT).'[notify]" value="1" '.checked((int)$s['notify'],1,false).'> notify=true</label></td></tr>';
        echo '<tr><th>Debug</th><td><label><input type="checkbox" name="'.esc_attr(self::OPT).'[debug]" value="1" '.checked((int)$s['debug'],1,false).'> расширенные логи</label></td></tr>';
        echo '</tbody></table>';

        submit_button('Сохранить');
        echo '</form>';

        echo '<hr><h3>Тест</h3>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_send_test');
        echo '<input type="hidden" name="action" value="krv_max_send_test">';
        submit_button('Отправить тест','secondary','submit',false);
        echo '</form>';
    }

    private static function tab_queue(): void {
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0;">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_run_queue');
        echo '<input type="hidden" name="action" value="krv_max_run_queue">';
        submit_button('Запустить очередь сейчас','secondary','submit',false);
        echo '</form>';
        echo '<p class="description" style="margin:6px 0 0;">Сохранение Token/Chat ID не запускает автоотправку старой очереди. Ручной запуск отправляет только 1 элемент за нажатие.</p>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_requeue_errors');
        echo '<input type="hidden" name="action" value="krv_max_requeue_errors">';
        submit_button('Requeue errors','secondary','submit',false);
        echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('krv_max_queue_all_published');
        echo '<input type="hidden" name="action" value="krv_max_queue_all_published">';
        submit_button('Поставить все опубликованные в очередь','secondary','submit',false,['onclick'=>"return confirm('Добавить все опубликованные материалы в очередь MAX?');"]);
        echo '</form>';
        echo '</div>';

        $q = new WP_Query([
            'post_type'=>self::supported_post_types(),'post_status'=>'any','posts_per_page'=>50,
            'meta_key'=>self::META_STATUS,'orderby'=>'date','order'=>'DESC',
        ]);

        echo '<table class="widefat striped"><thead><tr><th>Пост</th><th>Тип</th><th>Статус</th><th>Попытки</th><th>Next try</th><th>Ошибка</th><th>Действия</th></tr></thead><tbody>';
        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                $id = get_the_ID();
                $st  = (string)get_post_meta($id,self::META_STATUS,true);
                $att = (int)get_post_meta($id,self::META_ATTEMPTS,true);
                $nt  = (int)get_post_meta($id,self::META_NEXTTRY,true);
                $err = (string)get_post_meta($id,self::META_ERROR,true);

                echo '<tr>';
                echo '<td><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html(get_the_title()).'</a></td>';
                echo '<td>'.esc_html(get_post_type($id) ?: '-').'</td>';
                echo '<td>'.esc_html($st ?: '-').'</td>';
                echo '<td>'.esc_html((string)$att).'</td>';
                echo '<td>'.esc_html($nt ? wp_date('Y-m-d H:i:s',$nt) : '-').'</td>';
                echo '<td title="'.esc_attr($err).'" style="max-width:520px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'.esc_html($err).'</td>';

                $send_url = wp_nonce_url(admin_url('admin-post.php?action=krv_max_send_now&post_id='.(int)$id), 'krv_max_send_now_'.(int)$id);
                $queue_url = wp_nonce_url(admin_url('admin-post.php?action=krv_max_queue_now&post_id='.(int)$id), 'krv_max_queue_now_'.(int)$id);
                echo '<td><a class="button button-small" href="'.esc_url($send_url).'">Отправить</a> ';
                echo '<a class="button button-small" href="'.esc_url($queue_url).'">В очередь</a></td>';
                echo '</tr>';
            }
            wp_reset_postdata();
        } else {
            echo '<tr><td colspan="7">Очередь пуста.</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function tab_logs(): void {
        $logs = get_option(self::LOG_OPT, []);
        $logs = is_array($logs) ? $logs : [];

        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Post</th><th>Step</th><th>HTTP</th><th>Message</th></tr></thead><tbody>';
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
                echo '<td title="'.esc_attr($msg).'" style="max-width:760px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'.esc_html($msg).'</td>';
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
        echo '<li>В разделе <strong>Интеграция</strong> получите токен и вставьте его в настройку <strong>Token</strong> плагина.</li>';
        echo '<li>Добавьте бота в нужную группу/канал в MAX, где будут публикации.</li>';
        echo '<li>Отправьте любое сообщение в эту группу (чтобы чат появился в списке API).</li>';
        echo '<li>Ниже нажмите кнопку поиска — плагин попробует показать доступные Chat ID.</li>';
        echo '</ol>';
        echo '<p><strong>Важно:</strong> если список пуст, проверьте права бота в группе и отправьте тестовое сообщение в чат ещё раз.</p>';

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
        wp_nonce_field('krv_max_metabox','krv_max_metabox_nonce');

        $disable = (int)get_post_meta($post->ID,self::META_DISABLE,true);
        $override = (string)get_post_meta($post->ID,self::META_OVERRIDE,true);

        echo '<p><label><input type="checkbox" name="krv_max_disable" value="1" '.checked($disable,1,false).'> Не отправлять в MAX</label></p>';
        echo '<p><strong>Override текст</strong>:</p>';
        echo '<textarea name="krv_max_override" class="widefat" style="min-height:80px;">'.esc_textarea($override).'</textarea>';

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=krv_max_send_now&post_id='.(int)$post->ID),
            'krv_max_send_now_'.(int)$post->ID
        );
        echo '<p style="margin-top:10px;"><a class="button button-secondary" href="'.esc_url($url).'">Отправить сейчас</a></p>';
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
        update_post_meta($post_id,self::META_ATTEMPTS,0);
        $now = time();
        update_post_meta($post_id,self::META_NEXTTRY,$now);
        update_post_meta($post_id,self::META_QUEUEDAT,$now);
        update_post_meta($post_id,self::META_QSTAMP,self::current_install_stamp());
        self::log('queue',0,$post_id,$why ?: 'queued');
    }

    private static function trigger_queue_worker(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }

        wp_schedule_single_event(time() + 5, self::CRON_HOOK);
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

                if ($res === true) {
                    update_post_meta($post_id,self::META_STATUS,'sent');
                    delete_post_meta($post_id,self::META_ERROR);
                    delete_post_meta($post_id,self::META_ATTEMPTS);
                    delete_post_meta($post_id,self::META_NEXTTRY);
                    delete_post_meta($post_id,self::META_QUEUEDAT);
                    delete_post_meta($post_id,self::META_QSTAMP);
                    continue;
                }

                $attempts = (int)get_post_meta($post_id,self::META_ATTEMPTS,true);
                $attempts++;
                update_post_meta($post_id,self::META_ATTEMPTS,$attempts);
                update_post_meta($post_id,self::META_ERROR,(string)$res);

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
        $chat_id = (string)$s['chat_id'];

        if ($token === '' || $chat_id === '') {
            self::notice('error','Не задан token или chat_id.');
            wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=settings'));
            exit;
        }

        $payload = ['text'=>"MAX Autopost: тест\n\n".home_url('/'),'notify'=>(bool)$s['notify']];
        $attachments = [];

        // Optional image for test message
        if (!empty($s['include_image']) && function_exists('curl_init')) {
            $mode = (string)($s['image_source_mode'] ?? 'post_or_site');
            if (self::normalize_image_source_mode($mode) !== 'post_only') {
                $site_icon = (int)get_option('site_icon');
                if ($site_icon) {
                    $file = get_attached_file($site_icon);
                    if (is_string($file) && $file && file_exists($file)) {
                        $up = self::upload($file, $token, 0);
                        if ($up !== false) {
                            $attachments[] = ['type'=>'image','payload'=>$up];
                        }
                    }
                }
            }
        }

        if (!empty($s['add_button'])) {
            $attachments[] = [
                'type'=>'inline_keyboard',
                'payload'=>[
                    'buttons'=>[[[
                        'type'=>'link',
                        'text'=>(string)$s['button_text'],
                        'url'=>home_url('/'),
                    ]]],
                ],
            ];
        }

        if (!empty($attachments)) $payload['attachments'] = $attachments;

        $ok = self::api($payload, $chat_id, $token, 0, (bool)$s['debug']);
        if ($ok === true) {
            update_option(self::WORKER_ENABLED_OPT, 1, false);
        }
        self::notice($ok===true?'success':'error', $ok===true?'Тест отправлен. Автоотправка очереди включена.':'Тест не отправился: '.self::short((string)$ok));

        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=settings'));
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

    public static function handle_requeue_errors(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('krv_max_requeue_errors');

        $posts = get_posts([
            'post_type'=>self::supported_post_types(),'post_status'=>'any','numberposts'=>-1,
            'meta_key'=>self::META_STATUS,'meta_value'=>'error',
        ]);

        foreach ($posts as $p) {
            self::queue_post((int)$p->ID,'Requeue error');
        }

        self::trigger_queue_worker();
        self::notice('success','Ошибочные посты переведены в очередь.');
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=queue'));
        exit;
    }

    public static function handle_send_now(): void {
        if (!current_user_can('edit_posts')) wp_die('Forbidden');

        $post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
        if (!$post_id) wp_die('Bad request');
        check_admin_referer('krv_max_send_now_'.$post_id);

        $res = self::send($post_id);

        if ($res === true) {
            update_post_meta($post_id,self::META_STATUS,'sent');
            delete_post_meta($post_id,self::META_ERROR);
            self::notice('success','Отправлено в MAX.');
        } else {
            update_post_meta($post_id,self::META_STATUS,'error');
            update_post_meta($post_id,self::META_ERROR,(string)$res);
            self::notice('error','Ошибка: '.self::short((string)$res));
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php'));
        exit;
    }


    public static function handle_queue_now(): void {
        if (!current_user_can('edit_posts')) wp_die('Forbidden');

        $post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
        if (!$post_id) wp_die('Bad request');
        check_admin_referer('krv_max_queue_now_'.$post_id);

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
            'numberposts'=>-1,
            'fields'=>'ids',
        ]);

        foreach ($posts as $id) {
            self::queue_post((int)$id,'Bulk queue all published');
        }

        self::trigger_queue_worker();
        self::notice('success','Все опубликованные материалы добавлены в очередь: '.count($posts));
        wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost&tab=queue'));
        exit;
    }

    /* ================= ROW / BULK ================= */

    public static function row_action(array $actions, WP_Post $post): array {
        if (!self::is_supported_post_type($post->post_type)) return $actions;

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

        foreach ($post_ids as $id) {
            $post = get_post((int)$id);
            if (!$post || !self::is_supported_post_type($post->post_type)) {
                continue;
            }
            self::queue_post((int)$id,'Bulk queue');
        }

        self::trigger_queue_worker();
        self::notice('success','Посты добавлены в очередь.');
        return $redirect_to;
    }

    /* ================= CORE: send / upload / api ================= */

    private static function send(int $post_id) {
        $s = self::get_settings();
        $token = self::token($s);
        $chat_id = (string)$s['chat_id'];

        if ($token === '' || $chat_id === '') return 'Не задан token/chat_id';

        $post = get_post($post_id);
        if (!$post) return 'Пост не найден';
        if (!self::is_supported_post_type($post->post_type)) return 'Тип записи не поддерживается';
        if ($post->post_status !== 'publish') return 'Можно отправлять только опубликованные материалы';

        if ((int)get_post_meta($post_id,self::META_DISABLE,true) === 1) return 'Отключено в метабоксе.';

        $text = self::build_text($post_id, $s);
        $url  = get_permalink($post_id);

        $payload = ['text'=>$text,'notify'=>(bool)$s['notify']];
        $attachments = [];

        // IMAGE first
        if (!empty($s['include_image']) && function_exists('curl_init')) {
            $file = self::resolve_image_file($post_id, $s);
            if ($file) {
                $up = self::upload($file, $token, $post_id);
                if ($up === false) return 'Upload failed (see logs)';
                $attachments[] = ['type'=>'image','payload'=>$up]; // IMPORTANT: full JSON
            }
        }

        // BUTTON second
        if (!empty($s['add_button'])) {
            $attachments[] = [
                'type'=>'inline_keyboard',
                'payload'=>[
                    'buttons'=>[[[
                        'type'=>'link',
                        'text'=>(string)$s['button_text'],
                        'url'=>$url,
                    ]]],
                ],
            ];
        }

        if (!empty($attachments)) $payload['attachments'] = $attachments;

        // Dedupe (stable hash w/o upload payload)
        $sig = [
            'text'=>$payload['text'],
            'notify'=>$payload['notify'],
            'has_image'=>(int)(isset($attachments[0]) && $attachments[0]['type']==='image'),
            'has_button'=>(int)!empty($s['add_button']),
            'button_text'=>(string)$s['button_text'],
            'url'=>$url,
            'post_modified_gmt'=>get_post_modified_time('U', true, $post_id),
        ];
        $hash = hash('sha256', wp_json_encode($sig, JSON_UNESCAPED_UNICODE));
        $prev = (string)get_post_meta($post_id,self::META_SENTHASH,true);
        if ($prev && hash_equals($prev,$hash)) return true;

        $res = self::api($payload, $chat_id, $token, $post_id, (bool)$s['debug']);
        if ($res === true) {
            update_post_meta($post_id,self::META_SENTHASH,$hash);
            return true;
        }
        return $res;
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
        $r1 = wp_remote_post('https://platform-api.max.ru/uploads?type=image', [
            'headers'=>[
                'Authorization'=>$token,
                'Content-Type'=>'application/json',
            ],
            'body'=>'{}',
            'timeout'=>20,
        ]);

        if (is_wp_error($r1)) {
            self::log('upload_step1', 0, $post_id, $r1->get_error_message());
            return false;
        }

        $code1 = (int)wp_remote_retrieve_response_code($r1);
        $body1 = (string)wp_remote_retrieve_body($r1);

        if ($code1 < 200 || $code1 >= 300) {
            self::log('upload_step1', $code1, $post_id, 'HTTP '.$code1.': '.self::short($body1));
            return false;
        }

        $j1 = json_decode($body1, true);
        if (!is_array($j1) || empty($j1['url'])) {
            self::log('upload_step1', $code1, $post_id, 'Bad JSON: '.self::short($body1));
            return false;
        }

        // step2
        $upload_url = (string)$j1['url'];

        $ch = curl_init($upload_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['data'=>new CURLFile($file)]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $out = curl_exec($ch);
        $code2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            self::log('upload_step2', $code2 ?: 0, $post_id, 'cURL error: '.$cerr);
            return false;
        }

        if ($code2 < 200 || $code2 >= 300) {
            self::log('upload_step2', $code2, $post_id, 'HTTP '.$code2.': '.self::short((string)$out));
            return false;
        }

        $j2 = json_decode((string)$out, true);

        // ✅ FIX (your log case): MAX may return nested objects, e.g. {"photos":{...:{token:"..."}}}
        // We must accept any valid JSON object/array and pass it as-is into image.payload.
        if (!is_array($j2) || empty($j2)) {
            self::log('upload_step2', $code2, $post_id, 'Bad JSON: '.self::short((string)$out));
            return false;
        }

        return $j2;
    }


    private static function discover_chats(string $token): array {
        $endpoints = [
            'https://platform-api.max.ru/chats?limit=50',
            'https://platform-api.max.ru/chats',
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

    private static function api(array $payload, string $chat_id, string $token, int $post_id, bool $debug) {
        $url = 'https://platform-api.max.ru/messages?chat_id=' . rawurlencode($chat_id);

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
            self::log('send', 0, $post_id, 'WP_Error: '.$msg);
            return $msg;
        }

        $code = (int)wp_remote_retrieve_response_code($r);
        $body = (string)wp_remote_retrieve_body($r);

        if ($code >= 200 && $code < 300) {
            if ($debug && $body !== '') self::log('send', $code, $post_id, 'Response: '.self::short($body));
            return true;
        }

        self::log('send', $code, $post_id, 'HTTP '.$code.': '.self::short($body));
        return 'HTTP '.$code.': '.self::short($body);
    }

    /* ================= HELPERS ================= */
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

    private static function build_text(int $post_id, array $settings): string {
        $override = trim((string)get_post_meta($post_id,self::META_OVERRIDE,true));
        $override = str_replace(["\r\n","\r"], "\n", $override);
        if ($override !== '') return self::append_custom_fields($override, $post_id, $settings);

        $title = self::clean_publish_text((string)get_the_title($post_id));

        $excerpt = has_excerpt($post_id)
            ? get_the_excerpt($post_id)
            : wp_strip_all_tags(strip_shortcodes((string)get_post_field('post_content',$post_id)));

        $excerpt = self::clean_publish_text((string)$excerpt);
        $max = self::normalize_text_limit(isset($settings['max_text_limit']) ? (int)$settings['max_text_limit'] : self::MAX_TEXT);
        $word_limit = max(20, min(300, (int)floor($max / 8)));
        $excerpt = wp_trim_words($excerpt, $word_limit, '…');

        $base = trim($title . "\n\n" . $excerpt);
        return self::append_custom_fields($base, $post_id, $settings);
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
