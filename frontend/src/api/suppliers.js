import api from './axios'

export const getAllSuppliers = () =>
  api.get('/api/v1/supplier-masters/all').then((r) => r.data.data)

export const getSuppliers = (page = 1, filters = {}) =>
  api.get('/api/v1/supplier-masters', { params: { page, ...filters } }).then((r) => r.data)

export const getSupplier = (id) =>
  api.get(`/api/v1/supplier-masters/${id}`).then((r) => r.data)

export const createSupplier = (data) =>
  api.post('/api/v1/supplier-masters', data).then((r) => r.data)

export const updateSupplier = (id, data) =>
  api.put(`/api/v1/supplier-masters/${id}`, data).then((r) => r.data)

export const deleteSupplier = (id) =>
  api.delete(`/api/v1/supplier-masters/${id}`)

export const getNextSupplierCode = () =>
  api.get('/api/v1/supplier-masters/next-code').then((r) => r.data.data.supplier_code)
