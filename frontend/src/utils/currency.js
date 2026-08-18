/**
 * The single definition of how money is written down on screen.
 *
 * Amounts and quantities look identical once printed — 1,234.56 is either a weight or a
 * price, and only the currency tells them apart. So every money figure carries one, and
 * nothing else does. Use <Money /> (components/ui/Money.jsx) to render; use fmtMoney only
 * where a raw string is required (an input value, a title tooltip).
 *
 * Single-currency by design: one deployment, one currency. The backend equivalent is
 * Modules\Inventory\Support\Money, configured from inventory.currency.
 */

/** Short symbol for dense tables and on-screen tags. */
export const CURRENCY = 'Rs'

/** ISO code, for column headings and printed documents. */
export const CURRENCY_CODE = 'LKR'

export const CURRENCY_DECIMALS = 2

/** "1,234.56" — the number alone. Replaces the ~20 copies of `fmt` this file supersedes. */
export const fmtMoney = (n) =>
  Number(n ?? 0).toLocaleString(undefined, {
    minimumFractionDigits: CURRENCY_DECIMALS,
    maximumFractionDigits: CURRENCY_DECIMALS,
  })

/** "Rs 1,234.56" — for the few places a plain string must be unambiguous on its own. */
export const fmtMoneyWithSymbol = (n) => `${CURRENCY} ${fmtMoney(n)}`
