import api from './axios'

const BASE = '/api/v1/ledger-accounts'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getLedgerAccounts = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getLedgerAccount = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createLedgerAccount = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateLedgerAccount = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteLedgerAccount = (id) =>
  api.delete(`${BASE}/${id}`)

/**
 * Flat list for <select> dropdowns.
 *
 * Payment screens should pass { fund_sources_only: 1 } — optionally with
 * cash_book_type and company_id — so they offer only the accounts money
 * actually moves through, for the paying company, rather than the whole chart.
 */
export const getAllLedgerAccounts = (filters = {}) =>
  api.get(`${BASE}/all`, { params: filters }).then((r) => r.data?.data ?? [])

/** The cash / bank classification options, served from the backend enum. */
export const getCashBookTypes = () =>
  api.get(`${BASE}/cash-book-types`).then((r) => r.data?.data ?? [])

/** Non-binding preview of the code a new ledger under this control account would get. */
export const getNextLedgerAccountCode = (controlAccountId) =>
  api.get(`${BASE}/next-code`, { params: { control_account_id: controlAccountId } })
    .then((r) => r.data?.data?.code ?? '')
