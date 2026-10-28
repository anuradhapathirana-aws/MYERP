import api from './axios'

const BASE = '/api/v1/bill-payments'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getBillPayments = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

export const getBillPayment = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data?.data)

export const createBillPayment = (payload) =>
  api.post(BASE, payload).then((r) => r.data?.data)

export const updateBillPayment = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data?.data)

/** Drafts only; the server refuses anything confirmed. */
export const deleteBillPayment = (id) =>
  api.delete(`${BASE}/${id}`)

/** Pays out: Dr Accounts Payable per bill / Cr the cash book or bank. Bill balances fall. */
export const confirmBillPayment = (id) =>
  api.post(`${BASE}/${id}/confirm`).then((r) => r.data?.data)

/** Reverses the journal on reversal_date (must be in an open period); bill balances come back. */
export const voidBillPayment = (id, { reason, reversal_date }) =>
  api.post(`${BASE}/${id}/void`, { reason, reversal_date }).then((r) => r.data?.data)

export const getBillPaymentHistory = (id) =>
  api.get(`${BASE}/${id}/history`).then((r) => r.data?.data ?? [])

/** Pay-from accounts (with allowed methods), companies, methods, statuses, lock date, maker-checker. */
export const getBillPaymentOptions = () =>
  api.get(`${BASE}/options`).then((r) => r.data?.data ?? {})

/** Non-binding preview of the next payment number. */
export const getNextBillPaymentNo = (paymentDate) =>
  api.get(`${BASE}/next-no`, { params: { payment_date: paymentDate } })
    .then((r) => r.data?.data?.payment_no ?? '')

/** The supplier's unpaid confirmed bills in one company, oldest due first. */
export const getOpenBills = (supplierId, companyId) =>
  api.get(`${BASE}/open-bills`, { params: { supplier_id: supplierId, company_id: companyId } })
    .then((r) => r.data?.data ?? [])
