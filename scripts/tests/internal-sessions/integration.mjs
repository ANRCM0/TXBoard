import assert from 'node:assert/strict';
import https from 'node:https';
import http from 'node:http';
import { readFileSync, writeFileSync, openSync, closeSync, unlinkSync, readdirSync, rmSync } from 'node:fs';
import { createHash, createPrivateKey, sign, randomBytes, randomUUID } from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const fixture = process.env.INTERNAL_FIXTURE_DIR;
const api = process.env.INTERNAL_CORE_API;
assert.ok(fixture && api);
const script = path.dirname(fileURLToPath(import.meta.url));
const manifest = JSON.parse(readFileSync(`${api}/../manifest.json`));
assert.equal(manifest.base, process.env.INTERNAL_EXPECTED_CORE_COMMIT);
const tlsVersions = new Set();
const actors = JSON.parse(readFileSync(`${fixture}/actors.json`));
const evidence = JSON.parse(readFileSync(`${fixture}/public-evidence.json`));
const trustPath = `${fixture}/core-trust.json`, boundaryPath = `${fixture}/boundary.json`;
const trust = JSON.parse(readFileSync(trustPath)), boundary = JSON.parse(readFileSync(boundaryPath));
const ca = readFileSync(`${fixture}/ca.pem`);
let core, proxy, checks = [], agents = [], sockets = [];
const processes = [];
const sha = (value) => createHash('sha256').update(value).digest('hex');
function start(command, args, name, env = {}) {
  const log = openSync(`${fixture}/${name}.log`, 'a', 0o600);
  const child = spawn(command, args, { cwd: api, env: { ...process.env, ...env }, detached: true, stdio: ['ignore', log, log] });
  closeSync(log);
  processes.push(child);
  return child;
}
function stop(child) {
  if (!child) return;
  try { process.kill(-child.pid, 'SIGTERM'); } catch {}
}
const mutate = (...args) => execFileSync('php', [`${api}/internal-readonly-fixtures/fixture.php`, ...args], { cwd: api, env: process.env });
function makeBody(input = {}, patch = {}) {
  return JSON.stringify({ contract_version: '1', request_id: randomUUID(), deadline_at: Math.floor(Date.now() / 1000) + 5, input, ...patch });
}
function request({ operation = 'user.profile.get', input = {}, role = 'user', token = actors.credentials.user,
  direct = false, body = makeBody(input), proof, headers = {}, agent, servername = 'internal-core.local', cert = role }) {
  return new Promise((resolve, reject) => {
    const config = direct ? { hostname: '127.0.0.1', port: 19081 } : {
      hostname: '127.0.0.1', port: 19443, servername, ca, minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3',
      ...(cert ? { key: readFileSync(`${fixture}/${cert}.key`), cert: readFileSync(`${fixture}/${cert}.pem`) } : {}),
      agent: agent ?? false,
    };
    const req = (direct ? http : https).request({ ...config, method: 'POST', path: `/txapi/internal/v1/operations/${operation}`,
      headers: { 'content-type': 'application/json', authorization: token, 'content-length': Buffer.byteLength(body),
        ...(proof ? { 'x-txboard-service-proof': proof } : {}), ...headers }, timeout: 6000,
    }, (res) => {
      let text = '';
      res.on('data', (chunk) => { text += chunk; if (text.length > 65536) req.destroy(); });
      res.on('end', () => {
        try { if (!direct) tlsVersions.add(res.socket.getProtocol()); resolve({ status: res.statusCode, body: JSON.parse(text), text, socket: req.socket, headers: res.headers }); }
        catch { reject(new Error('Expected bounded JSON response')); }
      });
    });
    req.on('timeout', () => req.destroy(new Error('Fixture timeout')));
    req.on('error', reject);
    req.end(body);
  });
}
async function check(name, run) {
  await run();
  checks.push(name);
  process.stdout.write(`ok ${checks.length}: ${name}\n`);
}
async function status(options, expected) {
  const result = await request(options);
  assert.equal(result.status, expected, `Expected ${expected}; received ${result.status} (${result.body.error?.code ?? 'success'})`);
  for (const secret of [...Object.values(actors.credentials), 'private-subscribe-token', 'must-never-return-callback', 'PRIVATE KEY']) {
    assert.ok(!result.text.includes(secret), 'Response must not leak credentials');
  }
  return result;
}
function claims(operation, body, token, role = 'user', patch = {}) {
  const now = Math.floor(Date.now() / 1000);
  return { v: 'internal-readonly/1', epoch: 1, iss: 'txboard-local-tls-boundary', aud: 'txboard-core-local', service: `${role}-gateway`,
    cert_sha256: evidence.certificates[role], method: 'POST', path: `/txapi/internal/v1/operations/${operation}`,
    body_sha256: sha(body), actor_sha256: sha(token), iat: now, nbf: now - 2, exp: now + 10, jti: randomBytes(16).toString('hex'), ...patch };
}
function proof(payload, role = 'user', kid = `${role}-1`, header = { alg: 'RS256', typ: 'JWT', kid }) {
  const h = Buffer.from(typeof header === 'string' ? header : JSON.stringify(header)).toString('base64url');
  const p = Buffer.from(typeof payload === 'string' ? payload : JSON.stringify(payload)).toString('base64url');
  const unsigned = `${h}.${p}`;
  return `${unsigned}.${sign('RSA-SHA256', Buffer.from(unsigned), createPrivateKey(readFileSync(`${fixture}/${kid}.proof.key`))).toString('base64url')}`;
}
async function ready(direct = false) {
  for (let i = 0; i < 40; i++) {
    try { await request({ direct }); return; } catch { await sleep(100); }
  }
  throw new Error('Local fixture server failed to start');
}
const decorate = action => JSON.parse(execFileSync('php', [`${script}/fixture.php`, api, action], { cwd:api, env:process.env, encoding:'utf8' }));
let remaining;
try {
  remaining = decorate('remaining').credential;
  core = start('php', ['-S','127.0.0.1:19081','-t','public','public/index.php'], 'core-sessions');
  proxy = start('node', [`${api}/internal-readonly-fixtures/tls-boundary.mjs`,boundaryPath], 'boundary-sessions');
  await ready(true); await ready();
  const list = () => status({operation:'user.session.list'},200);
  let currentId, expiredId;
  await check('real TLS1.3 native owner list uses decimal IDs and excludes private tokens', async () => {
    const response = await list();
    assert.deepEqual(response.body.meta,{current_only:true,slice:'internal-isolated-sessions/1'});
    assert.equal(response.body.data.items.length,3);
    for (const item of response.body.data.items) {
      assert.match(item.id,/^[1-9][0-9]*$/); assert.ok(!Object.hasOwn(item,'token'));
    }
    currentId = response.body.data.items.find(r=>r.name==='internal-fixture-user').id;
    expiredId = response.body.data.items.find(r=>r.name==='expired').id;
    assert.ok(response.headers['cache-control'].includes('no-store'));
  });
  await check('bare Core, admin certificate/actor and expired visitor cannot revoke sessions', async () => {
    await status({operation:'user.session.logout',direct:true},401);
    await status({operation:'user.session.logout',role:'admin'},403);
    await status({operation:'user.session.logout',token:actors.credentials.admin},403);
    await status({operation:'user.session.logout',token:actors.credentials.expired},401);
    mutate('mutate','user','ban');
    try { await status({operation:'user.session.logout'},403); }
    finally { mutate('mutate','user','unban'); }
    assert.equal((await list()).body.data.items.length,3);
  });
  await check('foreign session ID is hidden and native owner sessions remain intact', async () => {
    const foreign = await status({operation:'user.session.list',token:actors.credentials.other},200);
    await status({operation:'user.session.revoke',input:{session_id:foreign.body.data.items[0].id}},404);
    assert.equal((await status({operation:'user.session.list',token:actors.credentials.other},200)).body.data.items.length,1);
  });
  await check('native specified deletion succeeds once and a repeated delete returns 404', async () => {
    const result = await status({operation:'user.session.revoke',input:{session_id:expiredId}},200);
    assert.deepEqual(result.body.data,{ok:true});
    await status({operation:'user.session.revoke',input:{session_id:expiredId}},404);
    assert.equal((await list()).body.data.items.length,2);
  });
  await check('duplicate session fields and caller-supplied owner are rejected before mutation', async () => {
    const raw = makeBody({session_id:currentId}).replace(/}}$/,`,"session_id":"${expiredId}"}}`);
    await status({operation:'user.session.revoke',body:raw},400);
    await status({operation:'user.session.revoke',input:{session_id:currentId,owner_id:actors.user_id}},400);
    assert.equal((await list()).body.data.items.length,2);
  });
  await check('SQL delete fault leaves native current session usable and diagnostics private', async () => {
    decorate('delete-reject');
    try { await status({operation:'user.session.logout'},500); }
    finally { decorate('delete-allow'); }
    assert.equal((await list()).body.data.items.length,2);
  });
  await check('audit failure hides committed current revocation; only another credential can confirm', async () => {
    decorate('audit-reject');
    try { await status({operation:'user.session.logout'},503); }
    finally { decorate('audit-allow'); }
    await status({operation:'user.profile.get'},401);
    const result = await status({operation:'user.session.list',token:remaining},200);
    assert.equal(result.body.data.items.length,1);
    assert.ok(!result.body.data.items.some(r=>r.id===currentId));
  });
  await check('successful logout of final credential issues no new token and further requests fail', async () => {
    await status({operation:'user.session.logout',token:remaining},200);
    await status({operation:'user.session.list',token:remaining},401);
    assert.equal(decorate('state').user_sessions,0);
    assert.equal(decorate('state').other_sessions,1);
  });
  await check('independent session flag remains closed at real HTTP boundary', async () => {
    stop(core); await sleep(200);
    core = start('php',['-S','127.0.0.1:19081','-t','public','public/index.php'],'core-sessions-disabled',{CORE_INTERNAL_SESSIONS_ENABLED:'false'});
    await ready(true);
    await status({operation:'user.session.list',token:actors.credentials.other},404);
  });
  const logs = readdirSync(fixture).filter(n=>n.endsWith('.log')).map(n=>readFileSync(`${fixture}/${n}`,'utf8')).join('\n');
  for (const secret of [remaining,...Object.values(actors.credentials),'PRIVATE KEY','fixture-private-session-sql-error']) {
    assert.ok(!logs.includes(secret),'Private material leaked to local logs');
  }
  writeFileSync(`${fixture}/result.json`,JSON.stringify({phase:'B5.13',production_acceptance:false,passed:checks.length,checks,
    core_base:manifest.base,observed_tls_protocols:[...tlsVersions],state:decorate('state'),audit:JSON.parse(mutate('audit-check'))},null,2));
  process.stdout.write(`PASS ${checks.length} real session Core/TLS/MariaDB checks\n`);
} finally {
  for (const agent of agents) agent.destroy();
  for (const socket of sockets) socket.destroy();
  for (const child of processes) stop(child);
  await sleep(200);
  rmSync(`${fixture}/newcerts`,{recursive:true,force:true});
  for (const file of readdirSync(fixture)) {
    if (!file.endsWith('.log') && !['result.json','public-evidence.json'].includes(file)) {
      try { unlinkSync(`${fixture}/${file}`); } catch {}
    }
  }
}
