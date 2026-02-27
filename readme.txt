=== MAX Autopost (Free) ===
Contributors: drslon
Tags: max, autopost, wordpress, bot, cron
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.2.1
License: MIT
License URI: https://opensource.org/license/mit/

Автопостинг из WordPress в MAX (platform-api.max.ru): одно сообщение (картинка + текст + кнопка), очередь WP-Cron, retry, логи.

== Description ==

Плагин отправляет опубликованные записи WordPress в MAX одним сообщением.

Формат:
* IMAGE attachment (первый, если включено и есть картинка)
* TEXT (подпись) — поле `text`
* INLINE BUTTON (второй attachment, если включено)

Ключевой момент MAX:
* картинка игнорируется, если передать только token
* image должен быть первым attachment
* в image.payload должен быть ПОЛНЫЙ JSON, полученный после upload (ответ step2 может быть вложенным, например {"photos":{...}})

== Installation ==
1) Загрузите папку `max-autopost` в `/wp-content/plugins/`
2) Активируйте плагин
3) MAX Autopost → Настройки → Token/Chat ID
4) Нажмите “Отправить тест”

== Changelog ==
= 1.2.1 =
* Fix: upload step2 JSON может быть вложенным (например, {"photos":{...}}) — теперь принимаем и отправляем payload целиком.

= 1.2.0 =
* Кнопка “Читать” (inline_keyboard)
* Очередь WP-Cron, cron lock, retry/backoff
* Метабокс (disable + override + send now)
* Логи
