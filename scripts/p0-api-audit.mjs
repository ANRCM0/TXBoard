#!/usr/bin/env node
// P0 evidence generator: exports reference locations, never source lines or secrets.
import { readFileSync, readdirSync, statSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, extname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const scanDirs = ['api/app','api/routes','api/plugins','api/plugins-core',
 'api/database/migrations','api/resources','api/.docker',
 'web/admin/src','web/user/src','web/shared','mcp'];
const types = new Set(['.php','.ts','.tsx','.js','.jsx','.vue','.json']);
const marks = [
 ['legacy_api',/\/api\/v[12](?=\/|['"?\s]|$)/gi],
 ['legacy_session',/xboard_auth_data/gi],
 ['xboard_reference',/\bxboard\b/gi],
 ['legacy_schema',/\bv2_[a-z][a-z0-9_]*\b/gi],
 ['legacy_health',/\/api\/health(?=\/|['"?\s]|$)/gi],
];
const required = [
 ['GET','api/health',[]],
 ['POST','api/v1/passport/auth/login',[]],
 ['POST','api/v1/user/order/checkout',['user']],
 ['GET','api/v1/guest/payment/notify/{method}/{uuid}',[]],
 ['POST','api/v1/guest/payment/notify/{method}/{uuid}',[]],
 ['GET','api/v2/server/handshake',['server.v2']],
 ['GET','api/v2/{admin_path}/config/fetch',['admin.path','admin']],
 ['GET','api/v2/agent/whoami',['agent']],
];
function owner(path) {
 if(path.startsWith('web/admin/')) return 'Admin web';
 if(path.startsWith('web/user/')) return 'User web';
 if(path.startsWith('web/shared/')) return 'Web shared';
 if(path.startsWith('mcp/')) return 'MCP';
 if(path.startsWith('api/database/')) return 'Database';
 if(path.startsWith('api/.docker/')) return 'Runtime';
 if(path.startsWith('api/plugins') || path.startsWith('api/resources/')) return 'Extension';
 return 'Laravel';
}
function domain(uri) {
 if(/payment\/notify|webhook/.test(uri)) return 'Payment';
 if(/order|balance|checkout|commission/.test(uri)) return 'Billing';
 if(/server|machine|traffic/.test(uri)) return 'Node/Traffic';
 if(/agent/.test(uri)) return 'Agent';
 if(/\{admin_path\}|\/admin\//.test(uri)) return 'Admin';
 if(/passport|login|auth|register/.test(uri)) return 'Identity';
 return 'Public/User';
}
export function collectReferences(root) {
 const out = [];
 function walk(dir) {
  let entries;
  try { entries=readdirSync(dir,{withFileTypes:true}); }
  catch(e) { if(e.code==='ENOENT') return; throw e; }
  for(const ent of entries) {
   if(ent.isSymbolicLink() || ['vendor','node_modules'].includes(ent.name)) continue;
   const path=join(dir,ent.name);
   if(ent.isDirectory()) { walk(path); continue; }
   if(!ent.isFile() || !types.has(extname(ent.name)) || statSync(path).size>1048576) continue;
   const file=relative(root,path).replaceAll('\\','/');
   const lines=readFileSync(path,'utf8').split(/\r?\n/);
   for(let index=0; index<lines.length; index++) {
    for(const [kind,pattern] of marks) {
     pattern.lastIndex=0;
     const matched=lines[index].match(pattern);
     if(matched) out.push({file,line:index+1,kind,count:matched.length,owner:owner(file)});
    }
   }
  }
 }
 for(const dir of scanDirs) walk(resolve(root,dir));
 return out.sort((a,b)=>a.file.localeCompare(b.file)||a.line-b.line||a.kind.localeCompare(b.kind));
}
function safeUri(raw) {
 const uri=String(raw||'').replace(/^\/+/,'').split('?')[0].replace(/[\r\n]/g,'').slice(0,260);
 return uri.replace(/^api\/v2\/(?!\{|server(?:\/|$)|agent(?:\/|$)|user(?:\/|$)|guest(?:\/|$)|passport(?:\/|$)|client(?:\/|$))[^/]+/i,'api/v2/{admin_path}');
}
export function collectRoutes(input) {
 if(!Array.isArray(input)) throw new Error('route:list --json must be an array');
 return input.map(row=>{
  const uri=safeUri(row.uri);
  const middleware=Array.isArray(row.middleware)?row.middleware.map(String):
   String(row.middleware||'').split(',').map(x=>x.trim()).filter(Boolean);
  return {method:String(row.method||''),uri,middleware,action:String(row.action||'').slice(0,200),domain:domain(uri)};
 }).filter(r=>/^(api\/v[12](?:\/|$)|api\/health$|txapi(?:\/|$))/.test(r.uri))
   .sort((a,b)=>a.uri.localeCompare(b.uri)||a.method.localeCompare(b.method));
}
// route:list --json expands Laravel aliases to full middleware class names.
// Treat both representations as equivalent; do not waive the actual guard.
const expandedMiddleware = {
 user: 'App\\Http\\Middleware\\User',
 'server.v2': 'App\\Http\\Middleware\\ServerV2',
 'admin.path': 'App\\Http\\Middleware\\AdminPath',
 admin: 'App\\Http\\Middleware\\Admin',
 agent: 'App\\Http\\Middleware\\AgentAuth',
};
export function verifyCriticalRoutes(routes) {
 const failures=[];
 for(const [method,uri,guards] of required) {
  const route=routes.find(r=>r.uri===uri&&r.method.split('|').includes(method));
  if(!route) {failures.push(method+' '+uri+' missing');continue;}
  for(const guard of guards) {
   const names=[guard,expandedMiddleware[guard]].filter(Boolean);
   if(!route.middleware.some(m=>names.some(name=>m===name||m.startsWith(name+':'))))
    failures.push(method+' '+uri+' missing middleware '+guard);
  }
 }
 return failures;
}
export function generate(root,routesData) {
 const routes=collectRoutes(routesData);
 return {schema_version:1,references:collectReferences(root),routes,failures:verifyCriticalRoutes(routes)};
}
function main() {
 const args=process.argv.slice(2);
 function arg(name) {
  const index=args.indexOf(name);
  if(index<0||!args[index+1]||args[index+1].startsWith('--')) throw new Error('Need '+name+' <file>');
  return args[index+1];
 }
 const root=fileURLToPath(new URL('..',import.meta.url));
 const report=generate(root,JSON.parse(readFileSync(resolve(arg('--routes')),'utf8')));
 const dest=resolve(arg('--output-dir'));
 mkdirSync(dest,{recursive:true});
 writeFileSync(join(dest,'inventory.json'),JSON.stringify(report,null,2)+'\n');
 const byDomain={};
 for(const route of report.routes) byDomain[route.domain]=(byDomain[route.domain]||0)+1;
 const summary=['# TXBoard P0 baseline','',
  'Static references are not live traffic or proof of upstream source similarity.',
  'No source lines, credentials, tokens, request payloads or auth headers are exported.','',
  'Routes: '+report.routes.length,
  'Source reference locations: '+report.references.length,
  'Critical failures: '+report.failures.length,'',
  '| Domain | Routes |','| --- | ---: |',
  ...Object.keys(byDomain).sort().map(key=>'| '+key+' | '+byDomain[key]+' |'),'',
  'Next: manually verify external consumers, provenance, owners, production SQL and latency baselines.',''];
 writeFileSync(join(dest,'summary.md'),summary.join('\n'));
 console.log('P0: '+report.routes.length+' routes, '+report.references.length+' references.');
 for(const failure of report.failures) console.error('Critical legacy contract: '+failure);
 if(report.failures.length) process.exitCode=1;
}
if(process.argv[1]&&resolve(process.argv[1])===fileURLToPath(import.meta.url)) {
 try {main();} catch(e) {console.error(e.message);process.exitCode=1;}
}
