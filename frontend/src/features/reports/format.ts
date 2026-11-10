/** 77.8 → "77.8%"; null (nothing to divide) → "—". */
export function formatRate(rate: number | null): string {
  return rate === null ? '—' : `${rate.toFixed(1)}%`
}

/** Half days → "1.5"; whole days without a decimal. */
export function formatHalfDays(halves: number): string {
  return halves % 2 === 0 ? String(halves / 2) : (halves / 2).toFixed(1)
}

/** 1536 → "1.5 KB". */
export function formatBytes(bytes: number | null): string {
  if (bytes === null) return '—'
  if (bytes < 1024) return `${String(bytes)} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/** 1284 → "1,284". */
export function formatCount(value: number): string {
  return new Intl.NumberFormat().format(value)
}
