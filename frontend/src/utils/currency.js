/**
 * The single definition of how money is written down — and rounded — on screen.
 *
 * Amounts and quantities look identical once printed — 1,234.56 is either a weight or a
 * price, and only the currency tells them apart. So every money figure carries one, and
 * nothing else does. Use <Money /> (components/ui/Money.jsx) to render; use fmtMoney only
 * where a raw string is required (an input value, a title tooltip).
 *
 * Single-currency by design: one deployment, one currency. The backend equivalent is
 * Modules\Inventory\Support\Money, configured from inventory.currency.
 *
 * The number of decimals is the system's Amount decimals setting (System Settings >
 * Number Format), sent by /api/login and /api/me as `amount_decimals`. roundAmount()
 * mirrors the backend's App\Support\Money\Amount::round(), so what a form shows is
 * exactly what the server stores. Prices that are costs (PO / GRN unit price, product
 * cost) and quantities keep their own precision — never pass them through here.
 */

/** Short symbol for dense tables and on-screen tags. */
export const CURRENCY = 'Rs'

/** ISO code, for column headings and printed documents. */
export const CURRENCY_CODE = 'LKR'

const STORAGE_KEY = 'amount_decimals'
const DEFAULT_DECIMALS = 2
const MAX_DECIMALS = 2

const clampDecimals = (v) => {
  const n = Number.parseInt(v, 10)
  return Number.isInteger(n) && n >= 0 && n <= MAX_DECIMALS ? n : DEFAULT_DECIMALS
}

const readStored = () => {
  try {
    return clampDecimals(localStorage.getItem(STORAGE_KEY))
  } catch {
    return DEFAULT_DECIMALS
  }
}

// Read once and kept in memory: fmtMoney runs for every cell of every table.
let decimals = readStored()
let formatter = null

/** The Amount decimals setting in force (0–2; 2 until the server says otherwise). */
export const amountDecimals = () => decimals

/** Called with what /api/login or /api/me returned, or after a super admin changes it. */
export function setAmountDecimals(value) {
  const next = clampDecimals(value)
  try { localStorage.setItem(STORAGE_KEY, String(next)) } catch { /* private window */ }
  if (next !== decimals) {
    decimals = next
    formatter = null
  }
}

/**
 * Half away from zero to `places` decimals, exact for decimal input: 10.33335 -> 10.3334,
 * 1.005 -> 1.01 (as PHP round() and MySQL DECIMAL do). Shifting through the decimal
 * exponent ("1.005e2") avoids the binary error of 1.005 * 100 = 100.49999…
 */
const roundTo = (n, places) => {
  const v = Number(n) || 0
  const abs = Math.abs(v)
  const shifted = /e/i.test(String(abs))
    ? Math.round(abs * 10 ** places)
    : Math.round(Number(`${abs}e${places}`))
  const r = Number(`${shifted}e-${places}`)
  return v < 0 && r !== 0 ? -r : r
}

/** Half away from zero to the setting — the backend's Amount::round(). */
export const roundAmount = (n) => roundTo(n, decimals)

/** The smallest amount the setting can hold: 0.01 at 2 decimals, 1 at 0. */
export const amountUnit = () => 10 ** -decimals

/** Half a unit — two amounts closer than this are the same amount. */
export const amountTolerance = () => amountUnit() / 2

/** True when two amounts are equal at the setting's precision — the backend's Amount::equals(). */
export const amountsEqual = (a, b) => Math.abs(roundAmount(a) - roundAmount(b)) < amountTolerance()

/** An amount as whole minor units (cents at 2 decimals), for exact integer sums. */
export const toMinor = (v) => Math.round(roundAmount(v) * 10 ** decimals)

/** Minor units back to a plain input string: "1234.50" (no thousands separators). */
export const fromMinor = (m) => (m / 10 ** decimals).toFixed(decimals)

/** Minor units for display: "1,234.50". */
export const fmtMinor = (m) => fmtMoney(m / 10 ** decimals)

/** True when a typed string holds no more decimals than the setting allows. */
export const hasAmountPrecision = (v) => {
  const s = String(v ?? '').trim()
  const dot = s.indexOf('.')
  return dot === -1 || s.length - dot - 1 <= decimals
}

/** True for a typed, non-negative amount within the setting's decimals: "1250", "1250.5". */
export const isPlainAmount = (v) => /^\d+(\.\d+)?$/.test(String(v ?? '').trim()) && hasAmountPrecision(v)

/** The decimals rule in words, for validation messages: "at most 2 decimals" / "no decimals". */
export const amountDecimalsRule = () => (decimals === 0
  ? 'no decimals'
  : `at most ${decimals} decimal${decimals === 1 ? '' : 's'}`)

/** "1234.50" — rounded to the setting, as an input value or API payload. */
export const fixedAmount = (n) => roundAmount(n).toFixed(decimals)

/**
 * An amount typed into an input, rounded for when the field is left: "2775.124" -> "2775.12".
 * Blank stays blank, and junk is left for the field's own validation to report.
 */
export const roundAmountInput = (v) => {
  if (v == null || String(v).trim() === '') return ''
  const n = Number(v)
  return Number.isFinite(n) ? String(roundAmount(n)) : String(v)
}

/** The `step` for a money input: 0.01 at 2 decimals, 1 at 0. */
export const amountStep = () => String(amountUnit())

// ── Unit prices and costs ──────────────────────────────────────────────────
// Kept apart from the Amount decimals setting, as every ERP does ("unit-amount
// decimals"): a supplier's 10.3333/m stays 10.3333, only qty x price rounds as
// an amount. 4 = the decimal(15,4) unit_price columns (PO, GRN, SO, Invoice).

export const UNIT_PRICE_DECIMALS = 4

/**
 * A unit price/cost as an input should hold it: exactly as stored, up to 4 decimals,
 * trailing zeros dropped — "10.3333", "125.5", "80". Blank (or junk) gives ''.
 */
export const unitPriceText = (v) => {
  if (v == null || String(v).trim() === '') return ''
  const n = Number(v)
  return Number.isFinite(n) ? String(roundTo(n, UNIT_PRICE_DECIMALS)) : ''
}

/** onBlur for a unit price/cost input: 4 decimals at most, the column's own limit; junk left for validation. */
export const unitPriceInput = (v) => {
  if (v == null || String(v).trim() === '') return ''
  return Number.isFinite(Number(v)) ? unitPriceText(v) : String(v)
}

/** "1,234.56" — the number alone. Replaces the ~20 copies of `fmt` this file supersedes. */
export const fmtMoney = (n) => {
  formatter ??= new Intl.NumberFormat(undefined, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
  return formatter.format(Number(n ?? 0) || 0)
}

/** "Rs 1,234.56" — for the few places a plain string must be unambiguous on its own. */
export const fmtMoneyWithSymbol = (n) => `${CURRENCY} ${fmtMoney(n)}`
