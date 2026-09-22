# Архитектура и API

## Назначение

В 1.0.1 существуют два независимых сценария.

### 1. Standalone issue workspace

Основной сценарий. Редактор загружает полный PDF выпуска отдельно от Submission, размечает статьи по диапазонам страниц и сохраняет проверяемые черновики в структуре, близкой к OJS 3.5.

Workspace **не создаёт submission и не пишет publication metadata в БД OJS**.

### 2. Existing submission metadata

Совместимый режим ранней версии: извлечение из исходного PDF существующего submission и применение явно выбранных полей после проверки ticket/fingerprint.

## Регистрация

`PdfMetadataPlugin` — `PKP\plugins\GenericPlugin`.

Используются:

- `APIHandler::endpoints::plugin` для API controller;
- `TemplateManager::display` для backend JS/CSS;
- OJS context, roles, sections и author user groups через штатные repositories/models.

Плагин не хардкодит ID журнала, раздела, выпуска или ролей.

## Workspace API

База:

```text
/index.php/{journal}/api/v1/pdf-metadata/workspace
```

Маршруты:

| Method | Path | Purpose |
|---|---|---|
| GET | `/workspace` | состояние workspace, locales, sections, author groups |
| POST | `/workspace/upload` | загрузить полный PDF выпуска |
| POST | `/workspace/extract` | извлечь диапазон startPage/endPage |
| POST | `/workspace/save` | сохранить проверенный OJS draft |
| POST | `/workspace/remove` | удалить один draft |
| POST | `/workspace/clear` | удалить PDF и drafts текущего workspace |
| GET | `/workspace/export` | экспортировать review JSON |

Workspace идентифицируется комбинацией:

- contextId;
- userId;
- session token.

Данные хранятся в приватном `pdf_metadata.temp_dir`, а не в web root.

## Review draft

Черновик хранит:

```text
id
startPage
endPage
locale
publication
authors[]
unassignedAffiliations[]
readyForOjs
reviewedAt
```

`publication` использует имена полей OJS 3.5 там, где это возможно: `sectionId`, `pages`, `title`, `subtitle`, `abstract`, `keywords`, `citationsRaw`, DOI candidate.

Авторы и аффилиации сохраняются отдельными структурами, чтобы следующий этап мог создавать реальные OJS entities через repositories и validators.

## Извлечение диапазона

`PdfExtractor::extractRange()`:

1. проверяет PDF через `pdfinfo`;
2. проверяет число страниц и шифрование;
3. запускает `pdftotext -f start -l end`;
4. передаёт текст в `MetadataParser`;
5. возвращает suggestions + text + warnings.

Для полного выпуска XMP/Info документа **не используются как article metadata**, поскольку обычно описывают выпуск целиком.

## Безопасность

- backend roles: manager / sub-editor;
- cookie session only;
- CSRF для POST;
- private `temp_dir`;
- PDF signature `%PDF-`;
- максимальный issue PDF: 150 MiB;
- максимум 5000 страниц документа;
- максимум 80 страниц одной статьи;
- максимум 200 drafts;
- Poppler без shell;
- `prlimit`, timeout и ограничение stdout;
- JSON-файлы workspace записываются атомарно через temporary file + rename;
- workspace PDF проверяется SHA-256 перед последующими операциями.

## Existing submission API

Совместимый API:

```text
/index.php/{journal}/api/v1/pdf-metadata/{submissionId}/publications/{publicationId}
```

- GET — current/files/locales/groups;
- POST `/extract` — suggestions + ticket;
- POST `/apply` — явное применение выбранных полей.

Этот путь использует штатные OJS repositories/validators, HMAC ticket, fingerprint текущих metadata и повторную проверку PDF перед записью.

## Следующий этап

Планируемый этап после 1.0.1:

```text
review draft
→ create OJS submission/publication
→ create authors/affiliations
→ assign issue/section/pages
→ create article PDF galley from page range
→ final editor review
```

Создание и публикация должны оставаться отдельными действиями.
