const fs = require('node:fs');
const assert = require('node:assert/strict');

const plugin = fs.readFileSync(__dirname + '/../PdfMetadataPlugin.php', 'utf8');

assert(
  plugin.includes("APIHandler::endpoints::plugin"),
  'plugin API hook is registered'
);
assert(
  /function \(string \$hookName, APIRouter \$apiRouter\): bool/.test(plugin),
  'OJS 3.5 plugin API hook receives APIRouter directly'
);
assert(
  plugin.includes("$apiRouter->registerPluginApiControllers([new MetadataController()]);"),
  'metadata controller is registered on APIRouter'
);
assert(
  !plugin.includes("$args[0]->registerPluginApiControllers"),
  'legacy/wrong array-style plugin API hook is not used'
);
assert(
  plugin.includes("Locale::registerPath($path);"),
  'plugin locale path is registered explicitly'
);
assert(
  plugin.includes("$this->ensureLocaleData();"),
  'locale registration is invoked before labels are resolved'
);

const controller = fs.readFileSync(__dirname + '/../classes/MetadataController.php', 'utf8');
assert(
  controller.includes("self::roleAuthorizer([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])"),
  'workspace API uses OJS 3.5 role middleware for site admin, manager and sub-editor'
);
assert(
  controller.includes("new UserRolesRequiredPolicy($request)") &&
  controller.includes("new ContextRequiredPolicy($request)") &&
  controller.includes("new ContextAccessPolicy($request, $roleAssignments)"),
  'workspace API mirrors OJS 3.5 context authorization stack'
);
assert(
  plugin.includes("Role::ROLE_ID_SITE_ADMIN") && plugin.includes("Application::SITE_CONTEXT_ID"),
  'site administrators receive the workspace UI'
);

console.log('Plugin contract: OJS 3.5 API router, authorization and locale registration passed.');
