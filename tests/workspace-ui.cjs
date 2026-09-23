/* Executable DOM/fetch contract, independent of OJS and third-party packages. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let ready;
class Element {
  nodeType = 1; children = []; events = {}; value = ''; textContent = ''; disabled = false;
  constructor(tag) { this.tag = tag; this.className = ''; this.classList = {add: c => this.className += ' ' + c, remove: () => {}}; }
  setAttribute(k, v) { this[k] = v; if (k === 'class') this.className = v; }
  addEventListener(k, v) { this.events[k] = v; }
  append(...items) { items.forEach(x => { x.parent = this; this.children.push(x); }); }
  prepend(x) { x.parent = this; this.children.unshift(x); }
  replaceChildren(...items) { this.children = []; this.append(...items); }
  remove() { this.parent.children = this.parent.children.filter(x => x !== this); }
  focus() { document.activeElement = this; }
  querySelectorAll(selector) {
    const matches = n => selector.split(',').some(s => s.startsWith('.') ? n.className.split(' ').includes(s.slice(1)) : n.tag === s);
    return this.children.flatMap(n => [...(matches(n) ? [n] : []), ...n.querySelectorAll(selector)]);
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0]; }
}
const document = {readyState: 'loading', body: null, addEventListener: (name, fn) => { assert.equal(name, 'DOMContentLoaded'); ready = fn; },
  createElement: tag => new Element(tag), createTextNode: text => Object.assign(new Element('#text'), {textContent: text}),
  querySelector: selector => document.body?.querySelector(selector)};
let fail = true;
const calls = [];
const descriptor = {document: {fileName: 'issue.pdf', pages: 20}, articles: [], locales: ['en'], primaryLocale: 'en', sections: [{id: 9, title: 'Articles'}], groups: [{id: 3, name: 'Author'}]};
const draft = {startPage: 1, endPage: 11, locale: 'en', publication: {title: {en: 'Test'}, keywords: {en: [{name: 'one'}, {name: 'two'}]}, abstract: {en: ''}}, authors: []};
const sandbox = {window: {pdfMetadataConfig: {api: '/api/pdf-metadata', csrf: 'token'}, confirm: () => true}, document, FormData, structuredClone,
  fetch: async (url, options) => {
    calls.push({url, options});
    return {ok: !fail, json: async () => fail ? {message: 'Server failure'} : url.endsWith('/extract') ? {draft: structuredClone(draft)} : url.endsWith('/save') ? {articles: [{...draft, id: '1234567890abcdef'}]} : structuredClone(descriptor)};
  }};
const source = fs.readFileSync(__dirname + '/../js/pdfMetadata.js', 'utf8');
assert(!source.includes('innerHTML'));
vm.runInNewContext(source, sandbox);
assert.equal(document.body, null, 'head execution waits for DOM');
document.body = new Element('body'); ready();
const findButton = label => document.body.querySelectorAll('button').find(b => b.textContent === label);
(async () => {
  await findButton('openWorkspace').events.click();
  const status = document.body.querySelector('.pdfmd-workspace-status');
  assert.equal(status.textContent, 'Server failure', 'error survives finally');
  fail = false;
  await findButton('openWorkspace').events.click();
  await findButton('extractRange').events.click();
  const keywords = document.body.querySelectorAll('textarea').find(e => e.value === 'one\ntwo');
  assert(keywords, 'keywords use actual newlines');
  keywords.value = 'alpha\nbeta';
  await findButton('saveReview').events.click();
  assert.equal(status.textContent, 'articleSaved', 'success survives finally');
  const save = calls.find(c => c.url.endsWith('/save'));
  assert.deepEqual(JSON.parse(save.options.body).article.publication.keywords.en.map(x => x.name), ['alpha', 'beta']);
  assert.equal(save.options.headers['X-CSRF-Token'], 'token');
  assert.equal(save.options.credentials, 'same-origin');
  assert(findButton('edit'), 'saved drafts can be edited');
  findButton('edit').events.click();
  await findButton('clearWorkspace').events.click();
  assert(calls.some(c => c.url.endsWith('/clear') && c.options.method === 'POST'));
  assert(calls.every(c => c.url.startsWith('/api/pdf-metadata/workspace')));
  console.log('Workspace UI: DOM readiness, errors, extraction, newlines, save, edit, clear and CSRF passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
