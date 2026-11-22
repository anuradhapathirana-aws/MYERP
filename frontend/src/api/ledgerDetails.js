import api from './axios'

const BASE = '/api/v1/finance-reports/ledger-details'

/**
 * What the selection controls offer: every account (incl. party and inactive
 * ones with history), control accounts, categories and account types.
 */
export const getLedgerDetailsOptions = () =>
  api.get(`${BASE}/options`).then((r) => r.data?.data ?? {})

/**
 * One summary row per selected account plus grand totals, 25 accounts a page.
 * Each account's lines come from getAccountTransactions (api/journalEntries).
 */
export const getLedgerDetails = (params = {}) =>
  api.get(BASE, { params }).then((r) => r.data?.data)

/** The whole selection as one PDF; params.view is 'list' or 't_account'. */
export const downloadLedgerDetailsPdf = (params = {}) =>
  api.get(`${BASE}/pdf`, { params, responseType: 'blob' }).then((r) => r.data)
