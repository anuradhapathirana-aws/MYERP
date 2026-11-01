import api from './axios'

const BASE = '/api/v1/bank-deposits'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getBankDeposits = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

export const getBankDeposit = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data?.data)

export const createBankDeposit = (payload) =>
  api.post(BASE, payload).then((r) => r.data?.data)

export const updateBankDeposit = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data?.data)

/** Drafts only; the server refuses anything confirmed. Its cheques go back in hand. */
export const deleteBankDeposit = (id) =>
  api.delete(`${BASE}/${id}`)

/** Banks it: Dr Bank / Cr Cash Book + Cheques in Hand. */
export const confirmBankDeposit = (id) =>
  api.post(`${BASE}/${id}/confirm`).then((r) => r.data?.data)

/** Reverses the journal on reversal_date (must be in an open period); the cheques go back in hand. */
export const voidBankDeposit = (id, { reason, reversal_date }) =>
  api.post(`${BASE}/${id}/void`, { reason, reversal_date }).then((r) => r.data?.data)

/** The paying-in slip as a PDF blob (drafts print too, marked DRAFT). */
export const downloadBankDepositPdf = (id) =>
  api.get(`${BASE}/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)

export const getBankDepositHistory = (id) =>
  api.get(`${BASE}/${id}/history`).then((r) => r.data?.data ?? [])

/** Companies, bank accounts, statuses, lock date, maker-checker. */
export const getBankDepositOptions = () =>
  api.get(`${BASE}/options`).then((r) => r.data?.data ?? {})

/** Non-binding preview of the next deposit number. */
export const getNextBankDepositNo = (depositDate) =>
  api.get(`${BASE}/next-no`, { params: { deposit_date: depositDate } })
    .then((r) => r.data?.data?.deposit_no ?? '')

/**
 * What a company has waiting to be banked on a date: each cash book's
 * available cash, and the cheques in hand (a deposit being edited keeps its own).
 */
export const getMoneyInHand = (companyId, depositDate, depositId) =>
  api.get(`${BASE}/in-hand`, { params: { company_id: companyId, deposit_date: depositDate, deposit_id: depositId || undefined } })
    .then((r) => r.data?.data ?? { cash_accounts: [], cheques: [] })
