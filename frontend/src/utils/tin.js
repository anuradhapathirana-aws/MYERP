/**
 * Sri Lanka IRD: a VAT registration number is the 9-digit TIN followed by the
 * VAT tax-type code 7000 (e.g. 123456789-7000). Mirrors
 * CustomerMaster::isVatRegisteredTin on the backend — spaces and dashes are
 * ignored, and the check is strict (exactly 9 + 4 digits).
 */
export const isVatRegisteredTin = (tin) => /^\d{9}7000$/.test(String(tin ?? '').replace(/[\s-]/g, ''))

/** Shown when a Tax document is chosen for a customer who is not VAT registered. */
export const NOT_VAT_REGISTERED_WARNING =
  'This customer is not VAT registered (TIN must end in 7000). A tax invoice must state the purchaser\'s VAT number — check before issuing.'
