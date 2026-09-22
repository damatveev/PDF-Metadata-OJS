# Проверенная база совместимости

Дата проверки: 22 сентября 2026.

В исходной рабочей папке не было репозитория OJS, установленных PHP/OJS или файлов плагина. `sources/` не изменялся. Для проверки отдельно получены официальные исходники `pkp/ojs`, ветка `stable-3_5_0`, и закреплённые submodules. Файлы ядра не изменялись.

| Компонент | Зафиксированное состояние |
|---|---|
| OJS | `769450f2d4048da1f5bcf7f9537f51f7c2c2234b` |
| Версия из dbscripts/xml/version.xml | `3.5.0.5`, дата `2026-06-30` |
| lib/pkp | `6acb1be2eb8bde98545de595c3975861e923561e` |
| lib/ui-library | `1a7a47504c4f8b78f423cdfd16c55c0fcf01caca` |
| Автономные PHP-тесты | PHP CLI 8.3.35 на Windows, mbstring/DOM |

Это HEAD ветки, а не утверждение, что checkout совпадает с дистрибутивом release tag. Проверка каждой устанавливаемой сборки 3.5.x на staging обязательна; не заявляется проверенный запуск всех maintenance-релизов.

## Проверенные точки интеграции

- [APIRouter](https://github.com/pkp/pkp-lib/blob/6acb1be2eb8bde98545de595c3975861e923561e/classes/core/APIRouter.php): hook `APIHandler::endpoints::plugin`, `registerPluginApiControllers`.
- [PKPSubmissionController](https://github.com/pkp/pkp-lib/blob/6acb1be2eb8bde98545de595c3975861e923561e/api/v1/submissions/PKPSubmissionController.php): Laravel routes, role middleware, сигнатура authorize, валидаторы и событие MetadataChanged.
- [PublicationWritePolicy](https://github.com/pkp/pkp-lib/blob/6acb1be2eb8bde98545de595c3975861e923561e/classes/security/authorization/PublicationWritePolicy.php): доступ к publication/submission, stage roles, право записи.
- [Publication DAO](https://github.com/pkp/pkp-lib/blob/6acb1be2eb8bde98545de595c3975861e923561e/classes/publication/DAO.php): ленивый Stringable citationsRaw и сохранение цитат.
- [Author Repository](https://github.com/pkp/pkp-lib/blob/6acb1be2eb8bde98545de595c3975861e923561e/classes/author/Repository.php): add/edit/validate, запрет обычной записи ORCID.
- [Affiliation Repository](https://github.com/pkp/pkp-lib/blob/6acb1be2eb8bde98545de595c3975861e923561e/classes/affiliation/Repository.php): отдельные сущности аффилиаций и требование authorId.
- [PKPFileService](https://github.com/pkp/pkp-lib/blob/6acb1be2eb8bde98545de595c3975861e923561e/classes/services/PKPFileService.php): fileId → storage object, Flysystem readStream.
- [PKPTemplateManager](https://github.com/pkp/pkp-lib/blob/6acb1be2eb8bde98545de595c3975861e923561e/classes/template/PKPTemplateManager.php): TemplateManager::display, `pkpApp`, inline JavaScript.
- [Workflow store](https://github.com/pkp/ui-library/blob/1a7a47504c4f8b78f423cdfd16c55c0fcf01caca/src/pages/workflow/workflowStore.js): getPrimaryItems, submission/selectedPublication/permissions.
- [UI plugin guide](https://github.com/pkp/ui-library/blob/1a7a47504c4f8b78f423cdfd16c55c0fcf01caca/src/docs/guide/Plugins/Plugins.mdx): registry и storeExtend.

`version.xml` проверен по DTD из этого checkout. Синтаксис всех PHP-файлов и JavaScript проверен. При обновлении OJS повторите сравнение этих точек и интеграционные сценарии. Плагин не предназначен для OJS 3.3, 3.4, 3.6, OMP или OPS.
