import api from './axios'

export const getCustomerCreditNotes = (page = 1, filters = {}) =>
  api.get('/api/v1/customer-credit-notes', { params: { page, ...filters } }).then((r) => r.data)

export const getCustomerCreditNote = (id) =>
  api.get(`/api/v1/customer-credit-notes/${id}`).then((r) => r.data)
