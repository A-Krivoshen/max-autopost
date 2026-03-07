=== MAX Autopost (Free) ===
Contributors: drslon
Tags: max, autopost, wordpress, bot, cron
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.5.1
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
= 1.5.1 =
* Галочки в настройках переведены на выбор типов записей (включая кастомные).
* Контакт поддержки визуально отделён от рекламного виджета.

= 1.5.0 =
* Добавлен выбор кастомных полей галочками в настройках плагина.
* На странице админки встроен рекламный виджет и блок контактов (aleksey@krivoshein.site).

= 1.4.1 =
* Улучшен автостарт очереди: при добавлении постов в очередь запускается cron worker (single event + loopback).
* Добавлен отдельный хук для запланированных публикаций (future -> publish).

= 1.4.0 =
* Улучшена админка очереди: действия «Отправить» и «В очередь» в таблице.
* Добавлена кнопка постановки в очередь всех опубликованных материалов.
* Поддержаны страницы и другие публичные типы записей.

= 1.3.0 =
* Новое: публикация кастомных полей (meta) в тексте сообщения по настраиваемому маппингу.

= 1.2.1 =
* Fix: upload step2 JSON может быть вложенным (например, {"photos":{...}}) — теперь принимаем и отправляем payload целиком.

= 1.2.0 =
* Кнопка “Читать” (inline_keyboard)
* Очередь WP-Cron, cron lock, retry/backoff
* Метабокс (disable + override + send now)
* Логи
