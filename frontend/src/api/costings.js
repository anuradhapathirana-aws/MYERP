import api from './axios'

export const getCostings = (page = 1, filters = {}) =>
  api.get('/api/v1/costings', { params: { page, ...filters } }).then((r) => r.data)

export const getCosting = (id) =>
  api.get(`/api/v1/costings/${id}`).then((r) => r.data)

export const createCosting = (data) =>
  api.post('/api/v1/costings', data).then((r) => r.data)

export const updateCosting = (id, data) =>
  api.patch(`/api/v1/costings/${id}`, data).then((r) => r.data)

export const deleteCosting = (id) =>
  api.delete(`/api/v1/costings/${id}`)

export const confirmCosting = (id) =>
  api.post(`/api/v1/costings/${id}/confirm`).then((r) => r.data)

export const getNextDocumentNo = () =>
  api.get('/api/v1/costings/next-document-no').then((r) => r.data.data.document_no)

export const getNextReferenceNo = () =>
  api.get('/api/v1/costings/next-reference-no').then((r) => r.data.data.reference_no)

export const getSupplierGrns = (supplierId) =>
  api.get(`/api/v1/costings/supplier-grns/${supplierId}`).then((r) => r.data.data ?? [])

export const getCostingExpenseTypes = (costingType) =>
  api.get('/api/v1/costing-expense-types', {
    params: { costing_type: costingType, active_only: true },
  }).then((r) => r.data.data ?? [])
