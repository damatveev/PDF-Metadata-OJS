# Совместимость

Дата актуализации: 22 сентября 2026.

Целевая линия: **OJS 3.5.x**.

Версия, на которой сверялась интеграция при разработке 1.0.1: **OJS 3.5.0-5**.

## Используемые модели OJS 3.5

Publication:

- multilingual title/subtitle/abstract;
- keywords;
- citationsRaw;
- DOI relation;
- OJS-specific sectionId, issueId, pages.

Author:

- multilingual givenName/familyName/preferredPublicName;
- email;
- ORCID;
- country;
- url;
- userGroupId;
- includeInBrowse.

Affiliation:

- multilingual name;
- ROR;
- author relation.

Workspace 1.0.1 хранит review draft в этой логике, но до отдельной команды импорта не создаёт соответствующие DB entities.

## Проверенные интеграционные точки

- GenericPlugin registration;
- plugin API controller registration;
- backend TemplateManager assets;
- context-scoped sections;
- author user groups;
- publication/author/affiliation repositories и validators для legacy apply mode;
- private file handling;
- OJS session + CSRF.

## Runtime

Требуется:

- PHP 8.2+ в рамках требований OJS 3.5;
- `mbstring`;
- DOM/libxml;
- Linux;
- Poppler;
- `prlimit`.

## Не заявляется

Плагин не заявляет совместимость с OJS 3.3, 3.4, будущей 3.6 без отдельного audit, а также с OMP/OPS.

При каждом maintenance upgrade OJS 3.5 необходимо повторно проверить:

1. plugin routing;
2. role middleware;
3. context and session behavior;
4. schema names;
5. repositories/validators;
6. TemplateManager backend asset loading.
