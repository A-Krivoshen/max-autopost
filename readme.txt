=== MAX Autopost ===
Contributors: drslon
Tags: max, autopost, messenger, wordpress, cron
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Production-ready автопостинг из WordPress в MAX (platform-api.max.ru).

== Description ==

Плагин отправляет опубликованные записи WordPress в канал MAX одним сообщением:

1) IMAGE (первым attachment, через 2-step upload, payload = полный JSON)
2) TEXT (заголовок + excerpt)
3) INLINE BUTTON (вторым attachment)

Есть очередь через WP-Cron (каждую минуту) и ручная отправка (row action + bulk).

== Installation ==

1. Скопируйте папку `max-autopost` в `wp-content/plugins/`
2. Активируйте плагин в админке WordPress.
3. Откройте меню `MAX Autopost` и заполните Token и Chat ID.
4. Опубликуйте пост — он попадет в очередь.

== Usage ==

- При публикации поста: ставится `_krv_max_status = queued`.
- WP-Cron раз в минуту отправляет до 5 постов со статусом `queued`.
- Статусы: `queued | processing | sent | error`.
- Ошибка сохраняется в `_krv_max_error` и в лог (последние 50 ошибок).

== Notes ==

- Для загрузки изображений требуется расширение PHP cURL.
- MAX игнорирует image attachment, если payload неполный или не первый — в плагине это учтено.
- Максимальная длина текста сообщения ограничена 3900 символами.

== Changelog ==

= 1.0.0 =
* Initial release.
