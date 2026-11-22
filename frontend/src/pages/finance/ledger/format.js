// Number formatting shared by every ledger screen (Trial Balance popup,
// Ledger Details, T-account). One place, so Dr/Cr always reads the same.

export const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** Blank instead of 0.00, so a column of figures reads at a glance. */
export const moneyOrBlank = (n) => (Number(n) > 0 ? money(n) : '')

const isZero = (v) => Math.abs(v) < 0.005

/** Signed net (debit positive) shown the accountant's way: 1,250.00 Dr. */
export const balance = (n) => {
  const v = Number(n || 0)
  if (isZero(v)) return '0.00'
  return `${money(Math.abs(v))} ${v > 0 ? 'Dr' : 'Cr'}`
}

/** Indigo for a debit balance, amber for a credit one, grey for nil. */
export const balanceCls = (n) => {
  const v = Number(n || 0)
  if (isZero(v)) return 'text-slate-500'
  return v > 0 ? 'text-indigo-700' : 'text-amber-700'
}

/** "2026-04-01 → 2026-04-30", "Beginning → Today", or "All dates". */
export const periodLabel = ({ date_from: from, date_to: to } = {}) =>
  from || to ? `${from || 'Beginning'} → ${to || 'Today'}` : 'All dates'

/** A file-name-safe version of an account name. */
export const fileSafe = (s) => String(s ?? '').replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
