/** Private child fixture: keys/envelopes use inherited IPC, never logs or disk. */
import { createSharedOwnerFixture } from './shared-owner.mjs'
import { material, instruments, encode } from './fixtures.mjs'
let owners = [], key, config, envelope, counter, mode
process.on('message', async message => {
  try {
    if (message.type === 'init') {
      key = await material(message.jwk)
      config = { ...message.config, keyPair: key.pair }
      envelope = message.envelope; mode = message.mode
      const size = mode === 'concurrent' ? 16 : 1
      for (let index = 0; index < size; index++) owners.push(await createSharedOwnerFixture(config))
      counter = instruments(); process.send({ type: 'ready' })
    } else if (message.type === 'go') {
      if (mode === 'crash') {
        await owners[0].open(envelope)
        process.send({ type: 'claimed', counts: { ...counter.counts } })
        return // Parent SIGKILL; no seal/reconstruct call.
      }
      let response, winners = 0, refused = 0
      await Promise.all(owners.map(async owner => {
        try {
          const admitted = await owner.open(envelope)
          winners++
          response = await admitted.seal(encode('one fixture response'), { kind: 'result', status: 200 })
        } catch { refused++ }
      }))
      process.send({ type: 'result', winners, refused, counts: { ...counter.counts }, response })
      for (const owner of owners) owner.close()
      counter.close(); process.disconnect()
    }
  } catch {
    for (const owner of owners) owner.close()
    counter?.close(); process.send?.({ type: 'failure' }); process.exitCode = 1; process.disconnect?.()
  }
})
