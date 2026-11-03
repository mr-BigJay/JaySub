'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');

const script = readFileSync(resolve(__dirname, '../../public/assets/js/app.js'), 'utf8');
const start = script.indexOf("  const outboundPage = document.querySelector('.outbound-page');");
const end = script.indexOf('\n  }\n});', start);
assert.ok(start >= 0 && end > start, 'Outbounds handler must remain accessible');
const runOutboundBlock = new Function('document', 'window', 'fetch', script.slice(start, end + 4));

function fakeElement() {
  return {
    children: [],
    listeners: {},
    textContent: '',
    value: '',
    checked: false,
    hidden: false,
    appendChild(node) { this.children.push(node); },
    append(...nodes) { this.children.push(...nodes); },
    replaceChildren(...nodes) { this.children = [...nodes]; },
    addEventListener(name, fn) { this.listeners[name] = fn; },
    setAttribute() {},
  };
}

async function setup(options = {}) {
  const states = [];
  const calls = [];
  const listeners = {};
  const elements = {};
  for (const id of [
    'outbound-panel-list', 'outbound-select-all', 'outbound-editor-status',
    'outbound-list-wrap', 'outbound-template-body', 'outbound-template-cards',
    'outbound-subscription-body', 'outbound-subscription-cards',
    'outbound-action-results', 'outbound-action-summary', 'outbound-action-rows',
    'outbound-add-btn', 'outbound-safety-hint'
  ]) elements[id] = fakeElement();

  const panels = options.panels || [
    { id: 1, name: 'one', connection_status: 'connected' },
    { id: 2, name: 'two', connection_status: 'connected' },
  ];
  const responseRows = options.preview || panels.map((p) => ({
    panel_id: p.id, panel_name: p.name, status: 'ready', message: ''
  }));
  const appliedRows = panels.map((p) => ({
    panel_id: p.id, panel_name: p.name, status: 'success', ok: true,
  }));
  const fakeDocument = {
    querySelector(query) {
      return query === '.outbound-page'
        ? {
          getAttribute: () => 'csrf-test',
          querySelector: (child) => child === '.outbound-safety-hint'
            ? elements['outbound-safety-hint'] : null,
        }
        : null;
    },
    getElementById: (id) => elements[id] || null,
    createElement: fakeElement,
    addEventListener(name, fn) { listeners[name] = fn; },
  };
  const fakeWindow = {
    JaySubOutboundModal: {
      open() {},
      setState(state) { states.push(state); },
    },
    confirm: () => options.confirm !== false,
  };

  const fakeFetch = async (url, init = {}) => {
    calls.push({ url, init });
    if (url === '/admin/outbounds/api/panels') {
      return { ok: true, status: 200, json: async () => ({
        ok: true, panels, persist_enabled: options.persist === true,
        canary_panel_id: options.persist ? (options.canaryId || 1) : null,
      }) };
    }
    if (url === '/admin/outbounds/api/bulk/preview-json') {
      return { ok: true, status: 200, json: async () => ({
        ok: true,
        panels: responseRows.filter((r) =>
          JSON.parse(init.body).panel_ids.includes(r.panel_id)),
      }) };
    }
    if (url === '/admin/outbounds/api/bulk/apply-json') {
      return { ok: true, status: 200, json: async () => ({
        ok: true, results: (options.apply || appliedRows).filter((r) =>
          JSON.parse(init.body).panel_ids.includes(r.panel_id)),
      }) };
    }
    throw new Error('Unexpected request: ' + url);
  };

  runOutboundBlock(fakeDocument, fakeWindow, fakeFetch);
  await new Promise((done) => setImmediate(done));

  async function create(json = '{"tag":"proxy","protocol":"vless","settings":{}}') {
    await listeners['jaysub:outbound-create']({ detail: { json } });
  }
  return { create, calls, states, elements };
}

test('Create defaults to all panels and never writes while backend guard is off', async () => {
  const ctx = await setup({ persist: false });
  assert.equal(ctx.elements['outbound-select-all'].checked, true);
  assert.equal(ctx.elements['outbound-panel-list'].children.length, 2);
  assert.equal(ctx.elements['outbound-panel-list'].textContent, '');
  await ctx.create();
  const posts = ctx.calls.filter((c) => c.init.method === 'POST');
  assert.deepEqual(posts.map((p) => p.url), ['/admin/outbounds/api/bulk/preview-json']);
  assert.deepEqual(JSON.parse(posts[0].init.body).panel_ids, [1, 2]);
  assert.ok(ctx.states.some((s) => (s.message || '').includes('قفل')));
});

test('Create aborts all writes when any selected panel has tag conflict', async () => {
  const ctx = await setup({ persist: true, preview: [
    { panel_id: 1, panel_name: 'one', status: 'ready' },
    { panel_id: 2, panel_name: 'two', status: 'tag_conflict' },
  ] });
  // Operators can request a fleet preflight, but never write to a non-canary.
  ctx.elements['outbound-select-all'].checked = true;
  ctx.elements['outbound-select-all'].listeners.change();
  await ctx.create();
  assert.equal(ctx.calls.filter((c) => c.url.endsWith('/apply-json')).length, 0);
  assert.ok(ctx.states.some((s) => (s.message || '').includes('هیچ اوتباندی ایجاد نشد')));
});

test('Create calls guarded apply only after clean preview and confirmation', async () => {
  const ctx = await setup({ persist: true, confirm: true });
  await ctx.create();
  const posts = ctx.calls.filter((c) => c.init.method === 'POST');
  assert.deepEqual(posts.map((p) => p.url), [
    '/admin/outbounds/api/bulk/preview-json', '/admin/outbounds/api/bulk/apply-json'
  ]);
  assert.ok(ctx.states.some((s) => s.close === true));
});

test('Close/cancel confirmation prevents applying to panels', async () => {
  const ctx = await setup({ persist: true, confirm: false });
  await ctx.create();
  assert.equal(ctx.calls.filter((c) => c.url.endsWith('/apply-json')).length, 0);
});

test('Canary auto-selects only its approved target', async () => {
  const ctx = await setup({ persist: true, canaryId: 2 });
  assert.equal(ctx.elements['outbound-select-all'].checked, false);
  assert.match(ctx.elements['outbound-safety-hint'].textContent, /#2/);
  await ctx.create();
  const posts = ctx.calls.filter((c) => c.init.method === 'POST');
  assert.deepEqual(JSON.parse(posts[0].init.body).panel_ids, [2]);
  assert.equal(posts.at(-1).url, '/admin/outbounds/api/bulk/apply-json');
});

test('Canary blocks all-panel apply even when someone selects every panel', async () => {
  const ctx = await setup({ persist: true });
  ctx.elements['outbound-select-all'].checked = true;
  ctx.elements['outbound-select-all'].listeners.change();
  await ctx.create();
  assert.equal(ctx.calls.filter((c) => c.url.endsWith('/apply-json')).length, 0);
  assert.ok(ctx.states.some((s) => (s.message || '').includes('فقط برای یک پنل آزمایشی')));
});
