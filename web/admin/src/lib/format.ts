/** Exact decimal GB formatting; 1 GB = 1,000,000,000 bytes. */
export function gigabytes(value: bigint): string {
  const hundredths = (value + 5_000_000n) / 10_000_000n
  return (hundredths / 100n).toString() + '.' + (hundredths % 100n).toString().padStart(2, '0') + ' GB'
}

export function toByteString(value: bigint | string): bigint {
  if (typeof value === 'bigint') return value
  if (!/^(0|[1-9][0-9]{0,39})$/.test(value)) throw new TypeError('Invalid byte value')
  return BigInt(value)
}
