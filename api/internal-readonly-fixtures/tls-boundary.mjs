// Local/testing fixture: real mTLS terminates here; only fixed Core operations can be forwarded.
import https from 'node:https';
import http from 'node:http';
import { readFileSync } from 'node:fs';
import { createHash, createPrivateKey, randomBytes, sign, X509Certificate } from 'node:crypto';

const configPath = process.argv[2];
if (!configPath) throw new Error('Pass the trusted local fixture config file');
const load = () => JSON.parse(readFileSync(configPath, 'utf8'));
const initial = load();
if (initial.environment !== 'testing' || initial.listen_host !== '127.0.0.1'
  || !Number.isInteger(initial.listen_port) || initial.listen_port < 1024 || initial.listen_port > 65535
  || !Number.isInteger(initial.core_port) || initial.core_port < 1024 || initial.core_port > 65535) {
  throw new Error('Only fixed loopback testing ports are supported');
}
const operations = new Map([
  ['/txapi/internal/v1/operations/auth.login', 'user-gateway'],
  ['/txapi/internal/v1/operations/auth.register', 'user-gateway'],
  ['/txapi/internal/v1/operations/auth.password.reset', 'user-gateway'],
  ['/txapi/internal/v1/operations/auth.email-code.send', 'user-gateway'],
  ['/txapi/internal/v1/operations/public.site-config.get', 'user-gateway'],
  ['/txapi/internal/v1/operations/public.plan.list', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.profile.get', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.order.get', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.order.list', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.dashboard.get', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.payment-methods.list', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.subscription-summary.get', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.notice.list', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.order-status.get', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.order.create', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.session.list', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.session.revoke', 'user-gateway'],
  ['/txapi/internal/v1/operations/user.session.logout', 'user-gateway'],
  ['/txapi/internal/v1/operations/admin.order.get', 'admin-gateway'],
]);
const anonymousOperations = new Set(['/txapi/internal/v1/operations/public.site-config.get', '/txapi/internal/v1/operations/public.plan.list', '/txapi/internal/v1/operations/auth.login', '/txapi/internal/v1/operations/auth.email-code.send', '/txapi/internal/v1/operations/auth.register', '/txapi/internal/v1/operations/auth.password.reset']);
const sans = new Map([
  ['DNS:user-gateway.internal', 'user-gateway'],
  ['DNS:admin-gateway.internal', 'admin-gateway'],
]);
const sha = (value) => createHash('sha256').update(value).digest('hex');
const fail = (res, status, code) => {
  if (!res.headersSent) res.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'private, no-store' });
  res.end(JSON.stringify({ error: { code, message: 'Internal boundary rejected request' } }));
};
const leafValid = (cert, now) => cert && now >= Date.parse(cert.validFrom) && now < Date.parse(cert.validTo);
const serverLeaf = new X509Certificate(readFileSync(initial.server_cert));
if (serverLeaf.subjectAltName !== 'DNS:internal-core.local') throw new Error('Invalid server SAN');

const server = https.createServer({
  key: readFileSync(initial.server_key), cert: readFileSync(initial.server_cert),
  ca: readFileSync(initial.client_ca), crl: readFileSync(initial.client_crl),
  requestCert: true, rejectUnauthorized: true, minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3',
}, (req, res) => {
  let config, identity, cert, fingerprint;
  try {
    const nowMs = Date.now();
    cert = req.socket.getPeerX509Certificate();
    identity = sans.get(cert?.subjectAltName);
    fingerprint = cert?.fingerprint256.replaceAll(':', '').toLowerCase();
    config = load(); // Leaf/proof revocation takes effect on existing keepalive connections, too.
    const trust = config.services?.[identity]?.certificates?.[fingerprint];
    if (!req.socket.authorized || !identity || !leafValid(cert, nowMs) || !leafValid(serverLeaf, nowMs)
      || !trust || trust.revoked !== false || nowMs < trust.not_before * 1000 || nowMs >= trust.not_after * 1000) {
      fail(res, 401, 'SERVICE_UNAUTHORIZED');
      req.resume();
      return;
    }
    if (req.method !== 'POST' || !operations.has(req.url)) {
      fail(res, 400, 'UNKNOWN_OPERATION'); req.resume(); return;
    }
    if (operations.get(req.url) !== identity) {
      fail(res, 403, 'SERVICE_FORBIDDEN'); req.resume(); return;
    }
    // Forbid ambiguity in security-critical headers. All caller identity/proof headers are dropped.
    const names = req.rawHeaders.filter((_, i) => i % 2 === 0).map((name) => name.toLowerCase());
    if (['authorization', 'content-type', 'content-length'].some((name) => names.filter((v) => v === name).length > 1)
      || names.includes('transfer-encoding') || (anonymousOperations.has(req.url) ? names.includes('authorization')
        : !/^Bearer [A-Za-z0-9|._-]{1,4089}$(?![\s\S])/.test(req.headers.authorization ?? ''))
      || !/^application\/json(?:;\s*charset=utf-8)?$/i.test(req.headers['content-type'] ?? '')) {
      fail(res, 400, 'INVALID_REQUEST'); req.resume(); return;
    }
  } catch {
    fail(res, 503, 'DEPENDENCY_UNAVAILABLE'); req.resume(); return;
  }
  const chunks = [];
  let size = 0, complete = false;
  const timer = setTimeout(() => { if (!complete) { complete = true; fail(res, 504, 'DEADLINE_EXCEEDED'); req.destroy(); } }, 5000);
  req.on('error', () => clearTimeout(timer));
  req.on('data', (chunk) => {
    size += chunk.length;
    if (size > 16384) { complete = true; clearTimeout(timer); fail(res, 413, 'PAYLOAD_TOO_LARGE'); req.resume(); }
    else if (!complete) chunks.push(chunk);
  });
  req.on('end', () => {
    if (complete) return;
    complete = true;
    clearTimeout(timer);
    const body = Buffer.concat(chunks);
    try {
      const now = Math.floor(Date.now() / 1000);
      const key = config.proof_keys?.[config.service_kids?.[identity]];
      if (!key || key.service !== identity || key.revoked !== false || now < key.not_before || now >= key.not_after) {
        fail(res, 401, 'SERVICE_UNAUTHORIZED'); return;
      }
      const exp = Math.min(now + 10, key.not_after, config.services[identity].certificates[fingerprint].not_after,
        Math.floor(Date.parse(cert.validTo) / 1000));
      if (exp <= now) { fail(res, 401, 'SERVICE_UNAUTHORIZED'); return; }
      const privateKey = createPrivateKey(readFileSync(key.private_key_path));
      if (privateKey.asymmetricKeyType !== 'rsa' || privateKey.asymmetricKeyDetails.modulusLength < 2048) {
        fail(res, 503, 'DEPENDENCY_UNAVAILABLE'); return;
      }
      const header = Buffer.from(JSON.stringify({ alg: 'RS256', typ: 'JWT', kid: config.service_kids[identity] })).toString('base64url');
      const payload = Buffer.from(JSON.stringify({
        v: 'internal-readonly/1', epoch: key.epoch, iss: 'txboard-local-tls-boundary', aud: 'txboard-core-local',
        service: identity, cert_sha256: fingerprint, method: req.method, path: req.url,
        body_sha256: sha(body), actor_sha256: sha(req.headers.authorization ?? ''),
        iat: now, nbf: now - 2, exp, jti: randomBytes(16).toString('hex'),
      })).toString('base64url');
      const signed = `${header}.${payload}`;
      const proof = `${signed}.${sign('RSA-SHA256', Buffer.from(signed), privateKey).toString('base64url')}`;
      let upstreamDone = false;
      let wallTimer;
      const cleanup = () => clearTimeout(wallTimer);
      const cancel = (status, code) => {
        if (upstreamDone) return;
        upstreamDone = true;
        cleanup();
        upstream.destroy();
        if (!res.writableEnded && !res.destroyed) fail(res, status, code);
      };
      const upstream = http.request({ hostname: '127.0.0.1', port: initial.core_port, method: 'POST', path: req.url,
        headers: { ...(anonymousOperations.has(req.url) ? {} : { authorization: req.headers.authorization }), 'content-type': 'application/json',
          'content-length': body.length, 'x-txboard-service-proof': proof }, timeout: 5000,
      }, (reply) => {
        if (upstreamDone) { reply.destroy(); return; }
        const output = []; let bytes = 0;
        reply.on('data', (chunk) => {
          bytes += chunk.length;
          if (bytes > 65536) { cancel(502, 'RESPONSE_TOO_LARGE'); reply.destroy(); }
          else output.push(chunk);
        });
        reply.on('end', () => {
          if (upstreamDone || res.writableEnded || res.destroyed) { cleanup(); upstream.destroy(); return; }
          upstreamDone = true;
          cleanup();
          res.writeHead(reply.statusCode, { 'content-type': 'application/json', 'cache-control': 'private, no-store',
            ...(reply.headers['x-request-id'] ? { 'x-request-id': reply.headers['x-request-id'] } : {}),
            ...(/^(?:[1-9]|[1-5][0-9]|60)$/.test(reply.headers['retry-after'] ?? '')
              ? { 'retry-after': reply.headers['retry-after'] } : {}) });
          res.end(Buffer.concat(output));
          upstream.destroy();
        });
        reply.on('error', () => cancel(503, 'DEPENDENCY_UNAVAILABLE'));
        reply.on('close', () => { if (!reply.complete) cancel(503, 'DEPENDENCY_UNAVAILABLE'); });
      });
      // Socket timeout is only idle time. A separate wall timer also bounds continuous slow chunks/missing end.
      wallTimer = setTimeout(() => cancel(504, 'DEADLINE_EXCEEDED'), 5000);
      upstream.on('timeout', () => cancel(504, 'DEADLINE_EXCEEDED'));
      upstream.on('error', () => cancel(503, 'DEPENDENCY_UNAVAILABLE'));
      res.on('close', () => { upstreamDone = true; cleanup(); upstream.destroy(); });
      req.on('aborted', () => cancel(503, 'DEPENDENCY_UNAVAILABLE'));
      // IncomingMessage also emits close after a normal completed request body; only aborted input cancels.
      req.on('close', () => { if (!req.complete) cancel(503, 'DEPENDENCY_UNAVAILABLE'); });
      upstream.end(body);
    } catch {
      fail(res, 503, 'DEPENDENCY_UNAVAILABLE');
    }
  });
});
server.requestTimeout = 5000;
server.headersTimeout = 5000;
// Long enough to exercise leaf expiry/revocation on an existing connection; still bounded.
server.keepAliveTimeout = 120000;
server.maxHeadersCount = 30;
server.on('tlsClientError', () => {}); // No certificate bodies, request headers or credentials in logs.
server.listen(initial.listen_port, '127.0.0.1', () => process.stdout.write('local mTLS boundary ready\n'));
