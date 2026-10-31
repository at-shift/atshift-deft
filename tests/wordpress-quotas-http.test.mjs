import {test, after} from "node:test";
import assert from "node:assert/strict";
import {execFileSync} from "node:child_process";
import {fileURLToPath} from "node:url";
import {randomUUID, createHash} from "node:crypto";
import {CookieJar} from "tough-cookie";
import {JSDOM} from "jsdom";

const origin="http://127.0.0.1:8128",run=randomUUID();
const fixture=(action,...args)=>execFileSync("php",[fileURLToPath(new URL("./wordpress-users-fixture.php",import.meta.url)),action,run,...args.map(String)],{encoding:"utf8"});
const data=JSON.parse(fixture("create"));
after(()=>assert.match(fixture("cleanup"),/fixture-ok/));
async function client(user){
  const jar=new CookieJar();
  async function request(path,options={}){
    const url=new URL(path,origin);assert.equal(url.origin,origin);
    const r=await fetch(url,{redirect:"manual",...options,headers:{Cookie:await jar.getCookieString(url.href),...options.headers}});
    for(const cookie of r.headers.getSetCookie())await jar.setCookie(cookie,url.href);return r;
  }
  await request("/wp-login.php");assert.equal((await request("/wp-login.php",{method:"POST",body:new URLSearchParams({log:user.username,pwd:user.password,testcookie:"1"})})).status,302);
  const dom=new JSDOM(await(await request("/wp-admin/admin.php?page=structured-fields")).text());
  const line=[...dom.window.document.scripts].flatMap(s=>s.textContent.split("\n")).map(s=>s.trim()).find(s=>s.startsWith("window.sfWordPress="));
  assert.ok(line);const config=JSON.parse(line.slice("window.sfWordPress=".length,-1));dom.window.close();
  const api=(path,body)=>{
    const url=new URL(config.root);if(url.searchParams.has("rest_route"))url.searchParams.set("rest_route",url.searchParams.get("rest_route")+path);else url.pathname+=path;
    return request(url,{method:body===undefined?"GET":"POST",headers:{"X-WP-Nonce":config.nonce,...(body!==undefined&&!(body instanceof FormData)?{"Content-Type":"application/json"}:{})},...(body===undefined?{}:{body:body instanceof FormData?body:JSON.stringify(body)})});
  };return {request,api};
}
const [a,b]=[await client(data.users[0]),await client(data.users[1])],aid=data.users[0].id,bid=data.users[1].id;
const json=async r=>{const value=await r.json();assert.equal(r.status,200,value.message);return value;};
const pdf=Buffer.from("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n%0\n");
function form(bytes=pdf,purpose="reference") {const body=new FormData();body.set("file",new Blob([bytes]),"quota.pdf");body.set("purpose",purpose);return body;}
let winner,winArticle,winBytes;
test("per-person bytes are atomic across articles and leave capacity for another author",async()=>{
  const article=structuredClone(data.users[0].data.snapshot.article);article.id=data.race;
  await json(await a.api("articles/compose",{article,expectedVersion:0}));
  const before=JSON.parse(fixture("quota-fill",pdf.length));assert.equal(before.usedBytes,100*1024*1024-pdf.length);
  const second=Buffer.from(pdf);second[second.length-2]="1".charCodeAt(0);
  const inputs=[[aid,pdf],[data.race,second]];
  const results=await Promise.all(inputs.map(async([id,bytes])=>{const r=await a.api(`articles/${id}/assets`,form(bytes));return {status:r.status,body:await r.json(),id,bytes};}));
  assert.deepEqual(results.map(r=>r.status).sort(),[200,413]);
  const failed=results.find(r=>r.status===413);assert.equal(failed.body.code,"sf_asset_user_quota");
  const success=results.find(r=>r.status===200);winner=success.body;winArticle=success.id;winBytes=success.bytes;
  assert.equal(JSON.parse(fixture("quota-usage")).usedBytes,100*1024*1024);
  assert.equal((await b.api(`articles/${bid}/assets`,form())).status,200);
});
test("exact retry at capacity is idempotent; another article or purpose consumes new capacity",async()=>{
  assert.equal((await json(await a.api(`articles/${winArticle}/assets`,form(winBytes)))).assetId,winner.assetId);
  for(const [id,purpose] of [[winArticle,"publication"],[winArticle===aid?data.race:aid,"reference"]]){
    const r=await a.api(`articles/${id}/assets`,form(winBytes,purpose));assert.equal(r.status,413);assert.equal((await r.json()).code,"sf_asset_user_quota");
  }
});
test("delegated transfer consumes the grant issuer budget, not an anonymous or different uploader budget",async()=>{
  const bytes=Buffer.concat([pdf,Buffer.from("% transfer\n")]);
  const grant=await json(await a.api(`articles/${aid}/transfers`,{expectedVersion:1,filename:"quota.pdf",mime:"application/pdf",bytes:bytes.length,sha256:createHash("sha256").update(bytes).digest("hex"),purpose:"reference"}));
  const response=await b.request(grant.uploadUrl,{method:"POST",headers:{"X-SF-Transfer-Token":grant.token},body:form(bytes)});
  assert.equal(response.status,413);assert.equal((await response.json()).code,"sf_asset_user_quota");
  const own=await b.api(`articles/${bid}/assets`,form(bytes));assert.equal(own.status,200);
  assert.equal(JSON.parse(fixture("quota-usage")).usedBytes,100*1024*1024);
});
