import api from './axios'

export const getPurchaseOrders = (page = 1, filters = {}) =>
  api.get('/api/v1/purchase-orders', { params: { page, ...filters } }).then((r) => r.data)

export const getPurchaseOrder = (id) =>
  api.get(`/api/v1/purchase-orders/${id}`).then((r) => r.data)

export const createPurchaseOrder = (data) =>
  api.post('/api/v1/purchase-orders', data).then((r) => r.data)

export const updatePurchaseOrder = (id, data) =>
  api.put(`/api/v1/purchase-orders/${id}`, data).then((r) => r.data)

export const deletePurchaseOrder = (id) =>
  api.delete(`/api/v1/purchase-orders/${id}`)

export const updatePurchaseOrderStatus = (id, status) =>
  api.patch(`/api/v1/purchase-orders/${id}/status`, { status }).then((r) => r.data)

export const loadPOFromPR = (prId) =>
  api.get(`/api/v1/purchase-orders/from-pr/${prId}`).then((r) => r.data)

export const getNextPoNo = () =>
  api.get('/api/v1/purchase-orders/next-po-no').then((r) => r.data.data)

/** Download PO as PDF — returns a Blob */
export const downloadPoPdf = (id) =>
  api.get(`/api/v1/purchase-orders/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)
