import { test, after } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { randomUUID, createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";
import { installI18n } from "./wp-i18n-fixture.mjs";
import { CookieJar } from "tough-cookie";
import { JSDOM } from "jsdom";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

const origin = "http://127.0.0.1:8128", run = randomUUID();
const fixture = (action) => execFileSync("php", [fileURLToPath(new URL("./wordpress-users-fixture.php", import.meta.url)), action, run], {encoding:"utf8"});
const data = JSON.parse(fixture("create")), clients = [];
after(async () => { for (const c of clients) await c.close(); assert.match(fixture("cleanup"), /fixture-ok/); });
async function browser(user) {
  const jar = new CookieJar();
  async function request(url, options = {}) {
    const target = new URL(url, origin);
    const r = await fetch(target, {redirect:"manual", ...options, headers:{Cookie:await jar.getCookieString(target.href), ...options.headers}});
    for (const cookie of r.headers.getSetCookie()) await jar.setCookie(cookie, target.href);
    return r;
  }
  await request("/wp-login.php");
  assert.equal((await request("/wp-login.php", {method:"POST",body:new URLSearchParams({log:user.username,pwd:user.password,testcookie:"1"})})).status,302);
  const dom = new JSDOM(await (await request("/wp-admin/admin.php?page=structured-fields-connections")).text());
  const line = [...dom.window.document.scripts].flatMap(s=>s.textContent.split("\n")).map(s=>s.trim()).find(s=>s.startsWith("window.atshiftDeftWordPress="));
  assert.ok(line); const config=JSON.parse(line.slice("window.atshiftDeftWordPress=".length,-1)); dom.window.close();
  function url(path) {const u=new URL(config.root);if(u.searchParams.has("rest_route"))u.searchParams.set("rest_route",u.searchParams.get("rest_route")+path);else u.pathname+=path;return u;}
  return {request, url, api:(path,body)=>request(url(path),{method:body===undefined?"GET":"POST",headers:{"X-WP-Nonce":config.nonce,"Content-Type":"application/json"},...(body===undefined?{}:{body:JSON.stringify(body)})})};
}
async function json(r) {const value=await r.json();assert.equal(r.status,200,value.message);return value;}
let a,b;
try { a=await browser(data.users[0]); b=await browser(data.users[1]); }
catch(e) { fixture("cleanup"); throw e; }
let key, client, saved;
async function connect(token, endpoint) {
  const c=new Client({name:"structured-fields-integration-test",version:"1.0.0"});
  clients.push(c);
  await c.connect(new StreamableHTTPClientTransport(new URL(endpoint),{requestInit:{headers:{Authorization:`Bearer ${token}`}}}));
  return c;
}
async function call(c,name,args={}) {
  const r=await c.callTool({name:`structured-fields-${name}`,arguments:args});
  return {raw:r,data:r.isError ? null : (r.structuredContent ?? (r.content?.[0]?.text ? JSON.parse(r.content[0].text) : null))};
}
test("connection issue requires browser session + nonce, stores only a hash, does not leak keys in lists",async()=>{
  assert.equal((await fetch(a.url("connections"))).status,403);
  assert.equal((await a.request(a.url("connections"),{method:"POST",headers:{"Content-Type":"application/json"},body:'{"name":"bad"}'})).status,403);
  assert.equal((await a.api("connections",{name:"",publish:true})).status,400);
  key=await json(await a.api("connections",{name:"MCP integration test"}));
  assert.match(key.token,/^sfc_[a-f0-9-]{36}_[a-f0-9]{64}$/);
  assert.ok(key.expires>Date.now()/1000+29*86400);
  const list=await json(await a.api("connections")); assert.equal(list.items.length,1); assert.equal(list.publish,false);
  assert.ok(!JSON.stringify(list).includes(key.token));assert.ok(!JSON.stringify(list).includes("secret_hash"));
  assert.equal((await json(await b.api("connections"))).items.length,0);
  const check=execFileSync("php",["-r",`require $argv[1]; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Atshift\\Deft\\Connections::table().' WHERE id=%s',$argv[2]),ARRAY_A); echo json_encode(array_keys($row));`,fileURLToPath(new URL("../.local/wordpress/wp-load.php",import.meta.url)),key.id],{encoding:"utf8"});
  assert.ok(JSON.parse(check).includes("secret_hash"));assert.ok(!JSON.parse(check).includes("token"));
});
test("official MCP client discovers tools; new authoring tools still deny old keys",async()=>{
  assert.equal((await fetch(key.endpoint)).status,401);
  assert.equal((await a.request(key.endpoint)).status,401);
  client=await connect(key.token,key.endpoint);
  assert.deepEqual((await client.listTools()).tools.map(t=>t.name).sort(),["context","list-post-types","list-taxonomies","list-terms","list-drafts","get-draft","save-draft","trash-draft","restore-draft","create-structure","list-media","prepare-file-transfer","get-preview"].map(n=>`structured-fields-${n}`).sort());
  assert.equal((await call(client,"get-preview",{articleId:data.users[0].id,expectedVersion:1})).raw.isError,true);
  assert.equal((await call(client,"list-media",{articleId:data.users[0].id})).raw.isError,true);
  assert.equal((await call(client,"context")).data.capabilities.createStructure,false);
});
test("generated CLI settings authenticate to the exact WordPress query route without changing grants",async()=>{
  const window={};installI18n(window);runInNewContext(readFileSync(new URL("../wordpress/atshift-deft/connection-profiles.js",import.meta.url),"utf8"),{window,URL});
  for(const id of ["claude-code","gemini-cli","codex"]){
    const template=window.atshiftDeftConnectionProfiles.template(id,key.endpoint);
    assert.ok(template);assert.ok(!template.text.includes(key.token));
    const env={[template.env]:key.token};let url,auth;
    if(id==="codex"){
      url=JSON.parse(template.text.match(/^url = (.+)$/m)[1]);
      auth="Bearer "+env[template.text.match(/^bearer_token_env_var = "([^"]+)"$/m)[1]];
    }else{
      const server=Object.values(JSON.parse(template.text).mcpServers)[0];
      url=server.url||server.httpUrl;
      auth=server.headers.Authorization.replace(/\$\{([^}]+)\}/g,(_,name)=>env[name]);
    }
    assert.equal(url,key.endpoint);
    const c=new Client({name:`${id}-configuration-contract-test`,version:"1.0.0"});clients.push(c);
    await c.connect(new StreamableHTTPClientTransport(new URL(url),{requestInit:{headers:{Authorization:auth}}}));
    const context=(await call(c,"context")).data;
    assert.equal(context.capabilities.publish,false);assert.equal(context.capabilities.createStructure,false);
    assert.equal((await call(c,"get-draft",{articleId:data.users[1].id})).raw.isError,true);
    await c.close();
  }
});
test("context and draft reads isolate owners, respect language and deny private global abilities",async()=>{
  const context=await call(client,"context",{conversationLanguage:"ja"});assert.ok(!context.raw.isError);
  assert.equal(context.data.language.replyLanguage,"ja");assert.equal(context.data.transport.connectedToAI,true);
  assert.equal(context.data.capabilities.registerShared,false);assert.equal(context.data.capabilities.publish,false);
  const list=await call(client,"list-drafts");assert.deepEqual(list.data.drafts.map(d=>d.articleId),[data.users[0].id]);
  const own=await call(client,"get-draft",{articleId:data.users[0].id});assert.equal(own.data.version,1);assert.equal(own.data.previewUrl,undefined);assert.equal(own.data.previewRequiresOwnerLogin,true);
  assert.equal((await call(client,"get-draft",{articleId:data.users[1].id})).raw.isError,true);
  assert.equal((await call(client,"context",{articleId:data.users[1].id})).raw.isError,true);
  for(const route of ["/wp/v2/users","/structured-fields/v1/articles","/structured-fields/v1/connections","/mcp/mcp-adapter-default-server","/wp-abilities/v1/abilities/structured-fields/save-draft/run"]){
    const u=new URL(origin);u.searchParams.set("rest_route",route);
    assert.equal((await fetch(u,{headers:{Authorization:`Bearer ${key.token}`}})).status,403,route);
  }
});
test("save, reconnect and resume use existing immutable store; stale or unauthorized writes fail",async()=>{
  const own=await call(client,"get-draft",{articleId:data.users[0].id});const body=own.data.composeTemplate;
  body.article.title="Saved through MCP";
  const result=await call(client,"save-draft",body);assert.ok(!result.raw.isError,JSON.stringify(result));saved=result.data;
  assert.equal(saved.version,2);assert.equal(saved.publishAuthorized,false);assert.equal(saved.snapshot.article.title,body.article.title);
  assert.equal((await call(client,"save-draft",body)).raw.isError,true,"Stale expectedVersion must fail");
  const other=structuredClone(body);other.article.id=data.users[1].id;other.expectedVersion=1;
  assert.equal((await call(client,"save-draft",other)).raw.isError,true);
  assert.equal((await call(client,"save-draft",{...saved.composeTemplate,publish:true})).raw.isError,true);
  await client.close();client=await connect(key.token,key.endpoint);
  const resumed=await call(client,"get-draft",{articleId:data.users[0].id});assert.equal(resumed.data.version,2);assert.equal(resumed.data.composeTemplate.article.revision,3);
  resumed.data.composeTemplate.article.title="Resumed through MCP";
  const updated=await call(client,"save-draft",resumed.data.composeTemplate);assert.ok(!updated.raw.isError,JSON.stringify(updated));assert.equal(updated.data.version,3);
  const native=await json(await a.api(`articles/${data.users[0].id}`));assert.equal(native.version,3);
  const html=await a.request(native.previewUrl);assert.equal(html.status,200);assert.match(await html.text(),/Resumed through MCP/);
  assert.equal((await fetch(native.previewUrl)).status,403);
});
test("new drafts use catalog identities; keys cannot publish or mutate component definitions",async()=>{
  const context=(await call(client,"context")).data;
  const component=context.components.find(c=>c.id==="intro");
  const proposal=context.composeTemplate;proposal.article.id=data.race;proposal.article.title="New MCP draft";
  proposal.article.blocks=[{id:"block-new",componentId:component.id,componentRevision:component.revision,variantId:component.presentations[0].id,
    values:Object.fromEntries(component.fields.map(f=>[f.key,{value:"MCP draft content",alt:""}]))}];
  const result=await call(client,"save-draft",proposal);assert.ok(!result.raw.isError);assert.equal(result.data.version,1);
  assert.equal((await json(await a.api(`articles/${data.race}`))).version,1);
  assert.equal((await b.api(`articles/${data.race}`)).status,404);
  for(const name of ["publish","register-component","approve","upload-file"]){
    await assert.rejects(()=>client.callTool({name:`structured-fields-${name}`,arguments:{}}));
  }
});
test("guide-first context check is read-only; an opted-in sample saves separately with an owner-only preview",async()=>{
  const before=(await call(client,"list-drafts")).data;
  const context=(await call(client,"context",{conversationLanguage:"ja"})).data;
  assert.equal(context.transport.connectedToAI,true);assert.equal(context.transport.mcpEndpoint,key.endpoint);
  assert.deepEqual((await call(client,"list-drafts")).data,before,"Connection check must not create or update a draft");
  assert.match(context.instructions,/Optional Sample Walkthrough/);
  assert.match(context.instructions,/Proceed only after the user chooses the sample/);
  const original=(await call(client,"get-draft",{articleId:data.users[0].id})).data;
  const component=context.sharedComponents.find(c=>c.id==="intro");assert.ok(component);
  const proposal=context.composeTemplate;proposal.article.id=data.sample;proposal.article.title="Sample: Community reading meetup";
  assert.equal(proposal.expectedVersion,0);
  proposal.article.blocks=[{id:`sample-${randomUUID()}`,componentId:component.id,componentRevision:component.revision,variantId:component.presentations[0].id,
    values:{heading:{value:"Sample: Community reading meetup",alt:""},body:{value:"A fictional practice announcement. Share a favorite book with local readers. Date, time, venue and registration are undecided; details will follow.",alt:""}}}];
  const sample=await call(client,"save-draft",proposal);assert.ok(!sample.raw.isError);assert.equal(sample.data.version,1);assert.equal(sample.data.publishAuthorized,false);
  assert.notEqual(sample.data.articleId,original.articleId);assert.equal(sample.data.previewRequiresOwnerLogin,true);
  assert.match(sample.data.workspaceUrl,/page=structured-fields/);
  assert.equal(context.capabilities.portablePreview,false);
  assert.equal((await call(client,"get-preview",{articleId:data.sample,expectedVersion:1})).raw.isError,true,"Draft-only connection must use the owner workspace fallback");
  const native=await json(await a.api(`articles/${data.sample}`));
  assert.match(await (await a.request(native.previewUrl)).text(),/fictional practice announcement/);
  assert.equal((await fetch(native.previewUrl)).status,403);
  const edit=(await call(client,"get-draft",{articleId:data.sample})).data.composeTemplate;
  edit.article.blocks[0].values.body.value="Fictional sample: share a favorite book. Event details will follow.";
  assert.equal((await call(client,"save-draft",edit)).data.version,2);
  assert.deepEqual((await call(client,"get-draft",{articleId:data.users[0].id})).data,original,"The user's real draft is untouched");
  assert.equal((await b.api(`articles/${data.sample}`)).status,404);
});
test("key count is bounded and revoked slots can be reused",async()=>{
  const extra=[];
  for(let i=0;i<9;i++)extra.push(await json(await a.api("connections",{name:`Limit ${i}`})));
  assert.equal((await a.api("connections",{name:"Over limit"})).status,409);
  for(const item of extra)assert.equal((await a.api(`connections/${item.id}/revoke`,{})).status,200);
  assert.equal((await json(await a.api("connections"))).items.length,1);
});
test("permission removal and expiry block existing clients; other users cannot revoke",async()=>{
  fixture("revoke");
  await assert.rejects(()=>client.listTools());
  fixture("restore");
  assert.equal((await b.api(`connections/${key.id}/revoke`,{})).status,404);
  const exp=await json(await a.api("connections",{name:"Expiry test"}));
  execFileSync("php",["-r",`require $argv[1];$wpdb->update(Atshift\\Deft\\Connections::table(),['expires'=>time()-1],['id'=>$argv[2]]);`,fileURLToPath(new URL("../.local/wordpress/wp-load.php",import.meta.url)),exp.id]);
  assert.equal((await fetch(exp.endpoint,{headers:{Authorization:`Bearer ${exp.token}`}})).status,401);
  const bad=key.token.slice(0,-1)+(key.token.endsWith("0")?"1":"0");
  assert.equal((await fetch(key.endpoint,{headers:{Authorization:`Bearer ${bad}`}})).status,401);
});
test("revocation immediately blocks reconnect and ongoing requests but preserves drafts",async()=>{
  assert.equal((await json(await a.api(`connections/${key.id}/revoke`,{}))).revoked,true);
  await assert.rejects(()=>client.listTools());
  await assert.rejects(()=>connect(key.token,key.endpoint));
  assert.equal((await json(await a.api(`articles/${data.users[0].id}`))).version,3);
});

let authorKey, authorClient, transfer, structure;
const imageBytes=Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jL1sAAAAASUVORK5CYII=","base64");
const imageHash=createHash("sha256").update(imageBytes).digest("hex");
async function upload(grant, bytes=imageBytes) {
  const body=new FormData();body.append("purpose",grant.file.purpose);body.append("file",new Blob([bytes],{type:grant.file.mime}),grant.file.filename);
  return fetch(grant.uploadUrl,{method:"POST",redirect:"error",headers:{"X-SF-Transfer-Token":grant.token},body});
}
test("a media-first request can save an empty owned draft before files exist",async()=>{
  const bootstrap=await json(await a.api("connections",{name:"Media bootstrap"}));
  const c=await connect(bootstrap.token,bootstrap.endpoint);
  const body=(await call(c,"context")).data.composeTemplate;
  body.article.id=data.dbRace;body.article.title="Waiting for article materials";
  assert.deepEqual(body.article.blocks,[]);
  const result=await call(c,"save-draft",body);assert.ok(!result.raw.isError,JSON.stringify(result.raw));
  assert.equal(result.data.version,1);assert.equal(result.data.publishAuthorized,false);
  await json(await a.api(`connections/${bootstrap.id}/revoke`,{}));
});
test("authoring is explicitly granted; bounded structures reuse identities without shared updates",async()=>{
  authorKey=await json(await a.api("connections",{name:"Authoring test",authoring:true}));
  assert.equal(authorKey.authoring,true);assert.equal(authorKey.publishing,false);
  authorClient=await connect(authorKey.token,authorKey.endpoint);
  const context=(await call(authorClient,"context")).data;
  assert.equal(context.capabilities.createStructure,true);assert.equal(context.capabilities.transferFiles,true);
  assert.equal(context.capabilities.publish,false);assert.equal(context.capabilities.manageShared,false);
  const recipe=context.structureExample;recipe.id=`workflow-${run}`;recipe.key=`workflow_${run.replaceAll("-","_")}`;
  recipe.layout="columns";recipe.fields[0].key=`heading_${run.replaceAll("-","_")}`;
  const body={recipe,expectedVersion:0,reuse:{catalogToken:context.catalogToken,considered:context.components.map(c=>({id:c.id,revision:c.revision})),reason:"Existing components lack this two-column test contract."}};
  const result=await call(authorClient,"create-structure",body);assert.ok(!result.raw.isError,JSON.stringify(result.raw));structure=result.data;
  assert.equal(structure.id,recipe.id);
  assert.equal((await call(authorClient,"create-structure",body)).raw.isError,true,"Existing IDs require a fresh read, not an overwrite");
  const fresh=(await call(authorClient,"context")).data;body.reuse.catalogToken=fresh.catalogToken;
  body.recipe.id=`duplicate-${run}`;body.recipe.key=`duplicate_${run.replaceAll("-","_")}`;
  const reused=await call(authorClient,"create-structure",body);assert.ok(!reused.raw.isError,JSON.stringify(reused.raw));assert.equal(reused.data.id,structure.id);assert.equal(reused.data.reused,true);
  assert.equal((await call(authorClient,"create-structure",{...body,expectedVersion:1})).raw.isError,true);
  recipe.fields[0].value="PRIVATE ARTICLE TEXT";
  assert.equal((await call(authorClient,"create-structure",body)).raw.isError,true);
});
test("MCP stages exact image bytes and exposes only owned reusable references",async()=>{
  const media=(await call(authorClient,"list-media",{articleId:data.users[0].id})).data;
  assert.ok(media.assets.length);assert.ok(media.library.items.every(i=>data.users[0].data.attachments.image.id===i.assetRef.id||data.users[0].data.attachments.document.id===i.assetRef.id));
  assert.ok(media.assets.every(i=>!i.contentUrl));assert.ok(!media.assets.find(i=>i.purpose==="reference").assetRef);
  assert.equal((await call(authorClient,"list-media",{articleId:data.users[1].id})).raw.isError,true);
  const manifest={articleId:data.users[0].id,expectedVersion:3,filename:"pixel.png",mime:"image/png",bytes:imageBytes.length,sha256:imageHash,purpose:"publication"};
  assert.equal((await call(authorClient,"prepare-file-transfer",{...manifest,articleId:data.users[1].id})).raw.isError,true);
  assert.equal((await call(authorClient,"prepare-file-transfer",{...manifest,expectedVersion:2})).raw.isError,true);
  const prepared=await call(authorClient,"prepare-file-transfer",manifest);assert.ok(!prepared.raw.isError,JSON.stringify(prepared.raw));transfer=prepared.data;
  assert.equal(transfer.publishAuthorized,false);
  assert.equal((await upload(transfer,Buffer.from("not the authorized image"))).status,400);
  const asset=await json(await upload(transfer));assert.equal(asset.sha256,imageHash);assert.equal(asset.contentUrl,undefined);
  assert.equal((await json(await upload(transfer))).assetId,asset.assetId,"Identical retry reuses the private asset");
  const draft=(await call(authorClient,"get-draft",{articleId:data.users[0].id})).data.composeTemplate;
  draft.article.blocks[0].values.image={value:"",alt:"Exact test image",assetRef:{kind:"staged",id:asset.assetId,sha256:asset.sha256}};
  draft.article.blocks[0].values.document={value:"",alt:"PDF source",assetRef:data.users[0].data.staged.document};
  const saved=await call(authorClient,"save-draft",draft);assert.ok(!saved.raw.isError,JSON.stringify(saved.raw));assert.equal(saved.data.version,4);
  assert.equal((await upload(transfer)).status,409,"A changed article invalidates a pending grant");
});
test("portable preview is version-bound, credential-free and contains verified inline images",async()=>{
  const preview=await call(authorClient,"get-preview",{articleId:data.users[0].id,expectedVersion:4});assert.ok(!preview.raw.isError,JSON.stringify(preview.raw));
  const p=preview.data;assert.equal(p.version,4);assert.equal(p.themeIncluded,false);assert.equal(p.javascriptIncluded,false);assert.equal(p.publicUrl,null);
  assert.equal(p.sha256,createHash("sha256").update(p.html).digest("hex"));assert.match(p.html,/data:image\/png;base64,/);
  assert.ok(p.warnings.some(w=>w.includes("PDF")));
  const dom=new JSDOM(p.html);assert.ok(dom.window.document.querySelector('meta[http-equiv="Content-Security-Policy"]'));
  assert.equal(dom.window.document.querySelectorAll("script,iframe,a[href],form").length,0);dom.window.close();
  assert.ok(!p.html.includes("_sf_nonce")&&!p.html.includes(authorKey.token)&&!p.html.includes("/wp-admin/"));
  assert.equal((await call(authorClient,"get-preview",{articleId:data.users[0].id,expectedVersion:3})).raw.isError,true);
  assert.equal((await call(authorClient,"get-preview",{articleId:data.users[1].id,expectedVersion:1})).raw.isError,true);
  assert.equal((await call(authorClient,"get-preview",{articleId:data.users[0].id,expectedVersion:[4]})).raw.isError,true);
});
test("revocation and expiry also cancel authority of outstanding MCP-issued transfers",async()=>{
  const manifest={articleId:data.users[0].id,expectedVersion:4,filename:"pixel.png",mime:"image/png",bytes:imageBytes.length,sha256:imageHash,purpose:"reference"};
  const grant=(await call(authorClient,"prepare-file-transfer",manifest)).data;
  assert.ok(grant);await json(await a.api(`connections/${authorKey.id}/revoke`,{}));
  assert.equal((await upload(grant)).status,403);await assert.rejects(()=>authorClient.listTools());
  const exp=await json(await a.api("connections",{name:"Expiring authoring",authoring:true}));const expClient=await connect(exp.token,exp.endpoint);
  const expGrant=(await call(expClient,"prepare-file-transfer",manifest)).data;
  execFileSync("php",["-r",`require $argv[1];$wpdb->update(Atshift\\Deft\\Connections::table(),['expires'=>time()-1],['id'=>$argv[2]]);`,fileURLToPath(new URL("../.local/wordpress/wp-load.php",import.meta.url)),exp.id]);
  assert.equal((await upload(expGrant)).status,403);
});
