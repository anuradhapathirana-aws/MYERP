/** QR labels encode "{frontend_url}/inventory/pieces/{piece_code}" — accept both the raw code and the full URL (same rule as the web SO form). */
export function normalizeScannedCode(raw) {
  const value = String(raw ?? '').trim()
  if (!value) return ''
  if (value.includes('/')) {
    const segments = value.split('/').filter(Boolean)
    return segments[segments.length - 1] ?? ''
  }
  return value
}

/** Same single-currency convention as the web's utils/currency.js. */
export const CURRENCY = 'Rs'

export function fmtMoney(v) {
  const n = Number(v)
  if (!isFinite(n)) return '0.00'
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** "Rs 1,234.56" — money must be distinguishable from weights at a glance. */
export const fmtRs = (v) => `${CURRENCY} ${fmtMoney(v)}`

/** Quantities/weights: up to 3 decimals, trailing zeros trimmed. */
export function fmtQty(v) {
  const n = Number(v)
  if (!isFinite(n)) return '0'
  return n.toLocaleString('en-US', { maximumFractionDigits: 3 })
}

export function today() {
  return new Date().toISOString().slice(0, 10)
}

export const isValidDate = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s) && !isNaN(Date.parse(s))
