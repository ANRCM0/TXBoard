#!/usr/bin/env node
import { createHash, generateKeyPairSync, randomBytes, sign, verify } from 'node:crypto';
import { constants as fsConstants } from 'node:fs';
import { open, readFile, stat } from 'node:fs/promises';
import https from 'node:https';
import { extname } from 'node:path';
import { pathToFileURL } from 'node:url';
import { MIMEType } from 'node:util';

const nativeFormat = 'native-connection-bundle/v1';
const clientFormats = new Map([
  ['clash/v1', { min: [1, 18, 0], before: [2, 0, 0], mime: 'application/yaml', extension: '.yaml', filename: 'txboard-clash-classic.yaml' }],
  ['clash-meta/v1', { min: [1, 18, 0], before: [2, 0, 0], mime: 'application/yaml', extension: '.yaml', filename: 'txboard-clash.yaml' }],
  ['sing-box/v1', { min: [1, 10, 0], before: [1, 13, 0], mime: 'application/json', extension: '.json', filename: 'txboard-sing-box.json' }],
  ['shadowrocket/v1', { min: [2, 2, 0], before: [3, 0, 0], mime: 'text/plain', extension: '.txt', filename: 'txboard-shadowrocket.txt' }],
  ['surge-ios/v1', { min: [5, 20, 0], before: [6, 0, 0], mime: 'text/plain', extension: '.conf', filename: 'txboard-surge-ios.conf' }],
  ['surge-mac/v1', { min: [6, 7, 0], before: [7, 0, 0], mime: 'text/plain', extension: '.conf', filename: 'txboard-surge-mac.conf' }],
]);
const maxBytes = 1048576;
const requireValue = (condition) => {
  if (!condition) throw new Error('NATIVE_SUBSCRIPTION_CLIENT_REFUSED');
};
function selectFormat(format, clientVersion) {
  if (format === nativeFormat) {
    requireValue(clientVersion === undefined);
    return undefined;
  }
  const rule = clientFormats.get(format);
  requireValue(rule && typeof clientVersion === 'string'
    && /^(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})(?![\s\S])/.test(clientVersion));
  const parts = clientVersion.split('.').map(Number);
  const compare = (other) => {
    for (let index = 0; index < parts.length; index++) {
      if (parts[index] !== other[index]) return parts[index] - other[index];
    }
    return 0;
  };
  requireValue(compare(rule.min) >= 0 && compare(rule.before) < 0);
  return rule;
}
const keys = (value, expected) => requireValue(value && typeof value === 'object'
  && !Array.isArray(value) && Object.keys(value).sort().join('\n') === [...expected].sort().join('\n'));
const decimal = (value) => typeof value === 'string' && /^[1-9][0-9]{0,18}$/.test(value)
  && BigInt(value) <= 9223372036854775807n;
const canonical = (value) => {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonical(value[key])]));
  }
  return value;
};
const same = (left, right) => JSON.stringify(canonical(left)) === JSON.stringify(canonical(right));
const digest = (bytes) => createHash('sha256').update(bytes).digest('hex');
const origin = (value) => {
  const parsed = new URL(value);
  requireValue(parsed.protocol === 'https:' && !parsed.username && !parsed.password
    && parsed.pathname === '/' && !parsed.search && !parsed.hash);
  return parsed.origin;
};
const decode = (value, length) => {
  requireValue(typeof value === 'string' && /^[A-Za-z0-9_-]+$/.test(value));
  const bytes = Buffer.from(value, 'base64url');
  requireValue(bytes.toString('base64url') === value && (length === undefined || bytes.length === length));
  return bytes;
};
const publicKey = (value) => ({
  key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), decode(value, 32)]),
  format: 'der',
  type: 'spki',
});

function post(url, body, ca, bearer, deadline = Date.now() + 15000, document) {
  const encoded = Buffer.from(JSON.stringify(body));
  requireValue(encoded.length <= maxBytes);
  return new Promise((resolve, reject) => {
    let timer;
    const fail = (error) => { clearTimeout(timer); reject(error); };
    const finish = (value) => { clearTimeout(timer); resolve(value); };
    const request = https.request(url, {
      method: 'POST', ca, minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3',
      headers: {
        'Content-Type': 'application/json', Accept: document?.mime ?? 'application/json',
        'Content-Length': encoded.length,
        ...(bearer ? { Authorization: bearer } : {}),
      },
    }, (response) => {
      const chunks = [];
      let count = 0;
      response.on('data', (chunk) => {
        count += chunk.length;
        if (count > maxBytes) {
          response.destroy(new Error('NATIVE_SUBSCRIPTION_RESPONSE_TOO_LARGE'));
          return;
        }
        chunks.push(chunk);
      });
      response.on('error', fail);
      response.on('end', () => {
        try {
          const mime = new MIMEType(response.headers['content-type'] ?? '');
          requireValue(response.statusCode === 200 && count > 0
            && mime.essence === (document?.mime ?? 'application/json')
            && (!response.headers['content-encoding'] || response.headers['content-encoding'] === 'identity'));
          const raw = Buffer.concat(chunks);
          const text = new TextDecoder('utf-8', { fatal: true }).decode(raw);
          if (document) {
            requireValue([...mime.params].every(([name, value]) => name === 'charset' && value.toLowerCase() === 'utf-8')
              && response.headers['content-disposition'] === `attachment; filename="${document.filename}"`
              && response.headers['x-txboard-format'] === document.format
              && response.headers['x-txboard-authorization-digest'] === document.digest
              && !text.includes('\0'));
            if (document.mime === 'application/json') {
              const parsed = JSON.parse(text);
              requireValue(parsed && typeof parsed === 'object' && !Array.isArray(parsed));
            }
            finish(raw);
          } else {
            finish(JSON.parse(text));
          }
        } catch {
          fail(new Error('NATIVE_SUBSCRIPTION_RESPONSE_REFUSED'));
        }
      });
    });
    timer = setTimeout(() => request.destroy(new Error('NATIVE_SUBSCRIPTION_TIMEOUT')),
      Math.max(1, deadline - Date.now()));
    request.setTimeout(15000, () => request.destroy(new Error('NATIVE_SUBSCRIPTION_TIMEOUT')));
    request.on('error', fail);
    request.end(encoded);
  });
}

/** Private keys stay here. Native format returns its bundle; client formats return validated document bytes. */
export async function fetchNativeSubscription({ coreOrigin, providerOrigin, providerId, subjectId, bearer, ca, trust,
  format = nativeFormat, clientVersion }) {
  const document = selectFormat(format, clientVersion);
  const core = origin(coreOrigin);
  const provider = origin(providerOrigin);
  requireValue(decimal(subjectId) && /^[a-z][a-z0-9_-]{0,63}$/.test(providerId)
    && /^Bearer [\x21-\x7e]{1,512}$/.test(bearer));
  keys(trust, ['protocol_version', 'core_instance_id', 'key_id', 'key_epoch', 'algorithm', 'public_key']);
  requireValue(trust.protocol_version === 1 && trust.algorithm === 'Ed25519');
  const pair = generateKeyPairSync('ed25519');
  const recipient = pair.publicKey.export({ format: 'der', type: 'spki' }).subarray(-32).toString('base64url');
  const envelope = await post(`${core}/txapi/me/subscription-deliveries`, {
    access_subject_id: subjectId, recipient_public_key: recipient, format_id: format,
    ...(document ? { client_version: clientVersion } : {}),
  }, ca, bearer);
  const delivery = envelope.data;
  keys(delivery, ['endpoint', 'format_id', 'expires_at', 'proof_scheme', 'authorization']);
  requireValue(delivery.endpoint === `${provider}/subscription-render` && delivery.format_id === format
    && delivery.proof_scheme === 'native-provider-subscription-request/v1');
  keys(delivery.authorization, ['payload', 'signature']);
  const bytes = decode(delivery.authorization.payload);
  requireValue(bytes.length <= maxBytes
    && verify(null, bytes, publicKey(trust.public_key), decode(delivery.authorization.signature, 64)));
  const grant = JSON.parse(bytes.toString('utf8'));
  keys(grant, ['protocol_version', 'policy_version', 'audience', 'core_instance_id', 'key_id', 'key_epoch',
    'issued_at', 'not_before', 'expires_at', 'grant_id', 'provider_id', 'provider_instance_epoch',
    'recipient_public_key', 'format_id', 'user_id', 'subject', 'credential_sha256', 'credential_expires_at', 'nodes',
    ...(document ? ['client_version'] : [])]);
  const now = Math.floor(Date.now() / 1000);
  requireValue(grant.protocol_version === 1 && grant.policy_version === 'native-provider-subscription/v1'
    && grant.audience === 'tx-subscription-client-v1' && grant.core_instance_id === trust.core_instance_id
    && grant.key_id === trust.key_id && grant.key_epoch === trust.key_epoch
    && grant.provider_id === providerId && grant.recipient_public_key === recipient && grant.format_id === format
    && (!document || grant.client_version === clientVersion)
    && grant.subject.access_subject_id === subjectId && grant.subject.installation_id === trust.core_instance_id
    && /^[0-9a-f]{64}$/.test(grant.credential_sha256)
    && Number.isSafeInteger(grant.issued_at) && grant.issued_at >= 0 && Number.isSafeInteger(grant.expires_at)
    && Number.isSafeInteger(grant.not_before) && grant.not_before >= 0
    && grant.not_before <= grant.issued_at && grant.not_before >= grant.issued_at - 30
    && grant.issued_at <= now + 30 && grant.not_before <= now + 30 && grant.expires_at > now
    && grant.expires_at <= grant.issued_at + 30 && grant.expires_at > grant.issued_at
    && grant.credential_expires_at >= grant.expires_at && delivery.expires_at === grant.expires_at
    && Array.isArray(grant.nodes) && grant.nodes.length > 0 && grant.nodes.length <= 128);
  if (['clash/v1', 'surge-ios/v1', 'surge-mac/v1'].includes(format)) {
    requireValue(grant.subject.credential_profile_id === 'vmess-uuid/v1'
      && grant.nodes.every(node => node.protocol === 'vmess' && node.transport === 'tcp'
        && (format !== 'clash/v1' || node.tls === null || Array.isArray(node.tls.alpn) && node.tls.alpn.length === 0)));
  }
  const authorizationDigest = digest(bytes);
  const requestId = `client_${randomBytes(16).toString('hex')}`;
  const proof = sign(null, Buffer.from(`native-provider-subscription-request/v1\n${requestId}\n${authorizationDigest}\n`),
    pair.privateKey).toString('base64url');
  const bundle = await post(delivery.endpoint, {
    contract_version: 'credential-render/1', request_id: requestId, authorization: delivery.authorization, proof,
  }, ca, undefined, Math.min(Date.now() + 15000, grant.expires_at * 1000),
  document ? { ...document, format, digest: authorizationDigest } : undefined);
  requireValue(Date.now() < grant.expires_at * 1000);
  if (document) return bundle;
  keys(bundle, ['contract_version', 'request_id', 'authorization_digest', 'format_id', 'connections']);
  requireValue(bundle.contract_version === 'credential-render/1' && bundle.request_id === requestId
    && bundle.authorization_digest === authorizationDigest && bundle.format_id === format
    && Array.isArray(bundle.connections) && bundle.connections.length === grant.nodes.length
    && Date.now() < grant.expires_at * 1000);
  for (let index = 0; index < bundle.connections.length; index++) {
    const connection = bundle.connections[index];
    keys(connection, ['node', 'access_subject_id', 'credential_ref', 'credential_generation', 'uuid']);
    requireValue(same(connection.node, grant.nodes[index]) && connection.access_subject_id === subjectId
      && connection.credential_ref === grant.subject.credential_ref
      && connection.credential_generation === grant.subject.credential_generation
      && typeof connection.uuid === 'string'
      && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(connection.uuid)
      && digest(connection.uuid) === grant.credential_sha256);
  }
  return bundle;
}

async function main() {
  const values = new Map();
  const required = ['--core-origin', '--provider-origin', '--provider-id', '--subject-id', '--bearer-file',
    '--ca-file', '--trust-file', '--output'];
  for (let index = 2; index < process.argv.length; index += 2) {
    const key = process.argv[index];
    requireValue([...required, '--format', '--client-version'].includes(key)
      && !values.has(key) && process.argv[index + 1]);
    values.set(key, process.argv[index + 1]);
  }
  requireValue(required.every((key) => values.has(key)));
  const format = values.get('--format') ?? nativeFormat;
  const clientVersion = values.get('--client-version');
  const document = selectFormat(format, clientVersion);
  if (document) requireValue(extname(values.get('--output')) === document.extension);
  const bearerPath = values.get('--bearer-file');
  if (process.platform !== 'win32') requireValue(((await stat(bearerPath)).mode & 0o077) === 0);
  const bundle = await fetchNativeSubscription({
    format, clientVersion,
    coreOrigin: values.get('--core-origin'), providerOrigin: values.get('--provider-origin'),
    providerId: values.get('--provider-id'), subjectId: values.get('--subject-id'),
    bearer: (await readFile(bearerPath, 'utf8')).trim(), ca: await readFile(values.get('--ca-file')),
    trust: JSON.parse(await readFile(values.get('--trust-file'), 'utf8')),
  });
  const output = await open(values.get('--output'),
    fsConstants.O_CREAT | fsConstants.O_EXCL | fsConstants.O_WRONLY | (fsConstants.O_NOFOLLOW ?? 0), 0o600);
  try {
    await output.writeFile(document ? bundle : `${JSON.stringify(bundle)}\n`);
    await output.sync();
  } finally {
    await output.close();
  }
  console.log(document ? 'Native subscription client document written.' : 'Native subscription bundle written.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch(() => {
    console.error('NATIVE_SUBSCRIPTION_CLIENT_FAILED');
    process.exitCode = 1;
  });
}
