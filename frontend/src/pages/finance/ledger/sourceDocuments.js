// Where each journal source document opens, keyed by the journal's
// source_type (PostingRole::SOURCE_* on the backend). A source type missing
// here simply shows its number without a link.
//
// To link a new document type: add one line, pointing at its VIEW page (never
// an /edit page: a posted document must not open for editing from a report).
const VIEW_PATHS = {
  grn:                 (id) => `/inventory/goods-received-notes/${id}`,
  invoice:             (id) => `/inventory/invoices/${id}`,
  bill:                (id) => `/finance/bills/${id}`,
  bill_payment:        (id) => `/finance/bill-payments/${id}`,
  bill_payment_return: (id) => `/finance/bill-payments/${id}`,
  bank_deposit:        (id) => `/finance/bank-deposits/${id}`,
}

/** The view page of a ledger row's source document, or null when it has none. */
export const sourceDocumentPath = (row) => {
  const path = VIEW_PATHS[row?.source_type]
  return path && row.source_id ? path(row.source_id) : null
}
