import api from './axios'

export const getSupplierReturns = (page = 1, filters = {}) =>
  api.get('/api/v1/supplier-returns', { params: { page, ...filters } }).then((r) => r.data)

export const getSupplierReturn = (id) =>
  api.get(`/api/v1/supplier-returns/${id}`).then((r) => r.data)

export const createSupplierReturn = (data) =>
  api.post('/api/v1/supplier-returns', data).then((r) => r.data)

export const updateSupplierReturn = (id, data) =>
  api.put(`/api/v1/supplier-returns/${id}`, data).then((r) => r.data)

export const deleteSupplierReturn = (id) =>
  api.delete(`/api/v1/supplier-returns/${id}`)

export const confirmSupplierReturn = (id) =>
  api.post(`/api/v1/supplier-returns/${id}/confirm`).then((r) => r.data)

export const getNextSupplierReturnNo = () =>
  api.get('/api/v1/supplier-returns/next-return-no').then((r) => r.data.data.return_no)

export const getReturnableGrns = (supplierId) =>
  api.get(`/api/v1/supplier-returns/returnable-grns/${supplierId}`).then((r) => r.data.data)

export const getReturnableGrnItems = (grnId) =>
  api.get(`/api/v1/supplier-returns/returnable-items/${grnId}`).then((r) => r.data.data)

export const downloadSupplierReturnPdf = (id) =>
  api.get(`/api/v1/supplier-returns/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)
