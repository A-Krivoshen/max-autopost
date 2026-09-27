=== MAX Autopost (Free) ===
Contributors: drslon
Tags: max, autopost, wordpress, bot, cron
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.11.9
License: MIT
License URI: https://opensource.org/license/mit/

Автопостинг из WordPress в MAX (platform-api2.max.ru): отправка в канал/группу и сразу в несколько chat_id, одно сообщение (картинка + текст + кнопка), очередь WP-Cron, retry, логи, обновления из GitHub Releases.

== Description ==

Плагин отправляет опубликованные записи WordPress в MAX одним сообщением.

Формат:
* IMAGE attachment (первый, если включено и есть картинка)
* TEXT (подпись) — поле `text`
* INLINE BUTTON (второй attachment, если включено)

Ключевой момент MAX:
* image должен быть первым attachment
* в image.payload нужен только `{token}` (из ответа upload `photos.*.token`) или `{url}`
* сырой JSON step2 (`{"photos":{...}}`) API2 не принимает — HTTP 400 proto.payload

== Installation ==
1) Загрузите папку `max-autopost` в `/wp-content/plugins/`
2) Активируйте плагин
3) MAX Autopost → Настройки → Token/Chat ID
4) Нажмите “Отправить тест”

== Changelog ==
= 1.11.9 =
* Fix: image.payload = {token}/{url}, не сырой JSON upload. Убирает HTTP 400 proto.payload «Can't deserialize body».
* Fallback: сначала plain С картинкой и кнопками; text-only — второй путь, в том числе для plain+вложения.
* Жирный заголовок через <b>, не <strong>.
* GET /chats больше не вызывается (метод удалён в июне 2026).
