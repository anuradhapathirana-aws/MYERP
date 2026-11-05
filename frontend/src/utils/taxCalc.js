/**
 * Applies a tax code to an amount, for live previews on purchase forms.
 *
 * Mirrors the backend's App\Services\TaxCalculator::calculate() exactly — the
 * server re-prices every line on save and its figures are the ones stored. If
 * one changes, change the other (and tests/Unit/TaxCalculatorTest.php).
 *
 * A component is { tax_code_ref, rate, is_compound, is_recoverable }, as
 * returned by GET /tax-codes/options.
 */

const round2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100

/** What 1 before tax becomes after every tax — divide an inclusive total by it to get the base. */
export function inclusiveFactor(components = []) {
  let earlier = 0
  for (const c of components) {
    earlier += (c.is_compound ? 1 + earlier : 1) * Number(c.rate) / 100
  }
  return 1 + earlier
}

/**
 * @param {Array}   components  the code's taxes, in order (empty = no tax)
 * @param {number}  amount      before tax, or the tax-inclusive total when inclusive
 * @param {boolean} inclusive
 * @returns {{ base: number, lines: Array<{ref, rate, base, amount, recoverable}>, taxTotal: number, recoverable: number, nonRecoverable: number, total: number }}
 */
export function calculateTax(components = [], amount = 0, inclusive = false) {
  const value = Number(amount) || 0

  if (!components.length) {
    const base = round2(value)
    return { base, lines: [], taxTotal: 0, recoverable: 0, nonRecoverable: 0, total: base }
  }

  const base  = inclusive ? round2(value / inclusiveFactor(components)) : round2(value)
  const lines = []
  let earlier = 0

  for (const c of components) {
    const on     = c.is_compound ? round2(base + earlier) : base
    const amt    = round2(on * Number(c.rate) / 100)
    lines.push({ ref: c.tax_code_ref, rate: Number(c.rate), base: on, amount: amt, recoverable: Boolean(c.is_recoverable) })
    earlier += amt
  }

  // Inclusive: the printed total wins; a rounding cent goes on the last tax.
  if (inclusive) {
    const drift = round2(round2(value) - base - lines.reduce((s, l) => s + l.amount, 0))
    if (drift !== 0) lines[lines.length - 1].amount = round2(lines[lines.length - 1].amount + drift)
  }

  const taxTotal    = round2(lines.reduce((s, l) => s + l.amount, 0))
  const recoverable = round2(lines.reduce((s, l) => s + (l.recoverable ? l.amount : 0), 0))

  return {
    base,
    lines,
    taxTotal,
    recoverable,
    nonRecoverable: round2(taxTotal - recoverable),
    total:          round2(base + taxTotal),
  }
}

/** "SSCL_PUR 2.5% → VAT 18% on top" — a one-line summary of a code's taxes. */
export function describeComponents(components = []) {
  if (!components.length) return 'No tax'
  return components
    .map((c, i) => `${c.tax_code_ref} ${Number(c.rate)}%${c.is_compound && i > 0 ? ' on top' : ''}${c.is_recoverable ? ' (claimable)' : ' (cost)'}`)
    .join(' → ')
}
