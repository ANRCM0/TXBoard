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
        try { resolve({ status: res.statusCode, body: JSON.parse(text), text, socket: req.socket, headers: res.headers }); }
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
try {
  core = start('php', ['-S', '127.0.0.1:19081', '-t', 'public', 'public/index.php'], 'core-http', { PHP_CLI_SERVER_WORKERS: '4' });
  proxy = start('node', [`${api}/internal-readonly-fixtures/tls-boundary.mjs`, boundaryPath], 'tls-boundary');
  await ready(true); await ready();
  // Establish before the short-lived leaf expires, then hold the same TLS connection through expiration.
  const shortAgent = new https.Agent({ keepAlive: true, maxSockets: 1, maxCachedSessions: 0 });
  agents.push(shortAgent);
  let shortSocket;
  await check('short-lived real certificate initially authorizes', async () => {
    const result = await status({ cert: 'short', agent: shortAgent }, 200); shortSocket = result.socket;
  });
  await check('real user mTLS and Sanctum profile projection', async () => {
    const result = await status({}, 200);
    assert.equal(result.body.data.id, actors.user_id); assert.equal(result.body.data.balance_minor, '0');
    assert.equal(result.body.meta.current_only, true);
    assert.deepEqual(Object.keys(result.body.data).sort(), ['balance_minor','commission_balance_minor','email','expired_at','id','plan_id','traffic'].sort());
  });
  await check('owned discounted historical order preserves status and exact minor money', async () => {
    const result = await status({ operation: 'user.order.get', input: { trade_no: actors.trade_no } }, 200);
    assert.equal(result.body.data.status, 4); assert.equal(result.body.data.amount_minor, '1200');
  });
  await check('foreign order is hidden by existing Core owner query', () => status({ operation: 'user.order.get', input: { trade_no: actors.trade_no }, token: actors.credentials.other }, 404));
  const adminInput = { id: actors.order_id, admin_path: actors.admin_path };
  await check('real admin service and current Core admin role/path', async () => {
    const result = await status({ role: 'admin', token: actors.credentials.admin, operation: 'admin.order.get', input: adminInput }, 200);
    assert.equal(result.body.data.user_id, actors.user_id);
  });
  await check('wrong current dynamic admin path hidden', () => status({ role: 'admin', token: actors.credentials.admin, operation: 'admin.order.get', input: { ...adminInput, admin_path: 'wrong' } }, 404));
  await check('user service cannot invoke admin operation', () => status({ operation: 'admin.order.get', input: adminInput, token: actors.credentials.admin }, 403));
  await check('admin service cannot invoke user operation', () => status({ role: 'admin', token: actors.credentials.admin }, 403));
  await check('admin service cannot promote user actor', () => status({ role: 'admin', operation: 'admin.order.get', input: adminInput }, 403));
  await check('user service rejects admin actor', () => status({ token: actors.credentials.admin }, 403));
  for (const [name, certificate] of [['missing certificate', null], ['untrusted CA', 'bad-client'], ['expired leaf', 'expired'], ['CRL-revoked leaf', 'revoked']]) {
    await check(`real TLS rejects ${name}`, () => assert.rejects(request({ cert: certificate })));
  }
  for (const certificate of ['wrong-san', 'multi-san']) {
    await check(`exact SAN policy rejects ${certificate}`, () => status({ cert: certificate }, 401));
  }
  await check('client verifies server DNS identity', () => assert.rejects(request({ servername: 'wrong.internal' })));
  await check('visitor identity/proof headers are discarded at TLS termination', async () => {
    const result = await status({ headers: { 'x-client-cert': 'admin', 'x-service-identity': 'admin-gateway', 'x-actor-id': '1', 'x-txboard-service-proof': 'visitor-proof' } }, 200);
    assert.equal(result.body.data.id, actors.user_id);
  });
  await check('direct Core cannot accept bare cert/actor headers', () => status({ direct: true, headers: { 'x-client-cert': 'admin', 'x-service-identity': 'admin-gateway' } }, 401));
  await check('expired Sanctum actor is rejected', () => status({ token: actors.credentials.expired }, 401));
  await check('banned user is reevaluated on every operation', async () => {
    mutate('mutate', 'user', 'ban'); await status({}, 403); mutate('mutate', 'user', 'unban'); await status({}, 200);
  });
  await check('admin role revocation is immediately authoritative', async () => {
    mutate('mutate', 'admin', 'demote'); await status({ role: 'admin', token: actors.credentials.admin, operation: 'admin.order.get', input: adminInput }, 403);
    mutate('mutate', 'admin', 'promote');
  });
  await check('strict input rejects unknown/duplicate fields and large body', async () => {
    await status({ body: makeBody({}, { actor_id: '1' }) }, 400);
    const raw = makeBody(); await status({ body: raw.slice(0, -1) + ',"contract_version":"1"}' }, 400);
    await status({ body: makeBody({ actor_id: '1' }) }, 400);
    await status({ body: ' '.repeat(16385) }, 413);
    await status({ operation: 'not.registered' }, 400);
    await status({ body: makeBody({}, { deadline_at: Math.floor(Date.now() / 1000) }) }, 504);
  });
  await check('cryptographic proof rejects scope, actor/body binding, clock, epoch and unknown key', async () => {
    const raw = makeBody(); const original = claims('user.profile.get', raw, actors.credentials.user);
    const before = mutate('nonce-count').toString();
    for (const patch of [{ body_sha256: '0'.repeat(64) }, { actor_sha256: '0'.repeat(64) }, { path: '/txapi/me' },
      { epoch: 9 }, { aud: 'visitor' }, { iat: original.iat + 30 }, { exp: original.iat - 1 }, { exp: original.iat + 100 }]) {
      await status({ direct: true, body: raw, proof: proof({ ...original, ...patch }) }, 401);
    }
    await status({ direct: true, body: raw, proof: proof(original, 'user', 'user-1', { alg: 'RS256', typ: 'JWT', kid: 'missing' }) }, 401);
    await status({ direct: true, body: raw, proof: proof(original, 'user', 'user-1', '{"alg":"RS256","typ":"JWT","kid":"user-1","kid":"user-1"}') }, 401);
    const encoded = JSON.stringify(original);
    await status({ direct: true, body: raw, proof: proof(encoded.slice(0, -1) + ',"service":"user-gateway"}') }, 401);
    await status({ direct: true, body: raw, proof: proof(original, 'admin', 'admin-1', { alg: 'RS256', typ: 'JWT', kid: 'user-1' }) }, 401);
    assert.equal(mutate('nonce-count').toString(), before, 'Invalid proof must not consume nonce');
  });
  await check('concurrent duplicate proof executes once across real PHP workers', async () => {
    const body = makeBody(); const signed = proof(claims('user.profile.get', body, actors.credentials.user));
    const results = await Promise.all(Array.from({ length: 8 }, () => request({ direct: true, body, proof: signed })));
    assert.equal(results.filter((r) => r.status === 200).length, 1);
    assert.equal(results.filter((r) => r.status === 409).length, 7);
  });
  await check('committed nonce survives real Core worker restart', async () => {
    const body = makeBody(); const signed = proof(claims('user.profile.get', body, actors.credentials.user));
    await status({ direct: true, body, proof: signed }, 200);
    stop(core); await sleep(300);
    core = start('php', ['-S', '127.0.0.1:19081', '-t', 'public', 'public/index.php'], 'core-http', { PHP_CLI_SERVER_WORKERS: '4' });
    await ready(true); await status({ direct: true, body, proof: signed }, 409);
  });
  await check('proof epoch rotation accepts separate new key and rejects revoked old key', async () => {
    trust.proof_keys['user-1'].revoked = true; writeFileSync(trustPath, JSON.stringify(trust));
    await status({}, 401);
    boundary.service_kids['user-gateway'] = 'user-2'; writeFileSync(boundaryPath, JSON.stringify(boundary));
    await status({}, 200);
  });
  await check('leaf trust revocation is checked on established keepalive connection', async () => {
    const agent = new https.Agent({ keepAlive: true, maxSockets: 1, maxCachedSessions: 0 }); agents.push(agent);
    const first = await status({ agent }, 200);
    boundary.services['user-gateway'].certificates[evidence.certificates.user].revoked = true;
    writeFileSync(boundaryPath, JSON.stringify(boundary));
    const denied = await status({ agent }, 401); assert.equal(denied.socket, first.socket);
    boundary.services['user-gateway'].certificates[evidence.certificates.user].revoked = false;
    writeFileSync(boundaryPath, JSON.stringify(boundary));
  });
  await check('unknown storage fault fails closed without SQL/credentials', async () => {
    mutate('storage-off'); await status({}, 503); mutate('storage-on');
  });
  await check('real per-service DB rate window rejects without nonce consumption', async () => {
    mutate('rate-fill'); const count = mutate('nonce-count').toString();
    const result = await status({}, 429);
    assert.ok(Number(result.headers['retry-after']) >= 1 && Number(result.headers['retry-after']) <= 60);
    assert.equal(mutate('nonce-count').toString(), count); mutate('rate-reset');
  });
  const remaining = evidence.short_certificate_expires_at * 1000 - Date.now() + 50;
  if (remaining > 0) { process.stdout.write('waiting for real short-lived certificate expiration on existing TLS connection\n'); await sleep(remaining); }
  await check('real certificate expiry is rejected on the same keepalive TLS connection', async () => {
    const denied = await status({ cert: 'short', agent: shortAgent }, 401); assert.equal(denied.socket, shortSocket);
  });
  await check('Core rejects revoked sessions even with valid service certificate', async () => {
    mutate('mutate', 'user', 'revoke'); await status({}, 401);
  });
  for (const [name, env] of [['default disabled', { CORE_INTERNAL_READONLY_ENABLED: 'false' }],
    ['production cannot enable slice', { APP_ENV: 'production', CORE_INTERNAL_READONLY_ENABLED: 'true' }]]) {
    await check(`real HTTP ${name}`, async () => {
      stop(core); await sleep(300);
      core = start('php', ['-S', '127.0.0.1:19081', '-t', 'public', 'public/index.php'], 'core-http', { PHP_CLI_SERVER_WORKERS: '4', ...env });
      await ready(true); await status({ direct: true }, 404);
    });
  }
  const audit = JSON.parse(mutate('audit-check'));
  const snapshot = JSON.parse(readFileSync(`${api}/../internal-snapshot.json`));
  writeFileSync(`${fixture}/result.json`, JSON.stringify({ current_only: true, environment: 'testing', passed: checks.length, checks,
    committed_core_head: snapshot.committed_core_head, slice_sha256: snapshot.slice_sha256,
    runtime: { node: process.version, php: execFileSync('php', ['-r', 'echo PHP_VERSION;']).toString(),
      database: mutate('database-version').toString() }, ...audit, ...evidence }, null, 2));
  process.stdout.write(`PASS ${checks.length} real mTLS/Core checks\n`);
} finally {
  for (const agent of agents) agent.destroy();
  for (const socket of sockets) socket.destroy();
  for (const child of processes) stop(child);
  // This exact child directory is generated disposable public CA output, never caller-selected.
  rmSync(`${fixture}/newcerts`, { recursive: true, force: true });
  // Retain sanitized evidence/logs; ephemeral private keys, credentials, configs and CA state are removed.
  for (const file of readdirSync(fixture)) {
    if (!file.endsWith('.log') && !['result.json', 'public-evidence.json', 'routes-default.json', 'routes-enabled.json', 'newcerts'].includes(file)) {
      try { unlinkSync(`${fixture}/${file}`); } catch {}
    }
  }
}
