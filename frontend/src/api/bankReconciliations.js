import api from './axios'

const BASE = '/api/v1/bank-reconciliations'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getBankReconciliations = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

export const getBankReconciliation = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data?.data)

/** Always saves a draft; the server re-reads every line from the ledger. */
export const createBankReconciliation = (payload) =>
  api.post(BASE, payload).then((r) => r.data?.data)

export const updateBankReconciliation = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data?.data)

export const deleteBankReconciliation = (id) =>
  api.delete(`${BASE}/${id}`)

/** Freezes the matching. Refused unless the variance is 0.00. */
export const postBankReconciliation = (id) =>
  api.post(`${BASE}/${id}/post`).then((r) => r.data?.data)

/** Undo the latest posted reconciliation of the account: its lines become uncleared again. */
export const voidBankReconciliation = (id, reason) =>
  api.post(`${BASE}/${id}/void`, { reason }).then((r) => r.data?.data)

/** The Bank Reconciliation Statement as a PDF blob (drafts print too, marked DRAFT). */
export const downloadBankReconciliationPdf = (id) =>
  api.get(`${BASE}/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)

export const getBankReconciliationHistory = (id) =>
  api.get(`${BASE}/${id}/history`).then((r) => r.data?.data ?? [])

/** Companies, bank accounts, statuses, lock date, maker-checker, accounts for bank entries. */
export const getBankReconciliationOptions = () =>
  api.get(`${BASE}/options`).then((r) => r.data?.data ?? {})

export const getNextBankReconciliationNo = (statementDate) =>
  api.get(`${BASE}/next-no`, { params: { statement_date: statementDate } })
    .then((r) => r.data?.data?.recon_no ?? '')

/**
 * Opening balance, book balance and every uncleared bank line from the start date up to the
 * statement date. dateFrom counts only on an account's first reconciliation (its start date).
 */
export const getReconciliationWorkspace = (bankAccountId, statementDate, reconciliationId, dateFrom) =>
  api.get(`${BASE}/workspace`, {
    params: {
      bank_account_id: bankAccountId,
      statement_date: statementDate,
      reconciliation_id: reconciliationId || undefined,
      date_from: dateFrom || undefined,
    },
  }).then((r) => r.data?.data)

/**
 * Add Transaction. by = date | cheque | amount | description (uncleared entries of any date)
 * or returned (cheques an earlier reconciliation realized that bounced later).
 * POST, so the ids already on screen never make the URL too long.
 */
export const searchReconciliationEntries = (bankAccountId, by, term, except = []) =>
  api.post(`${BASE}/search`, { bank_account_id: bankAccountId, by, term, except })
    .then((r) => r.data?.data ?? [])

/** Post bank charges or interest from the statement ({ type, request_id, ... }). Returns its bank line. */
export const postBankEntry = (payload) =>
  api.post(`${BASE}/bank-entries`, payload).then((r) => r.data?.data)
