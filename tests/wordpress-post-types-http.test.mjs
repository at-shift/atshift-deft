import test, {after} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {randomUUID} from 'node:crypto';
import {JSDOM} from 'jsdom';
import {Client} from '@modelcontextprotocol/sdk/client/index.js';
import {StreamableHTTPClientTransport} from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const fixture = JSON.parse(readFileSync(process.env.SF_POST_TYPES_FIXTURE, 'utf8'));
const site = new URL(fixture.site);
assert.equal(site.hostname, '127.0.0.1');
const clients = [];
after(async () => { for (const client of clients) await client.close(); });
async function connect(key) {
  const endpoint = new URL(site); endpoint.searchParams.set('rest_route', key.route);
  const client = new Client({name:'deft-post-types-local-test',version:'1.0.0'}); clients.push(client);
  await client.connect(new StreamableHTTPClientTransport(endpoint,{requestInit:{headers:{Authorization:`Bearer ${key.token}`}}}));
  return client;
}
async function call(client,name,args={}) {
  const result=await client.callTool({name:'structured-fields-'+name,arguments:args});
  if (result.isError) return {error:true};
  return result.structuredContent ?? JSON.parse(result.content[0].text);
}
test('actual MCP discovery is available in both scopes without granting publication',async()=>{
  const draft=await connect(fixture.draftKey);
  const tools=(await draft.listTools()).tools;
  assert.ok(tools.some(t=>t.name==='structured-fields-list-post-types'));
  assert.ok(!tools.some(t=>t.name==='structured-fields-set-publication'));
  assert.ok((await call(draft,'list-post-types')).items.some(t=>t.postType==='page'));
  assert.equal((await call(draft,'context')).capabilities.publish,false);
  const url=new URL(site);url.searchParams.set('rest_route','/structured-fields/v1/post-types');
  assert.ok([401,403].includes((await fetch(url)).status));
  assert.equal((await fetch(url,{headers:{Authorization:`Bearer ${fixture.draftKey.token}`}})).status,403);
});
test('MCP publishes to page/custom type and schedules/cancels the selected target',async()=>{
  const client=await connect(fixture.publishingKey);
  const context=await call(client,'context'); assert.ok(context.publication.destinations.items.some(t=>t.postType==='sf_test_event'));
  const source=await call(client,'get-draft',{articleId:fixture.published.page.articleId});
  const schema=(await client.listTools()).tools.find(t=>t.name==='structured-fields-set-publication').inputSchema;
  assert.ok(schema.properties.postType);
  for(const postType of ['page','sf_test_event']){
    const article=structuredClone(source.snapshot.article);article.id='http-target-'+randomUUID();article.revision=1;
    for(const block of article.blocks) for(const key of Object.keys(block.values)) block.values[key]={value:'',alt:''};
    const saved=await call(client,'save-draft',{article,expectedVersion:0});assert.equal(saved.version,1);
    const body={articleId:article.id,expectedVersion:1,expectedPublicationVersion:0,requestId:randomUUID(),postType,timing:'now',publishAt:'',timeZone:''};
    const result=await call(client,'set-publication',body);assert.equal(result.state,'published');assert.equal(result.postType,postType);
    assert.equal((await fetch(result.url)).status,200);
    assert.equal((await call(client,'set-publication',body)).postId,result.postId);
    assert.equal((await call(client,'set-publication',{...body,postType:'post'})).error,true);
  }
  const article=structuredClone(context.composeTemplate.article);article.id='http-reserve-'+randomUUID();
  await call(client,'save-draft',{article,expectedVersion:0});
  const schedule=await call(client,'set-publication',{articleId:article.id,expectedVersion:1,expectedPublicationVersion:0,requestId:randomUUID(),
    postType:'page',timing:'scheduled',publishAt:new Date(Date.now()+3600000).toISOString().replace(/\.\d{3}Z$/,'+00:00'),timeZone:'UTC'});
  assert.equal(schedule.postType,'page');assert.equal(schedule.state,'scheduled');assert.ok(Number.isInteger(schedule.postId)&&schedule.postId>0);
  assert.notEqual((await fetch(new URL('?page_id='+schedule.postId,site))).status,200);
  assert.equal((await call(client,'cancel-publication',{articleId:article.id,expectedPublicationVersion:1})).state,'cancelled');
});
test('public post/page/custom pages deliver scoped styles, JS and frozen media over HTTP',async()=>{
  for(const result of Object.values(fixture.published)){
    const response=await fetch(result.url);assert.equal(response.status,200);
    const dom=new JSDOM(await response.text(),{url:result.url});const document=dom.window.document;
    assert.ok(document.querySelector('.sf-article'));
    assert.ok(document.querySelector(`[id="atshift-deft-publication-${result.postId}-inline-css"]`));
    assert.ok(document.querySelector('script[src*="image-zoom-v1.js"]'));
    assert.equal(document.querySelectorAll('[onerror],[onload]').length,0);
    for(const element of document.querySelectorAll('.sf-article img,.sf-article a')){
      const file=await fetch(element.src || element.href);assert.equal(file.status,200);
      assert.ok(/image\/png|application\/pdf/.test(file.headers.get('content-type')));
    }
    dom.window.close();
  }
});
