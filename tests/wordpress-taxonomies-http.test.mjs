import test, {after} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {randomUUID} from 'node:crypto';
import {Client} from '@modelcontextprotocol/sdk/client/index.js';
import {StreamableHTTPClientTransport} from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const fixture=JSON.parse(readFileSync(process.env.SF_TAXONOMIES_FIXTURE,'utf8'));
const site=new URL(fixture.site);assert.equal(site.hostname,'127.0.0.1');
const clients=[];
after(async()=>{for(const client of clients)await client.close();});
async function connect(key){
  const url=new URL(site);url.searchParams.set('rest_route',key.route);
  const client=new Client({name:'deft-taxonomy-local-qa',version:'1.0.0'});clients.push(client);
  await client.connect(new StreamableHTTPClientTransport(url,{requestInit:{headers:{Authorization:`Bearer ${key.token}`}}}));return client;
}
async function call(client,name,args={}){
  const result=await client.callTool({name:'structured-fields-'+name,arguments:args});
  if(result.isError)return {error:true};return result.structuredContent??JSON.parse(result.content[0].text);
}
async function draft(client){
  const context=await call(client,'context');const article=structuredClone(context.composeTemplate.article);
  article.id='taxonomy-http-'+randomUUID();article.title='MCP category and tag test';article.blocks=[];
  await call(client,'save-draft',{article,expectedVersion:0});return article.id;
}
function intent(id,taxonomies,type='post',now=true,version=0){return{articleId:id,expectedVersion:1,expectedPublicationVersion:version,requestId:randomUUID(),postType:type,
  taxonomies,timing:now?'now':'scheduled',publishAt:now?'':new Date(Date.now()+3600000).toISOString().replace(/\.\d{3}Z$/,'+00:00'),timeZone:now?'':'UTC'};}
const draftTools=['context','list-post-types','list-taxonomies','list-terms','list-drafts','get-draft','save-draft','trash-draft','restore-draft',
  'create-structure','list-media','prepare-file-transfer','get-preview'];
function assertTools(tools,publishing=false){
  const expected=[...draftTools,...(publishing?['list-publications','get-publication','set-publication','cancel-publication']:[])];
  assert.deepEqual(tools.map(t=>t.name).sort(),expected.map(n=>'structured-fields-'+n).sort());
}

test('both MCP scopes expose authenticated read-only taxonomy discovery',async()=>{
  const client=await connect(fixture.keys.draft);const tools=(await client.listTools()).tools;
  assertTools(tools);
  for(const name of ['list-taxonomies','list-terms'])assert.ok(tools.some(t=>t.name==='structured-fields-'+name));
  assert.ok(!tools.some(t=>t.name==='structured-fields-set-publication'));
  const taxa=await call(client,'list-taxonomies',{postType:'post'});
  assert.deepEqual(taxa.items.map(i=>i.taxonomy),['category','post_tag']);
  assert.equal(taxa.createsTerms,false);
  const terms=await call(client,'list-terms',{postType:'post',taxonomy:'category'});
  assert.ok(terms.items.some(t=>t.id===fixture.category));
  assert.equal((await call(client,'context')).capabilities.publish,false);
  for(const path of ['/post-types/post/taxonomies','/post-types/post/taxonomies/category/terms']){
    const url=new URL(site);url.searchParams.set('rest_route','/structured-fields/v1'+path);
    assert.ok([401,403].includes((await fetch(url)).status));
    assert.equal((await fetch(url,{headers:{Authorization:`Bearer ${fixture.keys.draft.token}`}})).status,403);
  }
});
test('MCP assigns exact categories and tags, with retry and owner-scoped results',async()=>{
  const client=await connect(fixture.keys.publish);assertTools((await client.listTools()).tools,true);
  const id=await draft(client);
  const selection=[{taxonomy:'category',termIds:[fixture.category,fixture.child]},{taxonomy:'post_tag',termIds:[fixture.tag]}];
  const body=intent(id,selection);const result=await call(client,'set-publication',body);
  assert.equal(result.state,'published');assert.deepEqual(result.taxonomies,selection);
  assert.equal(result.taxonomyDetails[0].terms.length,2);assert.equal((await fetch(result.url)).status,200);
  assert.equal((await call(client,'set-publication',body)).postId,result.postId);
  assert.equal((await call(client,'set-publication',{...body,taxonomies:[]})).error,true);
  assert.deepEqual((await call(client,'get-publication',{articleId:id})).taxonomies,selection);
});
test('MCP custom taxonomy reservations retain selection and reject cross-destination IDs',async()=>{
  const client=await connect(fixture.keys.publish);const id=await draft(client);
  const selection=[{taxonomy:'sf_event_topic',termIds:[fixture.event]}];
  assert.equal((await call(client,'set-publication',intent(id,selection,'post'))).error,true);
  const state=await call(client,'set-publication',intent(id,selection,'sf_test_event',false));
  assert.equal(state.state,'scheduled');assert.ok(Number.isInteger(state.postId)&&state.postId>0);assert.deepEqual(state.taxonomies,selection);
  assert.notEqual((await fetch(new URL('?p='+state.postId,site))).status,200);
  const update=await call(client,'set-publication',intent(id,state.taxonomies,'sf_test_event',false,1));
  assert.equal(update.version,2);assert.deepEqual(update.taxonomies,selection);
  assert.equal((await call(client,'cancel-publication',{articleId:id,expectedPublicationVersion:2})).state,'cancelled');
});
