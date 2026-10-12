# UDP writer accounting traversal

The default sing-box v1 tracker now counts successful UDP packets written through
pinned sing 0.8.2 `bufio.CopyPacket`. Previously that path delivered packets while
bypassing the tracked writer and its counter. This is an explicit correction to
previously omitted traffic. The event-bucket observer remains opt-in and off in
ordinary Service construction. There is no durable sink, grant gate or v2 submit.

sing's `UnwrapCountPacketWriter` calls generic `UnwrapPacketWriter` before checking
`PacketWriteCounter`. `trackedPacketConn.WriterReplaceable()` now returns false,
so generic traversal stops at the tracked boundary. Counter-aware traversal then
calls its `UnwrapPacketWriter`, receives the underlying writer plus one callback,
and recurses into that underlying writer. It never returns the same tracked
wrapper. Repeated unwrap while collecting cached packets operates on the returned
underlying writer and adds no second callback owner.

The existing UDP direction mapping is preserved:

| Method | Physical direction | v1 counter |
| --- | --- | --- |
| Direct `ReadPacket` | upload | upload |
| Direct `WritePacket` | download | download |
| sing reader callback | upload | download |
| sing writer callback | download | upload |

Thus ordinary `CopyPacket` writer traffic changes from omitted bytes to the
previously declared **legacy upload** counter. With observation enabled it emits
one `UDPWriteCallback` event per successful packet, recording physical download,
legacy upload and `DirectionMismatch`. The callback has a completion timestamp
and unknown I/O interval; it cannot prove authorization or remote Terms ownership.
Neither the reader traversal nor TCP traversal changes.

Counter-aware copy writes to the underlying packet connection and invokes the
original counter only after a successful write. Failed writes add no attempted
bytes. Pinned sing may return nil for a first write failure when the source has
no handshake-failure reporting capability; this fix does not change that behavior.
Its original callback limiter then provides post-write backpressure;
closing the tracked connection cancels that wait. This is not a strict pre-write
lease or rate admission gate. Generic non-counter-aware unwrap now retains the
tracked direct writer, including its existing pre-write limiter and Close
cancellation. Generic writer casting also stops at this boundary, which may
forgo an upstream-specific shortcut. The counter-aware copy can still select
underlying reader optimizations after extracting the single counter owner.

A caller that explicitly obtains `Upstream()` can still bypass these wrappers.
Finite leases require separate verified binding and admission/closure fences.
All optional observations remain unattributable and volatile, including sealed
buckets, with no completion claim based on ActiveCount.

[Verification report](udp-writer-accounting-verification.json) records source and
dependency hashes and two Linux race/vet suites. Tests deliver real loopback UDP
packets through ordinary pinned `CopyPacket` with default and observed trackers,
exercise cached packet re-unwrapping, failed writes, post-write wait cancellation
and generic direct pre-write cancellation. Existing real reader tests verify its
legacy download mapping. The previous
[event-bucket report](local-event-buckets-verification.json) remains unchanged as
historical evidence of the original coverage gap.
