import test, {after} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {randomUUID} from 'node:crypto';
import {Client} from '@modelcontextprotocol/sdk/client/index.js';
import {StreamableHTTPClientTransport} from '@modelcontextprotocol/sdk/client/streamableHttp.js';

// Fixture is emitted only by the guarded disposable PHP suite, never a user key.
const fixture = JSON.parse(readFileSync(process.env.SF_APPEARANCE_FIXTURE, 'utf8'));
const site = new URL(fixture.publicUrl);
assert.equal(site.hostname, '127.0.0.1');
const clients = [];
after(async () => { for (const client of clients) await client.close(); });
async function connect(key) {
  const endpoint = new URL(site.origin);
  endpoint.searchParams.set('rest_route', key.route);
  const client = new Client({name: 'deft-appearance-local-test', version: '1.0.0'});
  clients.push(client);
  await client.connect(new StreamableHTTPClientTransport(endpoint, {requestInit: {headers: {Authorization: `Bearer ${key.token}`}}}));
  return client;
}
async function call(client, name, args = {}) {
  const result = await client.callTool({name: 'structured-fields-' + name, arguments: args});
  if (result.isError) return {error: true};
  return result.structuredContent ?? JSON.parse(result.content[0].text);
}

test('real MCP discovery, styled creation, private save and portable preview', async () => {
  const client = await connect(fixture.key);
  const tools = await client.listTools();
  const create = tools.tools.find(tool => tool.name === 'structured-fields-create-structure');
  assert.ok(create.inputSchema.properties.recipe.properties.appearance);
  assert.equal(tools.tools.some(tool => /theme|css|register-component/.test(tool.name)), false);
  const context = await call(client, 'context');
  assert.equal(context.capabilities.styleStructures, true);
  assert.equal(context.capabilities.manageShared, false);
  const source = context.sharedComponents.find(item => item.id === fixture.componentId);
  const recipe = structuredClone(source.structureRecipe);
  recipe.id = 'http-appearance-' + randomUUID(); recipe.key = 'http_appearance_' + randomUUID().replaceAll('-', '_');
  recipe.appearance.container.backgroundColor = '#f8f5ff';
  const body = {recipe, expectedVersion: 0, reuse: {catalogToken: context.catalogToken,
    considered: context.components.map(c => ({id: c.id, revision: c.revision})), reason: 'The catalog lacks this reusable light-violet announcement surface.'}};
  const component = await call(client, 'create-structure', body);
  assert.equal(component.scope, 'shared');
  assert.equal(component.component.fields.every(field => field.value === '' && field.alt === ''), true);
  const draft = structuredClone(context.composeTemplate);
  draft.article.title = 'HTTP styled draft';
  draft.article.blocks = [{id: 'stable-block', componentId: component.id, componentRevision: component.revision, variantId: 'default',
    values: Object.fromEntries(component.fields.map(field => [field.key, {
      value: field.type === 'link' ? 'https://example.invalid/registration' : 'HTTP fixture ' + field.key,
      alt: field.type === 'link' ? 'Registration' : '',
    }]))}];
  const saved = await call(client, 'save-draft', draft);
  assert.equal(saved.version, 1);
  const preview = await call(client, 'get-preview', {articleId: saved.articleId, expectedVersion: saved.version});
  assert.equal(preview.themeIncluded, false);
  assert.ok(preview.html.includes(component.component.css));
  assert.ok(preview.html.includes('background-color:#f8f5ff'));
  assert.equal((await call(client, 'create-structure', {...body, expectedVersion: 1})).error, true);
  const oldClient = await connect(fixture.oldKey);
  assert.equal((await call(oldClient, 'context')).capabilities.styleStructures, false);
  assert.equal((await call(oldClient, 'create-structure', body)).error, true);
  const otherClient = await connect(fixture.otherKey);
  const shared = (await call(otherClient, 'context')).sharedComponents;
  assert.ok(shared.some(c => c.id === component.id && c.appearance));
  assert.equal((await call(otherClient, 'get-draft', {articleId: saved.articleId})).error, true);
});

test('published HTTP page includes pinned component CSS without theme custom CSS', async () => {
  const response = await fetch(fixture.publicUrl);
  assert.equal(response.status, 200);
  const html = await response.text();
  assert.ok(html.includes(fixture.scopeA) && html.includes(fixture.scopeB));
  assert.ok(html.includes('sf-appearance-field-deadline'));
  assert.ok(html.includes('color:#8c2920'));
  assert.ok(!html.includes('id="wp-custom-css"'));
});
