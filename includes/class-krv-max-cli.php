<?php
/**
 * WP-CLI commands for MAX Autopost.
 *
 * Registered only from max-autopost.php when WP_CLI is true.
 * There is no method named list: "queue list" is its own command.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('KRV_MAX_CLI', false)) {
final class KRV_MAX_CLI {
    public static function register(): void {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }
        WP_CLI::add_command('max-autopost status', [__CLASS__, 'status']);
        WP_CLI::add_command('max-autopost send', [__CLASS__, 'send']);
        WP_CLI::add_command('max-autopost worker', [__CLASS__, 'worker']);
        WP_CLI::add_command('max-autopost queue list', [__CLASS__, 'queue_list']);
        WP_CLI::add_command('max-autopost queue run', [__CLASS__, 'queue_run']);
    }

    /**
     * Статус плагина, очереди и cron. Token и chat_id маскируются.
     *
     * ## EXAMPLES
     *
     *     wp max-autopost status
     */
    public static function status(): void {
        $row = KRV_MAX_Autopost::cli_status();
        foreach ($row as $key => $value) {
            WP_CLI::log($key . ': ' . $value);
        }
    }

    /**
     * Отправить один опубликованный пост, минуя выключатель воркера.
     *
     * Повторный запуск без --force не шлёт sent и partial_success.
     * Код выхода 0, если пост отправлен или пропущен (уже отправлен / занят / dry-run).
     * Код выхода 1 при ошибке или HTTP 429.
     *
     * ## OPTIONS
     *
     * <id>
     * : ID записи.
     *
     * [--force]
     * : Отправить снова, даже если статус sent или partial_success.
     *
     * [--dry-run]
     * : Ничего не отправлять и не менять мету.
     *
     * ## EXAMPLES
     *
     *     wp max-autopost send 12223
     *     wp max-autopost send 12223 --dry-run
     *     wp max-autopost send 12223 --force
     *
     * @param string[] $args
     * @param array<string,mixed> $assoc_args
     */
    public static function send(array $args, array $assoc_args): void {
        $post_id = isset($args[0]) ? (int) $args[0] : 0;
        if ($post_id <= 0) {
            WP_CLI::log('0 error not_found');
            WP_CLI::halt(1);
        }

        $res = KRV_MAX_Autopost::send_post_now($post_id, [
            'force' => !empty($assoc_args['force']),
            'dry_run' => !empty($assoc_args['dry-run']),
        ]);

        $status = (string) ($res['status'] ?? 'error');
        $reason = (string) ($res['reason'] ?? $status);
        if ($status === 'success') {
            $status = 'sent';
            $reason = 'sent';
        }
        if ($reason === '') {
            $reason = $status !== '' ? $status : 'error';
        }

        WP_CLI::log($post_id . ' ' . $status . ' ' . $reason);
        if ($status === 'error' || $status === 'rate_limited') {
            WP_CLI::halt(1);
        }
    }

    /**
     * Включить или выключить автоворкер.
     *
     * ## OPTIONS
     *
     * <enable|disable>
     * : enable включает воркер, disable выключает.
     *
     * ## EXAMPLES
     *
     *     wp max-autopost worker enable
     *     wp max-autopost worker disable
     *
     * @param string[] $args
     */
    public static function worker(array $args): void {
        $cmd = isset($args[0]) ? (string) $args[0] : '';
        if ($cmd === 'enable') {
            KRV_MAX_Autopost::set_worker_enabled(true);
            WP_CLI::log('worker on');
            return;
        }
        if ($cmd === 'disable') {
            KRV_MAX_Autopost::set_worker_enabled(false);
            WP_CLI::log('worker off');
            return;
        }
        WP_CLI::log('worker error usage');
        WP_CLI::halt(1);
    }

    /**
     * Список записей очереди.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : queued, error, partial_success, sent или all. По умолчанию queued.
     *
     * [--limit=<n>]
     * : Сколько строк. По умолчанию 50, максимум 500.
     *
     * [--format=<format>]
     * : table, json или csv. По умолчанию table.
     *
     * ## EXAMPLES
     *
     *     wp max-autopost queue list --format=json --limit=20
     *
     * @param string[] $args
     * @param array<string,mixed> $assoc_args
     */
    public static function queue_list(array $args, array $assoc_args): void {
        $status = isset($assoc_args['status']) ? (string) $assoc_args['status'] : 'queued';
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 50;
        $format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'table';
        if (!in_array($format, ['table', 'json', 'csv'], true)) {
            $format = 'table';
        }
        $rows = KRV_MAX_Autopost::cli_queue_rows($status, $limit);
        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['ID', 'title', 'status', 'queued_at', 'next_try', 'attempts', 'error']
        );
    }

    /**
     * Разобрать очередь, игнорируя выключатель воркера.
     *
     * --limit ограничивает пачку (максимум 50). Без --limit берётся настройка «Постов за запуск».
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Сколько постов за этот запуск.
     *
     * ## EXAMPLES
     *
     *     wp max-autopost queue run
     *     wp max-autopost queue run --limit=5
     *
     * @param string[] $args
     * @param array<string,mixed> $assoc_args
     */
    public static function queue_run(array $args, array $assoc_args): void {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 0;
        KRV_MAX_Autopost::process_queue(true, $limit, 0);
        WP_CLI::log('queue run done');
    }
}
}
