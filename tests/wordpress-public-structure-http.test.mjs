import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const fixture = process.env.SF_PUBLIC_STRUCTURE_FIXTURE ? JSON.parse(readFileSync(process.env.SF_PUBLIC_STRUCTURE_FIXTURE, 'utf8')) : null;

test('anonymous structure endpoint exposes only public semantic content', { skip: !fixture }, async () => {
  for (const id of [fixture.postId, fixture.pageId, fixture.customId]) {
    const response = await fetch(`${fixture.url}/?rest_route=/structured-fields/v1/public-structure/${id}`);
    assert.equal(response.status, 200);
    assert.match(response.headers.get('cache-control'), /no-store/);
    const body = await response.json();
    assert.equal(body.format, 'atshift-deft-public');
    assert.equal(body.schemaVersion, 1);
    assert.ok(Array.isArray(body.content));
    assert.deepEqual(Object.keys(body).sort(), ['content', 'format', 'modifiedAt', 'postType', 'publishedAt', 'schemaVersion', 'title', 'url']);
  }
  for (const id of [...fixture.missingIds, 2147483647]) {
    const response = await fetch(`${fixture.url}/?rest_route=/structured-fields/v1/public-structure/${id}`);
    assert.equal(response.status, 404);
    assert.match(response.headers.get('cache-control'), /no-store/);
    assert.equal((await response.json()).code, 'sf_public_structure_missing');
  }
});

test('public HTML includes safe parseable JSON-LD and a discoverable structure link', { skip: !fixture }, async () => {
  for (const [id, type] of [[fixture.postId, 'BlogPosting'], [fixture.pageId, 'WebPage'], [fixture.customId, 'WebPage']]) {
    const projection = await (await fetch(`${fixture.url}/?rest_route=/structured-fields/v1/public-structure/${id}`)).json();
    const response = await fetch(projection.url);
    assert.equal(response.status, 200);
    const html = await response.text();
    const json = html.match(/<script type="application\/ld\+json" id="atshift-deft-json-ld">(.*?)<\/script>/s);
    assert.ok(json, `JSON-LD for ${type}`);
    const data = JSON.parse(json[1]);
    assert.equal(data['@context'], 'https://schema.org');
    assert.equal(data['@type'], type);
    assert.equal(data.url, projection.url);
    assert.equal('author' in data, false);
    assert.match(html, new RegExp(`public-structure(?:/|%2F)${id}`));
  }
});
