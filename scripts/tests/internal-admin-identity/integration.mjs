import assert from 'node:assert/strict';
import https from 'node:https';
import http from 'node:http';
import { readFileSync, writeFileSync, openSync, closeSync, unlinkSync, readdirSync, rmSync } from 'node:fs';
import { createHash, createPrivateKey, sign, randomBytes, randomUUID } from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import { setTimeout as sleep } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const api = process.env.INTERNAL_CORE_API, fixture = process.env.INTERNAL_FIXTURE_DIR;
assert.ok(api && fixture);
const directory = path.dirname(fileURLToPath(import.meta.url));
const actors = JSON.parse(readFileSync(`${fixture}/actors.json`));
const publicEvidence = JSON.parse(readFileSync(`${fixture}/public-evidence.json`));
const ca = readFileSync(`${fixture}/ca.pem`), operation = 'admin.identity.get';
const route = `/txapi/internal/v1/operations/${operation}`;
const children = [], checks = [];
const sha = value => createHash('sha256').update(value).digest('hex');
let adminPath = actors.admin_path;
function start(command, args, label) {
  const log = openSync(`${fixture}/${label}.log`, 'a', 0o600);
  const child = spawn(command, args, {cwd: api, env: {...process.env, PHP_CLI_SERVER_WORKERS: '4'},
    detached: true, stdio: ['ignore', log, log]});
  closeSync(log); children.push(child); return child;
}
function stop(child) { try { process.kill(-child.pid, 'SIGTERM'); } catch {} }
const mutate = (...args) => execFileSync('php', [`${directory}/fixture.php`, ...args], {cwd: api, env: process.env});
const makeBody = (input = {admin_path: adminPath}) => JSON.stringify({contract_version: '1',
  request_id: randomUUID(), deadline_at: Math.floor(Date.now()/1000)+5, input});
function proof(body, token) {
  const now = Math.floor(Date.now()/1000);
  const header = Buffer.from(JSON.stringify({alg: 'RS256', typ: 'JWT', kid: 'admin-1'})).toString('base64url');
  const payload = Buffer.from(JSON.stringify({v: 'internal-readonly/1', epoch: 1, iss: 'txboard-local-tls-boundary',
    aud: 'txboard-core-local', service: 'admin-gateway', cert_sha256: publicEvidence.certificates.admin,
    method: 'POST', path: route, body_sha256: sha(body), actor_sha256: sha(token),
    iat: now, nbf: now-2, exp: now+10, jti: randomBytes(16).toString('hex')})).toString('base64url');
  const unsigned = `${header}.${payload}`;
  return `${unsigned}.${sign('RSA-SHA256', Buffer.from(unsigned), createPrivateKey(readFileSync(`${fixture}/admin-1.proof.key`))).toString('base64url')}`;
}
function request({cert = 'admin', token = actors.credentials.admin, body = makeBody(), direct = false,
  serviceProof, headers = {}} = {}) {
  return new Promise((resolve, reject) => {
    const transport = direct ? http : https;
    const tls = direct ? {hostname: '127.0.0.1', port: 19081} : {hostname: '127.0.0.1', port: 19443,
      servername: 'internal-core.local', ca, minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3', agent: false,
      ...(cert ? {key: readFileSync(`${fixture}/${cert}.key`), cert: readFileSync(`${fixture}/${cert}.pem`)} : {})};
    const req = transport.request({...tls, method: 'POST', path: route,
      headers: {'content-type': 'application/json', authorization: token, 'content-length': Buffer.byteLength(body),
        ...(serviceProof ? {'x-txboard-service-proof': serviceProof} : {}), ...headers}, timeout: 6000}, res => {
      const chunks = []; let size = 0;
      res.on('data', chunk => {size += chunk.length; if (size > 65536) req.destroy(new Error('Unbounded fixture response')); else chunks.push(chunk);});
      res.on('error', reject);
      res.on('end', () => {
        try {const text = Buffer.concat(chunks).toString('utf8'); resolve({status: res.statusCode, body: JSON.parse(text), text,
          headers: res.headers, tls: req.socket?.getProtocol?.()});} catch {reject(new Error('Expected bounded JSON fixture response'));}
      });
    });
    req.on('error', reject); req.on('timeout', () => req.destroy(new Error('Fixture timeout'))); req.end(body);
  });
}
async function status(options, expected) {
  const result = await request(options);
  assert.equal(result.status, expected, `Expected ${expected}, received ${result.status} (${result.body.error?.code ?? 'success'})`);
  for (const secret of [...Object.values(actors.credentials), actors.admin_path, 'rotated-identity-fixture',
    'private-identity-token', 'PRIVATE KEY', 'synthetic-private-audit-failure']) {
    assert.ok(!result.text.includes(secret), 'Response exposed fixture secret');
  }
  return result;
}
async function check(name, run) {await run(); checks.push(name); process.stdout.write(`ok ${checks.length}: ${name}\n`);}
async function ready() {
  for (let i=0;i<40;i++) {try {await request(); return;} catch {await sleep(100);}}
  throw new Error('Loopback fixture failed to start');
}
try {
  start('php', ['-S', '127.0.0.1:19081', '-t', 'public', 'public/index.php'], 'core-http');
  start('node', [`${api}/internal-readonly-fixtures/tls-boundary.mjs`, `${fixture}/boundary.json`], 'tls-boundary');
  await ready();
  await check('real Admin mTLS and current Core identity', async () => {
    const result = await status({}, 200);
    assert.equal(result.tls, 'TLSv1.3');
    assert.deepEqual(result.body.data, {id: actors.ids.admin, email: 'identity-admin@example.test', is_admin: true});
    assert.deepEqual(result.body.meta, {current_only: true, slice: 'internal-admin-identity/1'});
    assert.match(result.headers['cache-control'], /no-store/);
  });
  await check('User service certificate cannot call Admin operation', () => status({cert: 'user'}, 403));
  await check('ordinary User and staff credentials cannot borrow Admin service', async () => {
    await status({token: actors.credentials.user, headers: {'x-actor-id': actors.ids.admin, 'x-actor-role': 'admin'}}, 403);
    await status({token: actors.credentials.staff}, 403);
  });
  await check('expired and retired Agent credentials cannot authenticate', async () => {
    for (const key of ['expired', 'agent', 'renamed_agent']) await status({token: actors.credentials[key]}, 401);
  });
  await check('wrong management path is absent and never echoed', () => status({body: makeBody({admin_path: 'wrong'})}, 404));
  await check('caller identity and duplicate input fields rejected', async () => {
    await status({body: makeBody({admin_path: adminPath, actor_id: actors.ids.admin})}, 400);
    const duplicate = makeBody().replace('"input":{', '"input":{"admin_path":"duplicate",');
    await status({body: duplicate}, 400);
  });
  await check('current ban and demotion rechecked across requests', async () => {
    mutate('mutate', 'ban'); await status({}, 403); mutate('mutate', 'unban');
    mutate('mutate', 'demote'); await status({}, 403); mutate('mutate', 'promote'); await status({}, 200);
  });
  await check('native management path rotation takes effect', async () => {
    mutate('mutate', 'rotate-path'); await status({}, 404);
    adminPath = 'rotated-identity-fixture'; await status({}, 200);
  });
  await check('direct unsigned Core request cannot bypass mTLS proof', () => status({direct: true}, 401));
  await check('bound proof replay rejected without another audit', async () => {
    const body = makeBody(), serviceProof = proof(body, actors.credentials.admin);
    const before = JSON.parse(mutate('audit-check')).audit_rows;
    await status({direct: true, body, serviceProof}, 200);
    await status({direct: true, body, serviceProof}, 409);
    assert.equal(JSON.parse(mutate('audit-check')).audit_rows, before+1);
  });
  await check('real audit INSERT failure hides identity with fixed 503', async () => {
    mutate('audit-fail'); const result = await status({}, 503);
    assert.equal(result.body.error.code, 'DEPENDENCY_UNAVAILABLE');
    mutate('audit-restore'); await status({}, 200);
  });
  await check('current Admin token revocation rejects subsequent admission', async () => {
    mutate('mutate', 'revoke'); await status({}, 401);
  });
  const state = JSON.parse(mutate('audit-check'));
  writeFileSync(`${fixture}/result.json`, JSON.stringify({checks, passed: checks.length, tls: 'TLSv1.3',
    core_operation: operation, ...state, synthetic_only: true, no_gateway_admin_route: true}, null, 2)+'\n');
  process.stdout.write(`Passed ${checks.length} real Admin identity checks; sanitized evidence retained.\n`);
} finally {
  children.forEach(stop);
  await sleep(100);
  // Never retain credentials, keys, CA private state or configs referencing keys.
  for (const name of readdirSync(fixture)) {
    if (name.endsWith('.key') || name.endsWith('.csr') || name.endsWith('.ext') || name.endsWith('.cnf')
      || ['actors.json','boundary.json','core-trust.json','index.txt','index.txt.attr','serial','crlnumber'].some(prefix => name.startsWith(prefix))) {
      try {unlinkSync(`${fixture}/${name}`);} catch {}
    }
  }
  rmSync(`${fixture}/newcerts`, {recursive: true, force: true});
}
