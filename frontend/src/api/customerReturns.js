import api from './axios'

export const getCustomerReturns = (page = 1, filters = {}) =>
  api.get('/api/v1/customer-returns', { params: { page, ...filters } }).then((r) => r.data)

export const getCustomerReturn = (id) =>
  api.get(`/api/v1/customer-returns/${id}`).then((r) => r.data)

export const createCustomerReturn = (data) =>
  api.post('/api/v1/customer-returns', data).then((r) => r.data)

export const updateCustomerReturn = (id, data) =>
  api.put(`/api/v1/customer-returns/${id}`, data).then((r) => r.data)

export const deleteCustomerReturn = (id) =>
  api.delete(`/api/v1/customer-returns/${id}`)

export const confirmCustomerReturn = (id) =>
  api.post(`/api/v1/customer-returns/${id}/confirm`).then((r) => r.data)

export const getNextReturnNo = () =>
  api.get('/api/v1/customer-returns/next-return-no').then((r) => r.data.data.return_no)

export const getReturnableInvoices = (customerId) =>
  api.get(`/api/v1/customer-returns/returnable-invoices/${customerId}`).then((r) => r.data.data)

export const getReturnableItems = (invoiceId) =>
  api.get(`/api/v1/customer-returns/returnable-items/${invoiceId}`).then((r) => r.data.data)

export const downloadCustomerReturnPdf = (id) =>
  api.get(`/api/v1/customer-returns/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)
