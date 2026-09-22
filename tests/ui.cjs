/* Node-only contract test. Does not claim to replace a real OJS browser test. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let component, extension;
const watchers = [];
const sandbox = {
  window: {pdfMetadataConfig: {api: '/index.php/journal/api/v1/pdf-metadata', csrf: 'test', labels: {}}},
  pkp: {modules: {vue: {
    h: (tag, props, children) => ({tag, props: children === undefined ? {} : props, children: children === undefined ? props : children}),
    ref: (value) => ({value}), watch: (getter, callback) => watchers.push(callback), onBeforeUnmount: () => {},
  }}, registry: {registerComponent: (name, value) => {component = value;}, storeExtend: (name, callback) => {
    assert.equal(name, 'workflow'); callback({store: {extender: {extendFn: (name, fn) => {assert.equal(name, 'getPrimaryItems'); extension = fn;}}}});
  }}}, AbortController,
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../js/pdfMetadata.js', 'utf8'), sandbox);
const base = [{component: 'Existing'}];
assert.equal(extension(base, {}), base);
assert.equal(extension(base, {submission: {}, selectedPublication: {}, permissions: {canEditPublication: false}}), base);
const args = {submission: {id: 5}, selectedPublication: {id: 7}, permissions: {canEditPublication: true}, selectedMenuState: {primaryMenuItem: 'publication'}};
const extended = extension(base, args);
assert.equal(extended.length, 2);
assert.equal(extended[1].props.submissionId, 5);
assert.equal(extended[1].props.publicationId, 7);
assert.equal(base.length, 1);
const render = component.setup({submissionId: 5, publicationId: 7});
let tree = render();
assert.equal(tree.tag, 'section');
assert.equal(tree.props['aria-label'], 'name');
assert.equal(watchers.length, 1);
watchers[0]();
tree = render();
assert.equal(tree.props['aria-busy'], false);
assert(!fs.readFileSync(__dirname + '/../js/pdfMetadata.js', 'utf8').includes('innerHTML'));
console.log('UI contract: registration, permissions, placement, IDs, reset and safe rendering passed.');
