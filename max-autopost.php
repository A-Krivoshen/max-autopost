<?php
/**
 * Plugin Name: MAX Autopost
 * Plugin URI:  https://github.com/DrSlon/max-autopost
 * Description: Production-ready автопостинг из WordPress в MAX (platform-api.max.ru): одно сообщение (image + text + inline button), upload image с полным payload, очередь через WP-Cron, ручная отправка, тест и логи.
 * Version:     1.0.0
 * Author:      Dr.Slon
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

if ( ! defined('ABSPATH') ) exit;

final class KRV_MAX_Autopost {

    public const OPT_KEY      = 'krv_max_autopost';
    public const LOG_OPT_KEY  = 'krv_max_autopost_log';
    public const META_STATUS  = '_krv_max_status';
    public const META_ERROR   = '_krv_max_error';
    public const CRON_HOOK    = 'krv_max_autopost_cron';
    public const CRON_SCHED   = 'krv_max_autopost_minute';

    private static ?self $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    /* ===================== Bootstrap ===================== */

    public function hooks(): void {

        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('admin_menu',      [$this, 'admin_menu']);
        add_action('admin_init',      [$this, 'register_settings']);

        add_action('transition_post_status', [$this, 'queue_on_publish'], 20, 3);
        add_action(self::CRON_HOOK,           [$this, 'process_queue']);

        add_action('admin_post_krv_max_test',     [$this, 'handle_test_send']);
        add_action('admin_post_krv_max_send_now', [$this, 'handle_send_now']);

        add_filter('post_row_actions',          [$this, 'row_action'], 10, 2);
        add_filter('bulk_actions-edit-post',    [$this, 'bulk_action']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk'], 10, 3);
    }

    public static function activate(): void {
        $self = self::instance();
        $self->maybe_schedule_cron();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function cron_schedules(array $schedules): array {
        if ( ! isset($schedules[self::CRON_SCHED]) ) {
            $schedules[self::CRON_SCHED] = [
                'interval' => 60,
                'display'  => 'Every Minute (MAX Autopost)'
            ];
        }
        return $schedules;
    }

    private function maybe_schedule_cron(): void {
        if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
            wp_schedule_event(time() + 60, self::CRON_SCHED, self::CRON_HOOK);
        }
    }

    /* ===================== Settings ===================== */

    public function defaults(): array {
        return [
            'token'         => '',
            'chat_id'       => '',
            'include_image' => 1,
            'add_button'    => 1,
            'button_text'   => 'Читать',
            'notify'        => 1,
        ];
    }

    public function get_settings(): array {
        return wp_parse_args(get_option(self::OPT_KEY, []), $this->defaults());
    }

    public function register_settings(): void {
        register_setting(
            self::OPT_KEY,
            self::OPT_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => $this->defaults(),
            ]
        );
    }

    public function sanitize_settings($value): array {
        $d = $this->defaults();
        $v = is_array($value) ? $value : [];

        $out = [];
        $out['token'] = isset($v['token']) ? trim((string)$v['token']) : $d['token'];
        $out['chat_id'] = isset($v['chat_id']) ? trim((string)$v['chat_id']) : $d['chat_id'];

        $out['include_image'] = ! empty($v['include_image']) ? 1 : 0;
        $out['add_button']    = ! empty($v['add_button']) ? 1 : 0;

        $btn = isset($v['button_text']) ? trim((string)$v['button_text']) : $d['button_text'];
        $btn = $btn !== '' ? $btn : $d['button_text'];
        $out['button_text']   = mb_substr($btn, 0, 40);

        $out['notify'] = ! empty($v['notify']) ? 1 : 0;

        return $out;
    }

    /* ===================== Admin UI ===================== */

    public function admin_menu(): void {
        add_menu_page(
            'MAX Autopost',
            'MAX Autopost',
            'manage_options',
            'krv-max-autopost',
            [$this, 'render_page'],
            'dashicons-megaphone',
            65
        );
    }

    public function render_page(): void {
        if ( ! current_user_can('manage_options') ) return;

        $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'settings';
        $s = $this->get_settings();

        $notice = '';
        if ( isset($_GET['krv_notice']) ) {
            $notice = sanitize_key((string)$_GET['krv_notice']);
        }

        echo '<div class="wrap">';
        echo '<h1>MAX Autopost</h1>';

        if ( $notice === 'test_ok' ) {
            echo '<div class="notice notice-success"><p>Тестовое сообщение отправлено.</p></div>';
        } elseif ( $notice === 'test_fail' ) {
            echo '<div class="notice notice-error"><p>Тестовая отправка завершилась ошибкой. Смотри вкладку «Логи».</p></div>';
        } elseif ( $notice === 'queued' ) {
            echo '<div class="notice notice-success"><p>Запись(и) поставлены в очередь.</p></div>';
        }

        echo '<h2 class="nav-tab-wrapper">';
        echo '<a class="nav-tab ' . ($tab==='settings' ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=krv-max-autopost&tab=settings')) . '">Настройки</a>';
        echo '<a class="nav-tab ' . ($tab==='queue' ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=krv-max-autopost&tab=queue')) . '">Очередь</a>';
        echo '<a class="nav-tab ' . ($tab==='logs' ? 'nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=krv-max-autopost&tab=logs')) . '">Логи</a>';
        echo '</h2>';

        if ( $tab === 'settings' ) {
            $this->render_tab_settings($s);
        } elseif ( $tab === 'queue' ) {
            $this->render_tab_queue();
        } else {
            $this->render_tab_logs();
        }

        echo '</div>';
    }

    private function render_tab_settings(array $s): void {

        // curl check (required for upload)
        if ( ! function_exists('curl_init') ) {
            echo '<div class="notice notice-warning"><p><strong>Внимание:</strong> на сервере не найдено расширение cURL. Загрузка изображений в MAX работать не будет.</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPT_KEY);

        echo '<table class="form-table">';
        echo '<tr><th scope="row">Token</th><td><input type="text" name="' . esc_attr(self::OPT_KEY) . '[token]" value="' . esc_attr($s['token']) . '" class="regular-text" autocomplete="off"></td></tr>';
        echo '<tr><th scope="row">Chat ID</th><td><input type="text" name="' . esc_attr(self::OPT_KEY) . '[chat_id]" value="' . esc_attr($s['chat_id']) . '" class="regular-text" autocomplete="off"></td></tr>';

        echo '<tr><th scope="row">Включить изображение</th><td><label><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[include_image]" value="1" ' . checked(1, (int)$s['include_image'], false) . '> Да</label></td></tr>';

        echo '<tr><th scope="row">Включить кнопку</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[add_button]" value="1" ' . checked(1, (int)$s['add_button'], false) . '> Да</label> ';
        echo '<input type="text" name="' . esc_attr(self::OPT_KEY) . '[button_text]" value="' . esc_attr($s['button_text']) . '" class="regular-text" style="max-width:220px" />';
        echo '</td></tr>';

        echo '<tr><th scope="row">Notify</th><td><label><input type="checkbox" name="' . esc_attr(self::OPT_KEY) . '[notify]" value="1" ' . checked(1, (int)$s['notify'], false) . '> true</label></td></tr>';
        echo '</table>';

        submit_button('Сохранить');
        echo '</form>';

        echo '<hr><h2>Тестовая отправка</h2>';
        echo '<p>Отправляет тестовое сообщение в MAX в указанный <code>chat_id</code> без очереди. Формат учитывает настройки изображения/кнопки.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('krv_max_test_nonce');
        echo '<input type="hidden" name="action" value="krv_max_test" />';
        submit_button('Отправить тест', 'secondary');
        echo '</form>';
    }

    private function render_tab_queue(): void {

        $status = isset($_GET['status']) ? sanitize_key((string)$_GET['status']) : '';
        $allowed = ['queued','sent','error'];
        $status = in_array($status, $allowed, true) ? $status : '';

        echo '<p>';
        echo 'Фильтр: ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=krv-max-autopost&tab=queue')) . '">Все</a> | ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=krv-max-autopost&tab=queue&status=queued')) . '">queued</a> | ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=krv-max-autopost&tab=queue&status=sent')) . '">sent</a> | ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=krv-max-autopost&tab=queue&status=error')) . '">error</a>';
        echo '</p>';

        $meta_query = [];
        if ( $status ) {
            $meta_query[] = [
                'key'   => self::META_STATUS,
                'value' => $status,
            ];
        } else {
            $meta_query[] = [
                'key'     => self::META_STATUS,
                'compare' => 'EXISTS',
            ];
        }

        $q = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => $meta_query,
            'no_found_rows'  => true,
        ]);

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:70px">ID</th><th>Запись</th><th style="width:100px">Статус</th><th>Ошибка</th><th style="width:160px">Дата</th><th style="width:140px">Действие</th>';
        echo '</tr></thead><tbody>';

        if ( $q->have_posts() ) {
            foreach ( $q->posts as $p ) {
                $st = (string) get_post_meta($p->ID, self::META_STATUS, true);
                $err = (string) get_post_meta($p->ID, self::META_ERROR, true);
                $send_url = wp_nonce_url(
                    admin_url('admin-post.php?action=krv_max_send_now&post_id=' . $p->ID),
                    'krv_max_send_' . $p->ID
                );

                echo '<tr>';
                echo '<td>' . esc_html((string)$p->ID) . '</td>';
                echo '<td><a href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html(get_the_title($p->ID)) . '</a></td>';
                echo '<td>' . esc_html($st ?: '-') . '</td>';
                echo '<td style="max-width:520px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="' . esc_attr($err) . '">' . esc_html($err) . '</td>';
                echo '<td>' . esc_html(get_the_date('Y-m-d H:i', $p->ID)) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url($send_url) . '">В очередь</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6">Нет записей в очереди/истории.</td></tr>';
        }

        echo '</tbody></table>';
        wp_reset_postdata();

        echo '<p style="margin-top:12px;color:#666">Cron: обрабатывается раз в минуту, за проход — максимум 5 записей.</p>';
    }

    private function render_tab_logs(): void {
        $log = get_option(self::LOG_OPT_KEY, []);
        if ( ! is_array($log) ) $log = [];

        echo '<p>Последние 50 ошибок (если MAX вернул HTTP != 2xx, невалидный JSON, cURL/сеть и т.п.).</p>';

        if ( empty($log) ) {
            echo '<p><em>Пока пусто. И это хороший знак 🙂</em></p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th style="width:170px">Время</th><th style="width:80px">Post ID</th><th>Ошибка</th></tr></thead><tbody>';

        foreach ( $log as $row ) {
            $ts  = isset($row['time']) ? (string)$row['time'] : '';
            $pid = isset($row['post_id']) ? (int)$row['post_id'] : 0;
            $msg = isset($row['message']) ? (string)$row['message'] : '';

            $link = $pid ? '<a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html((string)$pid) . '</a>' : '—';

            echo '<tr>';
            echo '<td>' . esc_html($ts) . '</td>';
            echo '<td>' . $link . '</td>';
            echo '<td style="white-space:pre-wrap">' . esc_html($msg) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /* ===================== Queue ===================== */

    public function queue_on_publish(string $new_status, string $old_status, WP_Post $post): void {
        if ( $post->post_type !== 'post' ) return;
        if ( wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID) ) return;

        // Only on first transition to publish
        if ( $old_status !== 'publish' && $new_status === 'publish' ) {
            update_post_meta($post->ID, self::META_STATUS, 'queued');
            delete_post_meta($post->ID, self::META_ERROR);
        }
    }

    public function process_queue(): void {

        $this->maybe_schedule_cron();

        $q = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'   => self::META_STATUS,
                    'value' => 'queued',
                ]
            ],
            'no_found_rows'  => true,
        ]);

        if ( ! $q->have_posts() ) return;

        foreach ( $q->posts as $p ) {

            // Guard: prevent double processing by parallel cron (best-effort)
            update_post_meta($p->ID, self::META_STATUS, 'processing');

            $res = $this->send_post($p->ID);

            if ( $res === true ) {
                update_post_meta($p->ID, self::META_STATUS, 'sent');
                delete_post_meta($p->ID, self::META_ERROR);
            } else {
                update_post_meta($p->ID, self::META_STATUS, 'error');
                update_post_meta($p->ID, self::META_ERROR, (string)$res);
                $this->log_error($p->ID, (string)$res);
            }
        }

        wp_reset_postdata();
    }

    /* ===================== Manual send (row/bulk) ===================== */

    public function row_action(array $actions, WP_Post $post): array {
        if ( $post->post_type !== 'post' ) return $actions;
        if ( ! current_user_can('edit_post', $post->ID) ) return $actions;

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=krv_max_send_now&post_id=' . $post->ID),
            'krv_max_send_' . $post->ID
        );

        $actions['krv_max_send'] = '<a href="' . esc_url($url) . '">Отправить в MAX</a>';
        return $actions;
    }

    public function handle_send_now(): void {
        $id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        if ( ! $id || ! current_user_can('edit_post', $id) ) {
            wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php'));
            exit;
        }

        check_admin_referer('krv_max_send_' . $id);

        update_post_meta($id, self::META_STATUS, 'queued');
        delete_post_meta($id, self::META_ERROR);

        $ref = wp_get_referer() ?: admin_url('edit.php');
        $ref = add_query_arg('krv_notice', 'queued', $ref);
        wp_safe_redirect($ref);
        exit;
    }

    public function bulk_action(array $actions): array {
        $actions['krv_max_bulk'] = 'Отправить в MAX';
        return $actions;
    }

    public function handle_bulk(string $redirect, string $doaction, array $post_ids): string {
        if ( $doaction !== 'krv_max_bulk' ) return $redirect;

        $queued = 0;
        foreach ( $post_ids as $id ) {
            $id = (int) $id;
            if ( $id && current_user_can('edit_post', $id) ) {
                update_post_meta($id, self::META_STATUS, 'queued');
                delete_post_meta($id, self::META_ERROR);
                $queued++;
            }
        }

        return add_query_arg('krv_notice', 'queued', $redirect);
    }

    /* ===================== Test ===================== */

    public function handle_test_send(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_safe_redirect(admin_url('admin.php?page=krv-max-autopost'));
            exit;
        }

        check_admin_referer('krv_max_test_nonce');

        $s = $this->get_settings();
        $text = "Тестовое сообщение из WordPress\n\n" . home_url();

        // try to use Site Icon as test image (optional)
        $image_file = null;
        $site_icon_url = get_site_icon_url(512);
        if ( $site_icon_url ) {
            $image_file = $this->download_to_temp($site_icon_url);
        }

        $res = $this->send_custom($text, $image_file, home_url());

        if ( $image_file && file_exists($image_file) ) @unlink($image_file);

        $redir = admin_url('admin.php?page=krv-max-autopost&tab=settings');
        $redir = add_query_arg('krv_notice', $res === true ? 'test_ok' : 'test_fail', $redir);

        if ( $res !== true ) $this->log_error(0, (string)$res);

        wp_safe_redirect($redir);
        exit;
    }

    /* ===================== Core: build + send ===================== */

    public function send_post(int $post_id): bool|string {

        $s = $this->get_settings();
        $check = $this->check_settings($s);
        if ( $check !== true ) return $check;

        $text = $this->build_post_text($post_id);
        $post_url = get_permalink($post_id);

        $attachments = [];

        // IMAGE FIRST (full payload)
        if ( ! empty($s['include_image']) ) {
            $upload_payload = $this->maybe_upload_featured_image($post_id, $s['token']);
            if ( is_string($upload_payload) ) {
                // error
                return $upload_payload;
            }
            if ( is_array($upload_payload) ) {
                $attachments[] = [
                    'type'    => 'image',
                    'payload' => $upload_payload, // IMPORTANT: full JSON from upload
                ];
            }
        }

        // INLINE BUTTON SECOND
        if ( ! empty($s['add_button']) ) {
            $attachments[] = [
                'type'    => 'inline_keyboard',
                'payload' => [
                    'buttons' => [[[
                        'type' => 'link',
                        'text' => (string)$s['button_text'],
                        'url'  => (string)$post_url,
                    ]]],
                ],
            ];
        }

        $body = [
            'text'   => $text,
            'notify' => (bool) $s['notify'],
        ];

        if ( ! empty($attachments) ) {
            $body['attachments'] = $attachments;
        }

        return $this->api_send_message($body, $s);
    }

    /**
     * Sends arbitrary text using settings (and optional image/button).
     * Used for test message.
     */
    private function send_custom(string $text, ?string $image_file, string $button_url): bool|string {

        $s = $this->get_settings();
        $check = $this->check_settings($s);
        if ( $check !== true ) return $check;

        $text = $this->limit_text($text, 3900);

        $attachments = [];

        if ( ! empty($s['include_image']) && $image_file && file_exists($image_file) ) {
            $upload_payload = $this->upload_image_file($image_file, $s['token']);
            if ( is_string($upload_payload) ) return $upload_payload;
            if ( is_array($upload_payload) ) {
                $attachments[] = [
                    'type'    => 'image',
                    'payload' => $upload_payload,
                ];
            }
        }

        if ( ! empty($s['add_button']) ) {
            $attachments[] = [
                'type'    => 'inline_keyboard',
                'payload' => [
                    'buttons' => [[[
                        'type' => 'link',
                        'text' => (string)$s['button_text'],
                        'url'  => $button_url,
                    ]]],
                ],
            ];
        }

        $body = [
            'text'   => $text,
            'notify' => (bool) $s['notify'],
        ];

        if ( ! empty($attachments) ) $body['attachments'] = $attachments;

        return $this->api_send_message($body, $s);
    }

    private function check_settings(array $s): bool|string {
        if ( empty($s['token']) ) return 'MAX: token не задан.';
        if ( empty($s['chat_id']) ) return 'MAX: chat_id не задан.';
        return true;
    }

    private function build_post_text(int $post_id): string {
        $title = get_the_title($post_id);
        $title = $title ? wp_strip_all_tags($title, true) : '';

        $excerpt = get_post_field('post_excerpt', $post_id);
        $excerpt = is_string($excerpt) ? trim($excerpt) : '';

        if ( $excerpt === '' ) {
            $content = get_post_field('post_content', $post_id);
            $content = is_string($content) ? $content : '';
            $content = strip_shortcodes($content);
            $content = wp_strip_all_tags($content, true);
            $content = preg_replace('/\s+/u', ' ', $content);
            $excerpt = trim((string)$content);
        } else {
            $excerpt = wp_strip_all_tags($excerpt, true);
        }

        // A sane excerpt length; MAX limit applies below
        if ( $excerpt !== '' ) {
            $excerpt = $this->trim_words_mb($excerpt, 40, '…');
        }

        $text = $title;
        if ( $excerpt !== '' ) $text .= "\n\n" . $excerpt;

        return $this->limit_text($text, 3900);
    }

    private function limit_text(string $text, int $limit): string {
        $text = trim((string)$text);
        if ( mb_strlen($text) <= $limit ) return $text;
        return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    private function trim_words_mb(string $text, int $words, string $more): string {
        $text = trim($text);
        if ( $text === '' ) return '';

        // Rough word split with Unicode spaces
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ( ! is_array($parts) || count($parts) <= $words ) return $text;

        $parts = array_slice($parts, 0, $words);
        return trim(implode(' ', $parts)) . $more;
    }

    /* ===================== Upload (strict) ===================== */

    /**
     * Upload featured image of a post. Returns:
     * - array upload payload (token/url/type/...) on success
     * - null if no image (allowed)
     * - string error message on failure
     */
    private function maybe_upload_featured_image(int $post_id, string $token): array|string|null {

        $thumb_id = get_post_thumbnail_id($post_id);
        if ( ! $thumb_id ) return null;

        $file = get_attached_file($thumb_id);
        $file = is_string($file) ? $file : '';

        if ( $file && file_exists($file) ) {
            return $this->upload_image_file($file, $token);
        }

        // Fallback: download by URL (for offload/S3 cases)
        $src = wp_get_attachment_image_src($thumb_id, 'full');
        $url = is_array($src) && ! empty($src[0]) ? (string)$src[0] : '';
        if ( ! $url ) return null;

        $tmp = $this->download_to_temp($url);
        if ( ! $tmp ) return 'MAX upload: не удалось получить файл миниатюры (fallback download).';

        $res = $this->upload_image_file($tmp, $token);
        @unlink($tmp);

        return $res;
    }

    /**
     * Performs 2-step MAX upload (strict JSON validation).
     * Returns array payload (full JSON) or string error.
     */
    private function upload_image_file(string $file, string $token): array|string {

        if ( ! function_exists('curl_init') ) {
            return 'MAX upload: на сервере нет cURL (curl_init).';
        }

        if ( ! file_exists($file) ) {
            return 'MAX upload: файл не найден: ' . basename($file);
        }

        // Step 1: request upload URL
        $r1 = wp_remote_post(
            'https://platform-api.max.ru/uploads?type=image',
            [
                'headers' => [
                    'Authorization' => $token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => '{}',
                'timeout' => 20,
            ]
        );

        if ( is_wp_error($r1) ) {
            return 'MAX upload step1: ' . $r1->get_error_message();
        }

        $code1 = wp_remote_retrieve_response_code($r1);
        $body1 = (string) wp_remote_retrieve_body($r1);

        if ( $code1 < 200 || $code1 >= 300 ) {
            return 'MAX upload step1 HTTP ' . $code1 . ': ' . $this->safe_snippet($body1);
        }

        $json1 = $this->json_decode_strict($body1);
        if ( ! is_array($json1) || empty($json1['url']) ) {
            return 'MAX upload step1: невалидный JSON или нет url. Ответ: ' . $this->safe_snippet($body1);
        }

        $upload_url = (string) $json1['url'];

        // Step 2: multipart upload via cURL
        $ch = curl_init($upload_url);
        if ( ! $ch ) return 'MAX upload step2: curl_init failed.';

        $post_fields = [
            'data' => new CURLFile($file),
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $post_fields,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $out = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ( $out === false ) {
            return 'MAX upload step2 cURL error: ' . ($err ?: 'unknown');
        }

        if ( $http < 200 || $http >= 300 ) {
            return 'MAX upload step2 HTTP ' . $http . ': ' . $this->safe_snippet((string)$out);
        }

        $json2 = $this->json_decode_strict((string)$out);
        if ( ! is_array($json2) ) {
            return 'MAX upload step2: невалидный JSON. Ответ: ' . $this->safe_snippet((string)$out);
        }

        // STRICT: must not be token-only; require token+url+type (at least)
        $has_token = ! empty($json2['token']);
        $has_url   = ! empty($json2['url']);
        $has_type  = ! empty($json2['type']);

        if ( ! ($has_token && $has_url && $has_type) ) {
            return 'MAX upload step2: payload неполный (нужны token+url+type). Ответ: ' . $this->safe_snippet((string)$out);
        }

        return $json2; // IMPORTANT: full JSON payload
    }

    /* ===================== API send ===================== */

    private function api_send_message(array $body, array $s): bool|string {

        $url = 'https://platform-api.max.ru/messages?chat_id=' . rawurlencode((string)$s['chat_id']);

        $res = wp_remote_post(
            $url,
            [
                'headers' => [
                    'Authorization' => (string)$s['token'],
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error($res) ) {
            $msg = 'MAX send: ' . $res->get_error_message();
            return $msg;
        }

        $code = wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);

        if ( $code < 200 || $code >= 300 ) {
            $msg = 'MAX send HTTP ' . $code . ': ' . $this->safe_snippet($raw);
            return $msg;
        }

        // Validate JSON if present (MAX may return JSON or empty)
        $trim = trim($raw);
        if ( $trim !== '' ) {
            $decoded = $this->json_decode_strict($trim);
            if ( ! is_array($decoded) ) {
                return 'MAX send: ответ не JSON (или битый JSON). Ответ: ' . $this->safe_snippet($raw);
            }
        }

        return true;
    }

    /* ===================== Helpers ===================== */

    private function json_decode_strict(string $raw): ?array {
        $raw = trim($raw);
        if ( $raw === '' ) return [];
        $data = json_decode($raw, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) return null;
        return is_array($data) ? $data : null;
    }

    private function safe_snippet(string $s, int $max = 900): string {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        if ( mb_strlen($s) <= $max ) return $s;
        return mb_substr($s, 0, $max) . '…';
    }

    private function log_error(int $post_id, string $message): void {
        $message = trim($message);
        if ( $message === '' ) return;

        $log = get_option(self::LOG_OPT_KEY, []);
        if ( ! is_array($log) ) $log = [];

        $log[] = [
            'time'    => current_time('Y-m-d H:i:s'),
            'post_id' => $post_id,
            'message' => $message,
        ];

        // Keep last 50
        $log = array_slice($log, -50);

        update_option(self::LOG_OPT_KEY, $log, false);
    }

    private function download_to_temp(string $url): ?string {

        $tmp = wp_tempnam($url);
        if ( ! $tmp ) return null;

        $r = wp_remote_get($url, [
            'timeout'  => 20,
            'headers'  => [
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ],
            'redirection' => 5,
        ]);

        if ( is_wp_error($r) ) {
            @unlink($tmp);
            return null;
        }

        $code = wp_remote_retrieve_response_code($r);
        $body = wp_remote_retrieve_body($r);

        if ( $code < 200 || $code >= 300 || ! $body ) {
            @unlink($tmp);
            return null;
        }

        file_put_contents($tmp, $body);
        return $tmp;
    }
}

/* ===================== Boot ===================== */

add_action('plugins_loaded', static function() {
    $p = KRV_MAX_Autopost::instance();
    $p->hooks();
});

register_activation_hook(__FILE__, ['KRV_MAX_Autopost', 'activate']);
register_deactivation_hook(__FILE__, ['KRV_MAX_Autopost', 'deactivate']);
