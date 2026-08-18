import api from './axios'

export const getPurchaseRequests = (page = 1, filters = {}) =>
  api.get('/api/v1/purchase-requests', { params: { page, ...filters } }).then((r) => r.data)

export const getPurchaseRequest = (id) =>
  api.get(`/api/v1/purchase-requests/${id}`).then((r) => r.data)

export const createPurchaseRequest = (data) =>
  api.post('/api/v1/purchase-requests', data).then((r) => r.data)

export const updatePurchaseRequest = (id, data) =>
  api.put(`/api/v1/purchase-requests/${id}`, data).then((r) => r.data)

export const deletePurchaseRequest = (id) =>
  api.delete(`/api/v1/purchase-requests/${id}`)

export const approvePurchaseRequest = (id, data = {}) =>
  api.post(`/api/v1/purchase-requests/${id}/approve`, data).then((r) => r.data)

export const rejectPurchaseRequest = (id, reason) =>
  api.post(`/api/v1/purchase-requests/${id}/reject`, { reason }).then((r) => r.data)

export const cancelPurchaseRequest = (id) =>
  api.post(`/api/v1/purchase-requests/${id}/cancel`).then((r) => r.data)

export const getNextPurchaseRequestReferenceNo = () =>
  api.get('/api/v1/purchase-requests/next-reference-no').then((r) => r.data.data)
