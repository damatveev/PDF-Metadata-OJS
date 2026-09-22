/* Node-only static contract for the standalone issue workspace UI. */
const fs = require('node:fs');
const assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../js/pdfMetadata.js', 'utf8');

assert(source.includes('/workspace/upload'), 'workspace upload endpoint');
assert(source.includes('/workspace/extract'), 'workspace extract endpoint');
assert(source.includes('/workspace/save'), 'workspace save endpoint');
assert(source.includes('/workspace/remove'), 'workspace remove endpoint');
assert(source.includes('/workspace/export'), 'workspace export endpoint');
assert(source.includes('pdfmd-workspace-launcher'), 'backend workspace launcher');
assert(source.includes('issuePdf'), 'issue PDF input');
assert(source.includes('startPage') && source.includes('endPage'), 'page range controls');
assert(source.includes('readyForOjs'), 'review readiness state');
assert(!source.includes('innerHTML'), 'no innerHTML rendering');
assert(!source.includes('eval('), 'no eval');
assert(!source.includes('new Function('), 'no dynamic Function');

console.log('UI contract: standalone issue workspace endpoints, page ranges and safe rendering passed.');
