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

console.log('Plugin contract: OJS 3.5 API router signature and locale registration passed.');
