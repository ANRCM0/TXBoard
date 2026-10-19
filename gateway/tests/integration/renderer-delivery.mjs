import assert from 'node:assert/strict'
import { createHash, generateKeyPairSync, sign, verify } from 'node:crypto'
import { readFileSync, writeFileSync, statSync, symlinkSync } from 'node:fs'
import { createServer, request } from 'node:https'
import { spawn, spawnSync } from 'node:child_process'
import { resolve } from 'node:path'

const formats = [
  ['clash/v1', '1.18.0', '.yaml', 'application/yaml', 'txboard-clash-classic.yaml'],
  ['clash-meta/v1', '1.18.0', '.yaml', 'application/yaml', 'txboard-clash.yaml'],
  ['sing-box/v1', '1.10.0', '.json', 'application/json', 'txboard-sing-box.json'],
  ['shadowrocket/v1', '2.2.0', '.txt', 'text/plain', 'txboard-shadowrocket.txt'],
  ['surge-ios/v1', '5.20.0', '.conf', 'text/plain', 'txboard-surge-ios.conf'],
  ['surge-mac/v1', '6.7.0', '.conf', 'text/plain', 'txboard-surge-mac.conf'],
]
const digest = bytes => createHash('sha256').update(bytes).digest('hex')

export async function verifyRendererDelivery(client, other, { root, scratch, nonce, actorId, token, servers }) {
  let checks = 0
  function fixture(action, ...args) {
    const run = spawnSync('php', ['tests/integration/core-fixture.php', root, action, actorId, ...args],
      { encoding: 'utf8', timeout: 30000 })
    assert.equal(run.status, 0, run.stderr || 'Delivery fixture failed')
  }
  fixture('delivery-owner')
  const options = await client.call('subscription.delivery-options.get', {})
  assert.equal(options.authorized, true, options.reason)
  assert.deepEqual(options.formats.map(row => row.format_id), formats.map(row => row[0]))
  const subject = options.subjects.find(row => row.credential_profile_id === 'vmess-uuid/v1')
  const vless = options.subjects.find(row => row.credential_profile_id === 'vless-uuid/v1')
  assert.ok(subject && vless)
  assert.ok(!JSON.stringify(options).includes('credential_ref')); checks++
  const ca = readFileSync(`${scratch}/ca.pem`)
  const publicProxy = createServer({ key: readFileSync(`${scratch}/server.key`), cert: readFileSync(`${scratch}/server.pem`),
    minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3' }, async (req, res) => {
    if (req.method !== 'POST' || req.url !== '/txapi/me/subscription-deliveries') {
      res.writeHead(404); res.end(); return
    }
    try {
      const chunks = []
      for await (const chunk of req) chunks.push(chunk)
      const response = await fetch(`http://127.0.0.1:18081${req.url}`, { method: 'POST', redirect: 'error',
        headers: { 'content-type': 'application/json', authorization: req.headers.authorization ?? '', 'x-ci-tls-nonce': nonce },
        body: Buffer.concat(chunks), signal: AbortSignal.timeout(15000) })
      res.writeHead(response.status, { 'content-type': response.headers.get('content-type') || 'application/json' })
      res.end(Buffer.from(await response.arrayBuffer()))
    } catch { res.writeHead(502); res.end() }
  })
  await new Promise(r => publicProxy.listen(18090, '127.0.0.1', r)); servers.push(publicProxy)
  function wire(method, body, headers = {}) {
    return new Promise((done, reject) => {
      const req = request(subject.endpoint, { method, ca, minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3',
        headers: { 'content-type': 'application/json', ...headers } }, res => {
        const chunks = []
        res.on('data', chunk => chunks.push(chunk)); res.on('error', reject)
        res.on('end', () => done({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) }))
      })
      req.setTimeout(15000, () => req.destroy(new Error('Provider wire timeout')))
      req.on('error', reject); req.end(body === undefined ? undefined : JSON.stringify(body))
    })
  }
  const preflight = await wire('OPTIONS', undefined, { Origin: 'https://panel.example',
    'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'content-type' })
  assert.equal(preflight.status, 204); assert.equal(preflight.body.length, 0)
  assert.equal(preflight.headers['access-control-allow-origin'], 'https://panel.example'); checks++
  const denied = await wire('OPTIONS', undefined, { Origin: 'https://other.example',
    'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'content-type' })
  assert.equal(denied.status, 403); assert.equal(denied.headers['access-control-allow-origin'], undefined); checks++
  const pair = generateKeyPairSync('ed25519')
  const recipient = pair.publicKey.export({ format: 'der', type: 'spki' }).subarray(-32).toString('base64url')
  const input = { access_subject_id: subject.access_subject_id, recipient_public_key: recipient,
    format_id: 'clash-meta/v1', client_version: '1.18.0' }
  await assert.rejects(other.call('subscription.deliveries.authorize', input), e => e.status === 404); checks++
  await assert.rejects(client.call('subscription.deliveries.authorize', { ...input, client_version: '2.0.0' }), e => e.status === 422); checks++
  await assert.rejects(client.call('subscription.deliveries.authorize', { ...input,
    access_subject_id: vless.access_subject_id, format_id: 'clash/v1' }), e => e.status === 409); checks++
  const bearerPath = `${scratch}/renderer-bearer.private.txt`, trustPath = `${scratch}/renderer-trust.json`
  writeFileSync(bearerPath, token, { mode: 0o600, flag: 'wx' })
  writeFileSync(trustPath, JSON.stringify(options.trust), { mode: 0o600, flag: 'wx' })
  async function cli(format, version, output) {
    const args = [resolve('../scripts/native-subscription-client.mjs'), '--core-origin', 'https://127.0.0.1:18090',
      '--provider-origin', 'https://127.0.0.1:18085', '--provider-id', subject.provider_id,
      '--subject-id', subject.access_subject_id, '--bearer-file', bearerPath, '--ca-file', `${scratch}/ca.pem`,
      '--trust-file', trustPath, '--output', output, '--format', format]
    if (version) args.push('--client-version', version)
    return new Promise((done, reject) => {
      const child = spawn(process.execPath, args, { stdio: ['ignore', 'pipe', 'pipe'] })
      let stdout = '', stderr = ''
      child.stdout.on('data', bytes => { stdout += bytes }); child.stderr.on('data', bytes => { stderr += bytes })
      const timeout = setTimeout(() => child.kill(), 20000)
      child.on('error', error => { clearTimeout(timeout); reject(error) })
      child.on('exit', code => { clearTimeout(timeout); done({ code, stdout, stderr }) })
    })
  }
  let lastRequest, lastOutput
  for (const [format, version, extension, mime, filename] of formats) {
    const grant = await client.call('subscription.deliveries.authorize', { ...input, format_id: format, client_version: version })
    const payload = Buffer.from(grant.grant.payload, 'base64url'), policy = JSON.parse(payload)
    assert.equal(verify(null, payload, { format: 'der', type: 'spki',
      key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(options.trust.public_key, 'base64url')]) },
    Buffer.from(grant.grant.signature, 'base64url')), true)
    assert.equal(policy.format_id, format); assert.equal(policy.client_version, version)
    assert.equal(policy.user_id, actorId); assert.equal(policy.recipient_public_key, recipient)
    const authorizationDigest = digest(payload), requestId = `renderer_${format.split('/')[0]}`
    const proof = sign(null, Buffer.from(`native-provider-subscription-request/v1\n${requestId}\n${authorizationDigest}\n`),
      pair.privateKey).toString('base64url')
    const body = { contract_version: 'credential-render/1', request_id: requestId, authorization: grant.grant, proof }
    const response = await wire('POST', body, { Origin: 'https://panel.example' })
    assert.equal(response.status, 200)
    assert.equal(response.headers['access-control-allow-origin'], 'https://panel.example')
    assert.equal(response.headers['x-txboard-format'], format)
    assert.equal(response.headers['x-txboard-authorization-digest'], authorizationDigest)
    assert.equal(response.headers['content-disposition'], `attachment; filename="${filename}"`)
    assert.equal(response.headers['content-type'].split(';')[0], mime)
    const text = response.body.toString('utf8')
    assert.ok(text.length > 20); assert.ok(!text.includes('credential_ref'))
    if (format === 'sing-box/v1') assert.equal(JSON.parse(text).outbounds[1].type, 'vmess')
    if (format.startsWith('surge-')) {
      assert.equal(text.match(/\[Rule\]/g).length, 1)
      assert.ok(!text.includes('# injection'))
    }
    const output = `${scratch}/download-${format.split('/')[0]}${extension}`
    const downloaded = await cli(format, version, output)
    assert.equal(downloaded.code, 0, downloaded.stderr)
    assert.equal(digest(readFileSync(output)), digest(response.body))
    assert.equal(statSync(output).mode & 0o777, 0o600)
    assert.equal(downloaded.stdout.trim(), 'Native subscription client document written.')
    lastRequest = body; lastOutput = output; checks += 3
  }
  const before = digest(readFileSync(lastOutput))
  assert.equal((await cli('surge-mac/v1', '6.7.0', lastOutput)).code, 1)
  assert.equal(digest(readFileSync(lastOutput)), before); checks++
  const symlink = `${scratch}/download-symlink.conf`
  symlinkSync(lastOutput, symlink)
  assert.equal((await cli('surge-mac/v1', '6.7.0', symlink)).code, 1)
  assert.equal(digest(readFileSync(lastOutput)), before); checks++
  const nativeOutput = `${scratch}/download-native.json`
  assert.equal((await cli('native-connection-bundle/v1', undefined, nativeOutput)).code, 0)
  const native = JSON.parse(readFileSync(nativeOutput, 'utf8'))
  assert.equal(native.format_id, 'native-connection-bundle/v1'); assert.ok(native.connections.length > 0); checks++
  const wrongProof = { ...lastRequest, proof: Buffer.alloc(64).toString('base64url') }
  const rejected = await wire('POST', wrongProof)
  assert.equal(rejected.status, 403); assert.ok(!rejected.body.toString().includes(native.connections[0].uuid)); checks++
  fixture('revoke-render-subject', subject.access_subject_id)
  const revoked = await wire('POST', lastRequest)
  assert.ok([403, 409].includes(revoked.status)); assert.ok(!revoked.body.toString().includes(native.connections[0].uuid)); checks++
  await assert.rejects(client.call('subscription.deliveries.authorize', input), e => e.status === 409); checks++
  return checks
}
