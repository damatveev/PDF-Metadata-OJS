/* SPDX-License-Identifier: GPL-3.0-or-later
 * No bundled Vue: use the exact Vue instance supplied by OJS 3.5.
 * Render functions avoid runtime template compilation and unsafe HTML insertion.
 */
(() => {
  'use strict';
  const {h, ref, watch, onBeforeUnmount} = pkp.modules.vue;
  const config = window.pdfMetadataConfig;
  if (!config) return;
  const t = (key) => config.labels[key] || key;
  const local = (value, locale) => typeof value === 'object' && value !== null ? value[locale] || '' : value || '';
  const component = {
    name: 'PdfMetadataPanel',
    props: {submissionId: {type: Number, required: true}, publicationId: {type: Number, required: true}},
    setup(props) {
      const opened = ref(false), busy = ref(false), message = ref(''), details = ref('');
      const state = ref(null), result = ref(null), locale = ref(''), fileId = ref('');
      const values = ref({}), selected = ref({}), authors = ref([]);
      let active = null, generation = 0;
      const reset = () => {
        generation++; active?.abort(); active = null; busy.value = false;
        state.value = null; result.value = null; values.value = {}; selected.value = {};
        authors.value = []; message.value = ''; details.value = ''; opened.value = false;
      };
      watch(() => [props.submissionId, props.publicationId], reset);
      onBeforeUnmount(reset);
      const url = (op) => `${config.api}/${props.submissionId}/publications/${props.publicationId}${op ? '/' + op : ''}`;
      async function request(op, body) {
        const epoch = generation;
        const response = await fetch(url(op), {
          method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
          headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf},
          body: body ? JSON.stringify(body) : undefined, signal: active.signal,
        });
        let data;
        try { data = await response.json(); } catch (_) { throw new Error(t('error')); }
        if (epoch !== generation) {
          const error = new Error('Stale response'); error.name = 'AbortError'; throw error;
        }
        if (!response.ok) {
          const error = new Error(data.message || t(data.error) || t('error'));
          error.details = data.details;
          throw error;
        }
        return data;
      }
      async function run(fn) {
        if (busy.value) return;
        const epoch = generation;
        active = new AbortController(); busy.value = true; message.value = ''; details.value = '';
        try { await fn(); }
        catch (error) {
          if (epoch === generation && error.name !== 'AbortError') {
            message.value = error.message;
            details.value = error.details ? JSON.stringify(error.details, null, 2) : '';
          }
        } finally { if (epoch === generation) { busy.value = false; active = null; } }
      }
      async function load() {
        await run(async () => {
          const data = await request('');
          state.value = data; locale.value = data.current.primaryLocale;
          fileId.value = data.files[0]?.id || ''; opened.value = true;
          result.value = null;
        });
      }
      async function extract() {
        await run(async () => {
          result.value = null; selected.value = {}; authors.value = [];
          const data = await request('extract', {fileId: Number(fileId.value)});
          result.value = data; state.value.current = data.current;
          const next = {};
          for (const key of ['title', 'abstract', 'keywords', 'doi', 'references']) {
            const value = data.fields[key]?.value;
            next[key] = Array.isArray(value) ? value.join('\n') : value || '';
          }
          values.value = next;
          authors.value = (data.fields.authors?.value || []).map((author) => newAuthor(author.name));
        });
      }
      function newAuthor(name = '') {
        return {name, target: '', givenName: '', familyName: '', email: '', group: state.value?.groups[0]?.id || '', affiliations: '', onlyAffiliations: false};
      }
      function mapAuthor(row, target) {
        row.target = target;
        if (target && target !== 'new') {
          const author = state.value.current.authors.find((a) => a.id === Number(target));
          const primary = state.value.current.primaryLocale;
          row.givenName = local(author.givenName, primary); row.familyName = local(author.familyName, primary);
          row.email = author.email || '';
        }
      }
      async function apply() {
        await run(async () => {
          const fields = {};
          for (const key of Object.keys(selected.value)) {
            if (selected.value[key]) fields[key] = key === 'keywords' ? values.value[key].split('\n').map((v) => v.trim()).filter(Boolean) : values.value[key];
          }
          const operations = authors.value.filter((a) => a.target).map((row) => {
            const affiliations = row.affiliations.split('\n').map((v) => v.trim()).filter(Boolean);
            if (row.target !== 'new' && row.onlyAffiliations) return {action: 'affiliations', id: Number(row.target), affiliations};
            return {action: row.target === 'new' ? 'add' : 'update',
              ...(row.target === 'new' ? {userGroupId: Number(row.group)} : {id: Number(row.target)}),
              givenName: row.givenName, familyName: row.familyName, email: row.email, affiliations};
          });
          await request('apply', {ticket: result.value.ticket, locale: locale.value, fields, authors: operations});
          result.value = null; message.value = t('saved');
          // Avoid forcibly reloading a workflow containing unsaved work in other forms.
          state.value = await request('');
        });
      }
      const button = (text, action, disabled = false) => h('button', {type: 'button', class: 'pdfmd-button', disabled: busy.value || disabled, onClick: action}, text);
      const input = (label, value, update, type = 'text') => h('label', {class: 'pdfmd-input'}, [label,
        h('input', {type, value, disabled: busy.value, onInput: (event) => update(event.target.value)})]);
      const textarea = (label, value, update, rows = 3) => h('label', {class: 'pdfmd-input'}, [label,
        h('textarea', {value, rows, disabled: busy.value, onInput: (event) => update(event.target.value)})]);
      const select = (label, value, options, update) => h('label', {class: 'pdfmd-input'}, [label,
        h('select', {value, disabled: busy.value, onChange: (event) => update(event.target.value)}, options.map(([id, name]) => h('option', {value: id}, name)))]);
      return () => h('section', {class: 'pdfmd-panel', 'aria-label': t('name'), 'aria-busy': busy.value}, [
        h('h2', t('name')), h('p', t('description')),
        !opened.value ? button(t('load'), load) : null,
        message.value ? h('p', {role: 'status', class: 'pdfmd-status'}, message.value) : null,
        details.value ? h('pre', details.value) : null,
        busy.value ? h('p', {role: 'status'}, t('busy')) : null,
        opened.value && state.value ? h('div', [
          h('div', {class: 'pdfmd-controls'}, [
            select(t('file'), fileId.value, state.value.files.map((f) => [f.id, `${f.name} (#${f.id})`]), (v) => {fileId.value = v; result.value = null;}),
            select(t('locale'), locale.value, state.value.locales.map((l) => [l, l]), (v) => {locale.value = v; selected.value = {};}),
            button(t('extract'), extract, !fileId.value),
          ]),
          !state.value.files.length ? h('p', t('empty')) : null,
          result.value ? h('div', [
            h('p', {class: 'pdfmd-notice'}, t('review')),
            ...result.value.warnings.map((warning) => h('p', {role: 'status'}, t(warning))),
            ...['title', 'abstract', 'keywords', 'doi', 'references'].map((key) => {
              const current = key === 'references' ? state.value.current.citationsRaw : local(state.value.current[key], locale.value);
              const candidate = result.value.fields[key];
              return h('fieldset', {class: 'pdfmd-field'}, [
                h('legend', t(key)),
                h('label', [h('input', {type: 'checkbox', disabled: busy.value || (key === 'doi' && !!state.value.current.doiId),
                  checked: !!selected.value[key], onChange: (e) => {selected.value[key] = e.target.checked;}}), ' ' + t('select')]),
                h('div', {class: 'pdfmd-compare'}, [
                  h('div', [h('strong', t('current')), h('pre', Array.isArray(current) ? current.join('\n') : current || '—')]),
                  textarea(t('proposed'), values.value[key], (v) => {values.value[key] = v;}, key === 'abstract' || key === 'references' ? 7 : 2),
                ]),
                candidate ? h('small', `${t('source')}: ${candidate.source}; ${t('confidence')}: ${candidate.confidence}`) : null,
                key === 'doi' && state.value.current.doiId ? h('p', t('doiExists')) : null,
              ]);
            }),
            h('h3', t('authors')), h('p', t('authorHelp')),
            h('details', [h('summary', t('current')), ...state.value.current.authors.map((a) => h('p', [
              `${local(a.givenName, state.value.current.primaryLocale)} ${local(a.familyName, state.value.current.primaryLocale)} (#${a.id}) — ${a.email || ''}`,
              h('br'), a.affiliations.map((aff) => local(aff.name, state.value.current.primaryLocale) || aff.ror).join('; '),
            ]))]),
            h('p', t('affiliationHelp')),
            ...(result.value.fields.affiliations?.value || []).map((name) => h('p', {class: 'pdfmd-suggestion'}, name)),
            ...authors.value.map((row, index) => h('fieldset', {key: index, class: 'pdfmd-field'}, [
              h('legend', row.name || `${t('authors')} ${index + 1}`),
              select(t('mapping'), row.target, [['', t('skip')], ['new', t('newAuthor')], ...state.value.current.authors.map((a) => [a.id, `${local(a.givenName, state.value.current.primaryLocale)} ${local(a.familyName, state.value.current.primaryLocale)} (#${a.id})`])], (value) => mapAuthor(row, value)),
              row.target ? h('div', [
                row.target !== 'new' ? h('label', [h('input', {type: 'checkbox', checked: row.onlyAffiliations, disabled: busy.value, onChange: (e) => {row.onlyAffiliations = e.target.checked;}}), t('affiliations')]) : null,
                !row.onlyAffiliations || row.target === 'new' ? h('div', {class: 'pdfmd-controls'}, [
                  input(t('givenName'), row.givenName, (v) => {row.givenName = v;}),
                  input(t('familyName'), row.familyName, (v) => {row.familyName = v;}),
                  input(t('email'), row.email, (v) => {row.email = v;}, 'email'),
                  row.target === 'new' ? select(t('group'), row.group, state.value.groups.map((g) => [g.id, g.name]), (v) => {row.group = v;}) : null,
                ]) : null,
                textarea(t('affiliations'), row.affiliations, (v) => {row.affiliations = v;}),
              ]) : null,
            ])),
            button(t('addAuthor'), () => authors.value.push(newAuthor())),
            h('details', [h('summary', t('text')), h('pre', result.value.text)]),
            h('div', {class: 'pdfmd-actions'}, [button(t('apply'), apply)]),
          ]) : null,
        ]) : null,
      ]);
    },
  };
  pkp.registry.registerComponent('PdfMetadataPanel', component);
  pkp.registry.storeExtend('workflow', ({store}) => {
    store.extender.extendFn('getPrimaryItems', (items, args) => {
      if (!args.submission || !args.selectedPublication || !args.permissions?.canEditPublication) return items;
      // Only inside the Publication section; server remains the authority for access.
      if (args.selectedMenuState?.primaryMenuItem !== 'publication') return items;
      return [...items, {component: 'PdfMetadataPanel', props: {
        submissionId: Number(args.submission.id), publicationId: Number(args.selectedPublication.id),
      }}];
    });
  });
})();
