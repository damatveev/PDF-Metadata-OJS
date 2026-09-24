# PDF Metadata for OJS 3.5.x

Текущая версия: **1.0.1.2**. Лицензия: GPL-3.0-or-later.

Готовые пакеты: [tar.gz](dist/pdfMetadata-1.0.1.2.tar.gz), [ZIP](dist/pdfMetadata-1.0.1.2.zip), [SHA256](dist/SHA256SUMS.txt).
Распакуйте каталог `pdfMetadata` в `plugins/generic/`, затем зарегистрируйте версию и включите плагин по инструкции ниже. Не устанавливайте поверх копии с несохранёнными изменениями.

В 1.0.1.2 исправлены API-маршрутизация OJS 3.5 и загрузка локализаций. В 1.0.1.1 была восстановлена панель **PDF Metadata** в **Submission → Publication**. Нажмите «Загрузить», выберите исходный PDF и язык, извлеките предложения, отметьте нужные поля и проверьте сопоставление авторов. «Применить» обновляет только выбранные поля текущей неопубликованной версии. После сохранения перезагрузите workflow. Существующий DOI не перезаписывается. Текст аннотации сохраняется как безопасный текст; форматирование PDF не переносится.

Этот репозиторий — единственный источник разработки плагина **PDF Metadata**. Плагин предназначен для OJS 3.5.x и позволяет редактору работать с PDF без обязательной подачи материала через стандартную форму Submission.

## Основной режим 1.0.1: полный PDF выпуска

Редактор открывает отдельный инструмент **«Разметить PDF выпуска»** в backend OJS и загружает один полный PDF номера журнала.

Рабочий процесс:

1. загрузить PDF выпуска;
2. указать диапазон PDF-страниц конкретной статьи;
3. извлечь предложения метаданных;
4. проверить и исправить поля в структуре OJS;
5. сохранить черновик статьи для последующего импорта;
6. повторить для остальных статей;
7. экспортировать проверенные черновики в JSON.

Версия 1.0.1 **не создаёт submission и не публикует статьи автоматически** из workspace. Это намеренно: сначала выполняется редакторская проверка разметки.

### Поля черновика OJS

Publication:

- locale
- sectionId
- issueId (зарезервировано для следующего этапа импорта)
- pages
- prefix
- title
- subtitle
- abstract
- keywords
- citationsRaw
- DOI-кандидат

Author:

- seq
- givenName
- familyName
- preferredPublicName
- email
- orcid
- country
- url
- userGroupId
- includeInBrowse
- affiliations

Affiliation:

- multilingual name
- ROR

Разделы журнала и доступные группы авторов берутся из текущего контекста OJS, без хардкода конкретного журнала.

## Дополнительный режим

Сохранена совместимость с ранней логикой работы внутри существующего submission: плагин может извлекать метаданные из исходного PDF уже существующего материала и применять выбранные изменения после явного подтверждения редактора.

Основной дальнейший сценарий разработки — **полный PDF выпуска → проверка → создание статей OJS**.

## Извлечение

Используются локальные серверные инструменты Poppler:

- `pdfinfo`
- `pdftotext`
- `prlimit`

PDF не отправляется в Crossref, GROBID, LLM или иные внешние сервисы.

Для полного выпуска XMP/PDF Info номера не используются как достоверные метаданные отдельной статьи. При разметке диапазона статья анализируется по тексту выбранных страниц. Имена авторов предлагаются консервативно и не разделяются автоматически на given/family name без редакторской проверки.

OCR в 1.0.1 не выполняется. Для сканов требуется PDF с текстовым слоем.

## Требования

- OJS 3.5.0-4 или новее в линии 3.5.x;
- PHP 8.2+;
- расширения OJS, включая `mbstring` и DOM/XML;
- Linux для обработки PDF;
- Poppler;
- util-linux / `prlimit`;
- закрытый writable-каталог вне web root.

Пример конфигурации `config.inc.php`:

```ini
[pdf_metadata]
temp_dir = /var/lib/ojs-pdf-metadata
secret = "REPLACE_WITH_A_RANDOM_SECRET_AT_LEAST_32_CHARACTERS"
pdfinfo = /usr/bin/pdfinfo
pdftotext = /usr/bin/pdftotext
prlimit = /usr/bin/prlimit
```

Каталог:

```sh
sudo install -d -m 0700 -o www-data -g www-data /var/lib/ojs-pdf-metadata
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

## Установка из Git

Клонируйте репозиторий непосредственно в каталог generic plugins:

```sh
cd /path/to/ojs/plugins/generic
git clone https://github.com/damatveev/PDF-Metadata-OJS.git pdfMetadata
```

Для уже установленной копии:

```sh
cd /path/to/ojs/plugins/generic/pdfMetadata
git switch main
git pull --ff-only origin main
```

Зарегистрировать версию плагина в OJS при первой установке или обновлении версии:

```sh
cd /path/to/ojs
php lib/pkp/tools/installPluginVersion.php plugins/generic/pdfMetadata/version.xml
```

После этого включите плагин в **Веб-сайт → Плагины → Установленные плагины → Общие плагины**.

## Безопасность workspace

- доступ только авторизованным редакционным ролям;
- CSRF-проверка для POST;
- workspace привязан к contextId, userId и текущей сессии;
- PDF хранится только в приватном `temp_dir`;
- максимальный размер полного выпуска — 150 МиБ;
- максимум 200 сохранённых черновиков статей на workspace;
- диапазон одной статьи — максимум 80 PDF-страниц;
- обработка Poppler запускается без shell и с resource limits;
- экспорт содержит только сохранённые редактором черновики;
- workspace не пишет metadata в OJS и не создаёт submission.

## Структура

```text
PDF-Metadata-OJS/
├── PdfMetadataPlugin.php
├── index.php
├── version.xml
├── classes/
│   ├── IssueWorkspace.php
│   ├── MetadataController.php
│   ├── MetadataParser.php
│   ├── MetadataService.php
│   ├── PdfExtractor.php
│   ├── SubmissionPdf.php
│   ├── Ticket.php
│   └── Failure.php
├── js/pdfMetadata.js
├── js/submissionMetadata.js
├── styles/pdfMetadata.css
├── locale/{en,ru,ru_RU}/locale.po
├── docs/
├── tests/
├── scripts/package.py
└── LICENSE
```

## Разработка

Все дальнейшие изменения выполняются **только в этом репозитории**. Интеграция в конкретную OJS-инсталляцию должна выполняться через checkout/subtree/deployment из этого репозитория, а не редактированием копии плагина внутри репозитория журнала.

Сборка без сторонних Python-пакетов: `python3 scripts/package.py`. Архивы содержат один верхний каталог `pdfMetadata/`; `.git`, исторические архивы и локальная конфигурация в них не попадают.

Workspace UI редактирует title, abstract, keywords, DOI, references, section, pages и строки авторов вида `имя | фамилия | email | организация`. Расширенные поля схемы зарезервированы для импорта; не все имеют отдельные элементы управления. Разметка выпуска экспортируется в JSON; импорт JSON в OJS в эту версию не входит.

Для PDF submission fallback идёт от XMP к PDF Info и эвристикам текста. Для диапазона выпуска используется только текст. Ненадёжные/отсутствующие данные оставляются для ручной проверки. Сканы требуют внешнего OCR. Настройте `upload_max_filesize`, `post_max_size` и лимит тела запроса веб-сервера для выбранного размера выпуска (лимит плагина 150 МиБ). Хранилище workspace не очищается при выходе из сессии: используйте «Очистить рабочее пространство»; администратор должен контролировать объём приватного каталога и удалять старые workspace только вне активных запросов.

См. также:

- [Архитектура](docs/ARCHITECTURE.md)
- [Тестирование](docs/TESTING.md)
- [Совместимость](docs/COMPATIBILITY.md)
- [История изменений](CHANGELOG.md)
