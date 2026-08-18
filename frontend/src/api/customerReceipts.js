import api from './axios'

export const getCustomerReceipts = (page = 1, filters = {}) =>
  api.get('/api/v1/customer-receipts', { params: { page, ...filters } }).then((r) => r.data)

export const getCustomerReceipt = (id) =>
  api.get(`/api/v1/customer-receipts/${id}`).then((r) => r.data)

export const createCustomerReceipt = (data) =>
  api.post('/api/v1/customer-receipts', data).then((r) => r.data)

export const updateCustomerReceipt = (id, data) =>
  api.put(`/api/v1/customer-receipts/${id}`, data).then((r) => r.data)

export const deleteCustomerReceipt = (id) =>
  api.delete(`/api/v1/customer-receipts/${id}`)

export const confirmCustomerReceipt = (id) =>
  api.post(`/api/v1/customer-receipts/${id}/confirm`).then((r) => r.data)

export const getNextReceiptNo = () =>
  api.get('/api/v1/customer-receipts/next-receipt-no').then((r) => r.data.data.receipt_no)

export const getOutstandingInvoices = (customerId) =>
  api.get(`/api/v1/customer-receipts/outstanding-invoices/${customerId}`).then((r) => r.data.data)

export const downloadCustomerReceiptPdf = (id) =>
  api.get(`/api/v1/customer-receipts/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)

export const getOpenCustomerCreditNotes = (customerId, type = null) =>
  api.get('/api/v1/customer-receipts/open-credit-notes', {
    params: { customer_id: customerId, ...(type ? { type } : {}) },
  }).then((r) => r.data.data)
