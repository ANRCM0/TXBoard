// Adversarial bounded-response fixture: real TLS boundary, deliberately slow loopback upstream.
import assert from 'node:assert/strict';
import http from 'node:http';
import https from 'node:https';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { spawn, execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { setTimeout as sleep } from 'node:timers/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const api = process.argv[2];
assert.ok(api, 'Pass isolated Core api snapshot');
const dir = mkdtempSync(path.join(tmpdir(), 'txboard-internal-timeout-'));
const script = path.dirname(fileURLToPath(import.meta.url));
let child;
let mode = 'normal', closed = 0;
const sockets = new Set();
const upstream = http.createServer((req, res) => {
  req.resume();
  res.writeHead(200, { 'content-type': 'application/json' });
  if (mode === 'normal') { res.end('{"data":{},"request_id":"test"}'); return; }
  if (mode === 'oversize') { res.end('x'.repeat(65537)); return; }
  if (mode === 'error') { res.flushHeaders(); res.destroy(); return; }
  res.write('{"data":"');
  const timer = mode === 'slow-drip' ? setInterval(() => res.write('x'), 100) : undefined;
  res.on('close', () => { closed++; clearInterval(timer); });
});
upstream.on('connection', (socket) => { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); });
const get = (abort = false) => new Promise((resolve, reject) => {
  const start = Date.now();
  const body = JSON.stringify({ contract_version: '1', request_id: randomUUID(), deadline_at: Math.floor(Date.now()/1000)+5, input: {} });
  const req = https.request({ hostname: '127.0.0.1', port: 19444, servername: 'internal-core.local',
    ca: readFileSync(`${dir}/ca.pem`), key: readFileSync(`${dir}/user.key`), cert: readFileSync(`${dir}/user.pem`),
    agent: false, method: 'POST', path: '/txapi/internal/v1/operations/user.profile.get',
    headers: { authorization: 'Bearer fixture-only-credential', 'content-type': 'application/json', 'content-length': Buffer.byteLength(body) },
  }, (res) => {
    let text = ''; res.on('data', (chunk) => { text += chunk; });
    res.on('end', () => resolve({ status: res.statusCode, code: JSON.parse(text).error?.code, elapsed: Date.now() - start }));
  });
  req.on('error', (error) => abort ? resolve({ aborted: true }) : reject(error));
  req.end(body);
  if (abort) setTimeout(() => req.destroy(new Error('Fixture client disconnect')), 200);
});
try {
  execFileSync('python3', [`${script}/generate-pki.py`, dir]);
  const config = JSON.parse(readFileSync(`${dir}/boundary.json`));
  config.listen_port = 19444; config.core_port = 19082;
  writeFileSync(`${dir}/boundary.json`, JSON.stringify(config));
  await new Promise((resolve) => upstream.listen(19082, '127.0.0.1', resolve));
  child = spawn('node', [`${api}/internal-readonly-fixtures/tls-boundary.mjs`, `${dir}/boundary.json`], { stdio: ['ignore', 'ignore', 'ignore'] });
  for (let i = 0; i < 40; i++) { try { assert.equal((await get()).status, 200); break; } catch { await sleep(100); } }
  const checks = [];
  for (const value of ['slow-drip', 'missing-end']) {
    mode = value; const before = closed; const result = await get();
    assert.equal(result.status, 504); assert.equal(result.code, 'DEADLINE_EXCEEDED');
    assert.ok(result.elapsed >= 4500 && result.elapsed < 6500);
    await sleep(100); assert.ok(closed > before, 'Wall timeout must close upstream');
    checks.push(`${value}: walltime limited and upstream closed`);
  }
  mode = 'oversize'; assert.equal((await get()).status, 502); checks.push('oversize closes upstream');
  mode = 'error'; assert.equal((await get()).status, 503); checks.push('upstream abort cleans timer');
  mode = 'missing-end'; const before = closed; await get(true); await sleep(200);
  assert.ok(closed > before); checks.push('client disconnect closes upstream');
  mode = 'normal'; assert.equal((await get()).status, 200); checks.push('normal end succeeds after rejected responses');
  process.stdout.write(JSON.stringify({ current_only: true, passed: checks.length, checks })+'\n');
} finally {
  child?.kill('SIGTERM');
  for (const socket of sockets) socket.destroy();
  upstream.close();
  // mkdtemp-created directory holds only this fixture's temporary CA/keys; remove after stopping server.
  rmSync(dir, { recursive: true, force: true });
}
