import api from './axios'

export const getDeliveryOrders = (page = 1, filters = {}) =>
  api.get('/api/v1/delivery-orders', { params: { page, ...filters } }).then((r) => r.data)

export const getDeliveryOrder = (id) =>
  api.get(`/api/v1/delivery-orders/${id}`).then((r) => r.data)

export const createDeliveryOrder = (data) =>
  api.post('/api/v1/delivery-orders', data).then((r) => r.data)

export const updateDeliveryOrder = (id, data) =>
  api.put(`/api/v1/delivery-orders/${id}`, data).then((r) => r.data)

export const deleteDeliveryOrder = (id) =>
  api.delete(`/api/v1/delivery-orders/${id}`)

export const updateDeliveryOrderStatus = (id, status) =>
  api.patch(`/api/v1/delivery-orders/${id}/status`, { status }).then((r) => r.data)

export const getNextDoNo = () =>
  api.get('/api/v1/delivery-orders/next-do-no').then((r) => r.data.data)

/** "Recall SO" — the undelivered remainder of a confirmed sales order */
export const getDoSourceSalesOrder = (soId) =>
  api.get(`/api/v1/delivery-orders/from-so/${soId}`).then((r) => r.data.data)

/** Download DO as PDF — returns a Blob */
export const downloadDoPdf = (id) =>
  api.get(`/api/v1/delivery-orders/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)
