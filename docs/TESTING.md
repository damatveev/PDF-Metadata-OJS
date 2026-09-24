# Тестирование и приёмка

## Проверки 1.0.1.1 (24 сентября 2026)

```sh
php tests/adapter.php
php tests/workspace.php
node tests/ui.cjs
node tests/plugin-contract.cjs
python3 scripts/package.py
```

Для PHP необходимы mbstring и DOM. Автономные проверки: 36 parser/ticket assertions, 15 repository-double assertions, 17 filesystem workspace assertions. Node проверяет также API/locale contract плагина и исполняет оба UI с Vue/DOM/fetch doubles, включая запуск до DOMContentLoaded, сохранение сообщения об ошибке, newline keywords, edit/clear и CSRF. Сборщик проверяет ключи локализации, структуру и побайтовое содержимое архивов.

Это не испытание запущенного OJS: Linux Poppler или Ghostscript + prlimit, настоящая БД, роли, маршруты и браузер workflow должны пройти матрицу ниже на staging перед production.

## Минимальная локальная проверка

Из корня плагина:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check js/pdfMetadata.js
```

Для тестов PHP требуется `mbstring`.

## Smoke test сервера

Проверить:

```sh
command -v pdfinfo
command -v pdftotext
command -v gs
command -v prlimit
php -m | grep -E 'mbstring|dom|libxml'
```

`temp_dir` должен быть абсолютным, существующим, writable пользователем PHP и недоступным из web root.

## Acceptance matrix: issue workspace

| Scenario | Expected |
|---|---|
| Manager/editor opens backend | кнопка «Разметить PDF выпуска» доступна |
| Anonymous/author/reviewer | workspace API недоступен |
| Upload valid issue PDF | document metadata сохраняется, submission не создаётся |
| Upload non-PDF / encrypted / oversized file | отказ |
| Select valid page range | suggestions возвращаются только для диапазона |
| start < 1, end < start, end > document pages | validation error |
| Range > 80 pages | validation error |
| Scanned range | предупреждение noText; нужна ручная проверка/OCR |
| Save draft | появляется запись в списке размеченных статей |
| Missing title/section/author required review data | readyForOjs=false |
| Correct required review data | readyForOjs=true |
| Save several ranges | drafts сохраняются независимо и сортируются по страницам |
| Remove draft | удаляется только выбранная запись |
| Export JSON | экспорт содержит document + drafts |
| Clear workspace | PDF и drafts удаляются |
| Open another browser session | workspace текущей сессии не виден |
| Change journal/context | workspace другого context не доступен |
| Concurrent extraction | второй процесс получает busy/429 |
| Browser narrow width / keyboard | интерфейс остаётся доступным |

## Acceptance matrix: existing submission mode

Проверить отдельно:

- права на submission/publication;
- только latest queued publication;
- исходный submission PDF;
- CSRF;
- ticket expiry;
- metadata fingerprint conflict;
- PDF hash conflict;
- DOI already assigned;
- OJS validators для author/affiliation/publication;
- transaction rollback на ошибке.

## Важные ограничения 1.0.1

Не считать тест пройденным, если проверен только parser. Нужен staging OJS 3.5.x с реальными:

- DB;
- roles;
- sections;
- locales;
- plugin registry;
- PHP-FPM user;
- Poppler или Ghostscript;
- browser backend.

1.0.1 не тестирует и не выполняет автоматическое создание статей OJS из workspace — этой функции в версии нет.
