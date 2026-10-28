import api from './axios'

const BASE = '/api/v1/bills'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getBills = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

export const getBill = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data?.data)

export const createBill = (payload) =>
  api.post(BASE, payload).then((r) => r.data?.data)

export const updateBill = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data?.data)

/** Drafts only; the server refuses anything confirmed. */
export const deleteBill = (id) =>
  api.delete(`${BASE}/${id}`)

/** Posts Dr expense / Cr Accounts Payable. */
export const confirmBill = (id) =>
  api.post(`${BASE}/${id}/confirm`).then((r) => r.data?.data)

/** Reverses the journal on reversal_date (must be in an open period). */
export const voidBill = (id, { reason, reversal_date }) =>
  api.post(`${BASE}/${id}/void`, { reason, reversal_date }).then((r) => r.data?.data)

export const getBillHistory = (id) =>
  api.get(`${BASE}/${id}/history`).then((r) => r.data?.data ?? [])

/** Expense accounts, companies, locations, statuses, lock date, maker-checker. */
export const getBillOptions = () =>
  api.get(`${BASE}/options`).then((r) => r.data?.data ?? {})

/** Non-binding preview of the next bill number. */
export const getNextBillNo = (transactionDate) =>
  api.get(`${BASE}/next-no`, { params: { transaction_date: transactionDate } })
    .then((r) => r.data?.data?.bill_no ?? '')

/** Due date from the supplier's credit period + the group's default expense account. */
export const getSupplierBillDefaults = (supplierId, transactionDate) =>
  api.get(`${BASE}/supplier-defaults/${supplierId}`, { params: { transaction_date: transactionDate } })
    .then((r) => r.data?.data ?? {})
