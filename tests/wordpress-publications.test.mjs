import { test, after } from "node:test";
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { randomUUID, createHash } from "node:crypto";
import { CookieJar } from "tough-cookie";
import { JSDOM } from "jsdom";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

const origin = "http://127.0.0.1:8128", run = randomUUID();
const fixture = (action, ...args) => execFileSync("php", [fileURLToPath(new URL("./wordpress-users-fixture.php", import.meta.url)), action, run, ...args.map(String)], {encoding:"utf8"});
const data = JSON.parse(fixture("create")), clients = [];
after(async () => { for (const c of clients) await c.close(); assert.match(fixture("cleanup"), /fixture-ok/); });
async function browser(user) {
  const jar = new CookieJar();
  async function request(url, options = {}) {
    const target = new URL(url, origin);
    const response = await fetch(target, {redirect:"manual", ...options, headers:{Cookie:await jar.getCookieString(target.href), ...options.headers}});
    for (const cookie of response.headers.getSetCookie()) await jar.setCookie(cookie, target.href);
    return response;
  }
  await request("/wp-login.php");
  assert.equal((await request("/wp-login.php", {method:"POST",body:new URLSearchParams({log:user.username,pwd:user.password,testcookie:"1"})})).status,302);
  const dom = new JSDOM(await (await request("/wp-admin/admin.php?page=structured-fields-connections")).text());
  const line = [...dom.window.document.scripts].flatMap(s=>s.textContent.split("\n")).map(s=>s.trim()).find(s=>s.startsWith("window.sfWordPress="));
  assert.ok(line); const config=JSON.parse(line.slice("window.sfWordPress=".length,-1)); dom.window.close();
  function url(path) {const u=new URL(config.root);if(u.searchParams.has("rest_route"))u.searchParams.set("rest_route",u.searchParams.get("rest_route")+path);else u.pathname+=path;return u;}
  return {request, url, api:(path,body)=>request(url(path),{method:body===undefined?"GET":"POST",headers:{"X-WP-Nonce":config.nonce,"Content-Type":"application/json"},...(body===undefined?{}:{body:JSON.stringify(body)})})};
}
const json = async r => {const value=await r.json();assert.equal(r.status,200,value.message);return value;};
const a=await browser(data.users[0]), b=await browser(data.users[1]);
async function connect(key) {
  const c=new Client({name:"structured-fields-publication-test",version:"1.0.0"}); clients.push(c);
  await c.connect(new StreamableHTTPClientTransport(new URL(key.endpoint),{requestInit:{headers:{Authorization:`Bearer ${key.token}`}}}));return c;
}
async function call(c,name,args={}) {
  const r=await c.callTool({name:`structured-fields-${name}`,arguments:args});
  return {raw:r,data:r.isError ? null : (r.structuredContent ?? JSON.parse(r.content[0].text))};
}
async function good(name,args) {const r=await call(client,name,args);assert.ok(!r.raw.isError,JSON.stringify(r.raw));return r.data;}
const time = () => new Date(Date.now()+3600000).toISOString().replace(/\.\d{3}Z$/,"+00:00");
const intent = (id,version,pubVersion,timing="scheduled") => ({articleId:id,expectedVersion:version,expectedPublicationVersion:pubVersion,requestId:randomUUID(),timing,publishAt:timing==="now"?"":time(),timeZone:timing==="now"?"":"UTC"});
let draftKey, pubKey, client, draftClient, schedule, immediate, mediaUrl;

test("publishing is explicit; old keys and other roles cannot gain it",async()=>{
  assert.equal((await a.api("connections",{name:"Denied",publishing:true})).status,403);
  draftKey=await json(await a.api("connections",{name:"Original draft key"}));
  fixture("publisher");
  pubKey=await json(await a.api("connections",{name:"Explicit publishing",publishing:true}));
  assert.equal(pubKey.publishing,true); assert.notEqual(pubKey.endpoint,draftKey.endpoint);
  assert.equal((await fetch(pubKey.endpoint,{headers:{Authorization:`Bearer ${draftKey.token}`}})).status,401);
  assert.equal((await fetch(draftKey.endpoint,{headers:{Authorization:`Bearer ${pubKey.token}`}})).status,401);
  draftClient=await connect(draftKey);assert.equal((await draftClient.listTools()).tools.length,8);
  client=await connect(pubKey);assert.equal((await client.listTools()).tools.length,11);
  const context=await good("context",{conversationLanguage:"ja"});assert.equal(context.capabilities.publish,true);assert.equal(context.language.replyLanguage,"ja");
  assert.match(context.publication.instructions,/user chooses; the AI sets/);
  assert.equal((await call(client,"get-publication",{articleId:data.users[1].id})).raw.isError,true);
  assert.equal((await call(client,"set-publication",intent(data.users[1].id,1,0))).raw.isError,true);
  assert.equal((await fetch(a.url("publications"),{method:"POST",headers:{Authorization:`Bearer ${pubKey.token}`,"Content-Type":"application/json"},body:JSON.stringify(intent(data.users[0].id,1,0))})).status,403);
});
test("schedule remains private, exact retries are idempotent, version conflicts and cancellation are safe",async()=>{
  const own=await good("get-draft",{articleId:data.users[0].id});
  const proposal=structuredClone(own.composeTemplate);proposal.article.id=data.race;proposal.article.revision=1;proposal.expectedVersion=0;
  await good("save-draft",proposal);
  const body=intent(data.race,1,0);schedule=await json(await a.api("publications",body));
  assert.equal(schedule.authorizationMethod,"wordpress-session");
  assert.equal(schedule.state,"scheduled");assert.equal(schedule.postId,0);assert.equal(schedule.humanPresenceVerified,false);
  assert.deepEqual(await good("set-publication",body),schedule);
  assert.equal((await call(client,"set-publication",{...body,publishAt:time().replace(/T\d\d:/,"T00:")})).raw.isError,true);
  assert.equal((await call(client,"set-publication",intent(data.race,1,0))).raw.isError,true);
  fixture("publication-run",data.race);assert.equal((await good("get-publication",{articleId:data.race})).postId,0);
  const cancelled=await good("cancel-publication",{articleId:data.race,expectedPublicationVersion:1});assert.equal(cancelled.state,"cancelled");assert.equal(cancelled.version,2);
  fixture("publication-run",data.race,1);assert.equal((await good("get-publication",{articleId:data.race})).state,"cancelled");
  assert.equal((await call(client,"cancel-publication",{articleId:data.race,expectedPublicationVersion:1})).raw.isError,true);
});
test("saved changes invalidate reservations; stale workers cannot publish a replacement",async()=>{
  schedule=await good("set-publication",intent(data.race,1,2));assert.equal(schedule.version,3);
  const own=await good("get-draft",{articleId:data.race});own.composeTemplate.article.title="Changed after scheduling";await good("save-draft",own.composeTemplate);
  assert.equal((await good("get-publication",{articleId:data.race})).state,"invalidated");
  fixture("publication-due",data.race);fixture("publication-run",data.race);
  assert.equal((await good("get-publication",{articleId:data.race})).state,"blocked");
  schedule=await json(await a.api("publications",intent(data.race,2,3)));assert.equal(schedule.version,4);
  assert.equal(schedule.authorizationMethod,"wordpress-session");
  fixture("publication-due",data.race);fixture("publication-run",data.race,3);
  assert.equal((await good("get-publication",{articleId:data.race})).postId,0);
  fixture("publication-run",data.race,4);
  const published=await good("get-publication",{articleId:data.race});assert.equal(published.state,"published");assert.ok(published.postId);
  fixture("publication-run",data.race,4);assert.equal((await good("get-publication",{articleId:data.race})).postId,published.postId);
  assert.equal((await call(client,"set-publication",intent(data.race,2,4))).raw.isError,true);
});
test("immediate publication serves frozen images/PDF publicly, never executes article shortcodes",async()=>{
  fixture("administrator");
  const own=await good("get-draft",{articleId:data.users[0].id});const proposal=own.composeTemplate;
  proposal.article.title=' A & B <b>literal</b> <img src=x onerror=alert(1)> <svg onload=alert(2)> 日本語 &lt;b&gt; ';
  for(const block of proposal.article.blocks) for(const [key,value] of Object.entries(block.values)) {
    const component=own.snapshot.components.find(c=>c.id===block.componentId);const field=component.fields.find(f=>f.key===key);
    if(field.type==="image") {value.value="";value.alt="Publication image";value.assetRef=data.users[0].data.staged.image;}
    else if(field.type==="link") {value.value="";value.alt="Public test [sf_test_execute]";value.assetRef=data.users[0].data.staged.document;}
    else value.value="Public test [sf_test_execute]";
  }
  const saved=await call(draftClient,"save-draft",proposal);assert.ok(!saved.raw.isError,JSON.stringify(saved.raw));
  fixture("legacy-preview-title",data.users[0].id);
  const preview=await json(await a.api(`articles/${data.users[0].id}`));
  const previewDom=new JSDOM(await (await a.request(preview.previewUrl)).text());
  assert.equal(previewDom.window.document.querySelectorAll('[onerror], [onload]').length,0);
  assert.equal(previewDom.window.document.querySelector('h1.wp-block-post-title').textContent,proposal.article.title);
  previewDom.window.close();
  immediate=await good("set-publication",intent(data.users[0].id,2,0,"now"));assert.equal(immediate.state,"published",immediate.error);
  assert.equal(immediate.authorizationMethod,"delegated-publishing-connection");
  const response=await fetch(immediate.url);assert.equal(response.status,200);
  const dom=new JSDOM(await response.text());const image=dom.window.document.querySelector(".sf-article img");assert.ok(image);
  assert.equal(dom.window.document.querySelectorAll('[onerror], [onload]').length,0);
  assert.equal(dom.window.document.querySelector('h1.wp-block-post-title').textContent,proposal.article.title);
  mediaUrl=image.src;const imageResponse=await fetch(mediaUrl);assert.equal(imageResponse.status,200);assert.match(imageResponse.headers.get("content-type"),/image\/png/);
  assert.equal(createHash("sha256").update(Buffer.from(await imageResponse.arrayBuffer())).digest("hex"),data.users[0].data.staged.image.sha256);
  const pdf=dom.window.document.querySelector('.sf-article a[href*="sf_public_media"]');assert.ok(pdf);
  const pdfResponse=await fetch(pdf.href);assert.equal(pdfResponse.status,200);assert.match(pdfResponse.headers.get("content-disposition"),/^attachment/);
  assert.ok(dom.window.document.querySelector('[id^="sf-publication-"]'));dom.window.close();
  fixture("publication-shortcodes",data.users[0].id);
  assert.equal((await fetch((await json(await a.api(`articles/${data.users[0].id}`))).previewUrl)).status,403);
  const repeat=await good("get-publication",{articleId:data.users[0].id});assert.equal(repeat.postId,immediate.postId);
  fixture("legacy-public-title",data.users[0].id);
  const legacyDom=new JSDOM(await (await fetch(immediate.url)).text());
  assert.equal(legacyDom.window.document.querySelectorAll('[onerror], [onload]').length,0);
  assert.equal(legacyDom.window.document.querySelector('h1.wp-block-post-title').textContent,proposal.article.title);legacyDom.window.close();
  fixture("publication-recover",data.users[0].id);fixture("publication-run",data.users[0].id);
  assert.equal((await good("get-publication",{articleId:data.users[0].id})).postId,immediate.postId);
  const recoveredDom=new JSDOM(await (await fetch(immediate.url)).text());
  assert.equal(recoveredDom.window.document.querySelectorAll('[onerror], [onload]').length,0);
  assert.equal(recoveredDom.window.document.querySelector('h1.wp-block-post-title').textContent,proposal.article.title);recoveredDom.window.close();
  fixture("publication-hidden",data.users[0].id);assert.equal((await fetch(mediaUrl)).status,404);
  assert.equal((await good("get-publication",{articleId:data.users[0].id})).state,"withdrawn");
  fixture("publication-public",data.users[0].id);assert.equal((await fetch(mediaUrl)).status,200);
  fixture("publisher");
});
test("public media needs no private nonce but rejects malformed identifiers",async()=>{
  assert.equal((await fetch(mediaUrl)).status,200);
  for (const key of ["sf_public_media", "sf_media_key"]) {
    for (const malformed of ["array", "markup"]) {
      const url = new URL(mediaUrl), value = url.searchParams.get(key);
      url.searchParams.delete(key);
      url.searchParams.set(malformed === "array" ? key + "[]" : key, malformed === "array" ? value : `<b>${value}</b>`);
      assert.equal((await fetch(url)).status,404);
    }
  }
});
test("capability loss blocks due jobs and revoked connections cancel pending reservations",async()=>{
  const own=await good("get-draft",{articleId:data.users[0].id});const proposal=own.composeTemplate;
  proposal.article.id=data.dbRace;proposal.article.revision=1;proposal.expectedVersion=0;
  // Reuse the original URL-only fixture; private asset IDs are article-scoped.
  proposal.article.blocks=data.users[0].data.snapshot.article.blocks;
  await good("save-draft",proposal);
  await good("set-publication",intent(data.dbRace,1,0));fixture("publication-due",data.dbRace);
  fixture("author");fixture("publication-run",data.dbRace);
  assert.equal((await json(await a.api(`publications/${data.dbRace}`))).state,"blocked");
  await assert.rejects(()=>client.listTools());fixture("publisher");
  await good("set-publication",intent(data.dbRace,1,1));
  await json(await a.api(`connections/${pubKey.id}/revoke`,{}));
  assert.equal((await json(await a.api(`publications/${data.dbRace}`))).state,"cancelled");
  fixture("publication-due",data.dbRace);fixture("publication-run",data.dbRace);
  assert.equal((await json(await a.api(`publications/${data.dbRace}`))).postId,0);
});
test("expiry prevents new calls but does not silently cancel an authorized reservation",async()=>{
  pubKey=await json(await a.api("connections",{name:"Expiry reservation",publishing:true}));client=await connect(pubKey);
  const old=await good("get-publication",{articleId:data.dbRace});await good("set-publication",intent(data.dbRace,1,old.version));
  fixture("publication-expire",data.dbRace);await assert.rejects(()=>client.listTools());
  fixture("publication-due",data.dbRace);fixture("publication-run",data.dbRace);
  assert.equal((await json(await a.api(`publications/${data.dbRace}`))).state,"published");
});
