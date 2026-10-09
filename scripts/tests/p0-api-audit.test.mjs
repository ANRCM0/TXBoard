import { strict as assert } from 'node:assert';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { collectReferences, collectRoutes, verifyCriticalRoutes } from '../p0-api-audit.mjs';

test('scan locates old callers but never exposes source lines or secrets', () => {
 const root=mkdtempSync(join(tmpdir(),'txboard-p0-'));
 try {
  mkdirSync(join(root,'web/user/src'),{recursive:true});
  mkdirSync(join(root,'api/app'),{recursive:true});
  mkdirSync(join(root,'docs'),{recursive:true});
  writeFileSync(join(root,'web/user/src/client.ts'),
   'const url="/api/v1";\nconst key="xboard_auth_data"; // SECRET_VALUE\n');
  writeFileSync(join(root,'api/app/Model.php'),'<?php $table="v2_order";');
  writeFileSync(join(root,'docs/old.md'),'/api/v1');
  const found=collectReferences(root);
  assert.ok(found.some(x=>x.kind==='legacy_api'&&x.owner==='User web'&&x.line===1));
  assert.ok(found.some(x=>x.kind==='legacy_session'&&x.line===2));
  assert.ok(found.some(x=>x.kind==='legacy_schema'));
  assert.ok(!JSON.stringify(found).includes('SECRET_VALUE'));
  assert.ok(!JSON.stringify(found).includes('old.md'));
 } finally {rmSync(root,{recursive:true,force:true});}
});

test('route inventory guards payment, user, admin, agent and node contracts',()=>{
 const input=[
  {uri:'api/health',method:'GET|HEAD'},
  {uri:'api/v1/passport/auth/login',method:'POST'},
  {uri:'api/v1/user/order/checkout',method:'POST',middleware:['api','user']},
  {uri:'api/v1/guest/payment/notify/{method}/{uuid}',method:'GET|HEAD|POST'},
  {uri:'api/v2/server/handshake',method:'GET|HEAD|POST',middleware:['api','server.v2']},
  {uri:'api/v2/{admin_path}/config/fetch',method:'GET|HEAD',middleware:['api','admin.path','admin']},
  {uri:'api/v2/agent/whoami',method:'GET|HEAD',middleware:['api','agent']},
  {uri:'other/path',method:'GET'}
 ];
 const routes=collectRoutes(input);
 assert.equal(routes.length,7);
 assert.deepEqual(verifyCriticalRoutes(routes),[]);
 const broken=routes.map(r=>r.uri.includes('order/checkout')?{...r,middleware:['api']}:r);
 assert.ok(verifyCriticalRoutes(broken).some(s=>s.includes('missing middleware user')));
 const hidden=collectRoutes([{uri:'api/v2/secret-admin-path/config/fetch',method:'GET'}]);
 assert.equal(hidden[0].uri,'api/v2/{admin_path}/config/fetch');
 assert.ok(!JSON.stringify(hidden).includes('secret-admin-path'));
 assert.throws(()=>collectRoutes({bad:1}),/must be an array/);
});
