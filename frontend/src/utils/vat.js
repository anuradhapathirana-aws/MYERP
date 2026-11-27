/**
 * Prices are stored BEFORE TAX (price list, SO lines); the invoice adds VAT.
 *
 * DEFAULT_VAT_PCT mirrors the backend's inventory.tax.default_vat_pct
 * (Modules\Inventory\Support\Vat) — the same single-deployment pattern as
 * utils/currency.js. It is used ONLY for on-screen "After Tax" hints; every
 * amount that is saved or billed takes its VAT % from the server.
 */
export const DEFAULT_VAT_PCT = 18

/** Before Tax → After Tax, for display. Null when there is no price. */
export const afterTaxOf = (beforeTax, vatPct = DEFAULT_VAT_PCT) => {
  const n = parseFloat(beforeTax)
  return n > 0 ? n * (1 + (Number(vatPct) || 0) / 100) : null
}
