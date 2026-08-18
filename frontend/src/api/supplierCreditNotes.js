import api from './axios'

export const getSupplierCreditNotes = (page = 1, filters = {}) =>
  api.get('/api/v1/supplier-credit-notes', { params: { page, ...filters } }).then((r) => r.data)

export const getSupplierCreditNote = (id) =>
  api.get(`/api/v1/supplier-credit-notes/${id}`).then((r) => r.data)
