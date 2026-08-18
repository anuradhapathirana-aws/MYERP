import api from './axios'

export const getInvoices = (page = 1, filters = {}) =>
  api.get('/api/v1/invoices', { params: { page, ...filters } }).then((r) => r.data)

export const getInvoice = (id) =>
  api.get(`/api/v1/invoices/${id}`).then((r) => r.data)

export const createInvoice = (data) =>
  api.post('/api/v1/invoices', data).then((r) => r.data)

export const updateInvoice = (id, data) =>
  api.put(`/api/v1/invoices/${id}`, data).then((r) => r.data)

export const deleteInvoice = (id) =>
  api.delete(`/api/v1/invoices/${id}`)

export const updateInvoiceStatus = (id, status) =>
  api.patch(`/api/v1/invoices/${id}/status`, { status }).then((r) => r.data)

/** Preview needs a customer to build QQQQ, so nothing is returned until a DO is picked. */
export const getNextInvoiceNo = (doId, invoiceDate) =>
  api.get('/api/v1/invoices/next-invoice-no', { params: { do_id: doId, invoice_date: invoiceDate } })
    .then((r) => r.data.data)

/** Billing preview for a direct-SO (advance) invoice */
export const getBillingSourceForSo = (soId) =>
  api.get(`/api/v1/invoices/billing-source/so/${soId}`).then((r) => r.data.data)

/** Billing preview for a per-DO invoice */
export const getBillingSourceForDo = (doId) =>
  api.get(`/api/v1/invoices/billing-source/do/${doId}`).then((r) => r.data.data)

/** Download invoice as PDF — returns a Blob */
export const downloadInvoicePdf = (id) =>
  api.get(`/api/v1/invoices/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)

/** Confirmed-receipt payoff trail for one invoice, oldest first */
export const getInvoicePaymentHistory = (id) =>
  api.get(`/api/v1/invoices/${id}/payment-history`).then((r) => r.data.data)
