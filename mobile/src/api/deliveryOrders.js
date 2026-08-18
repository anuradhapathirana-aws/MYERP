import api from './client'

export const getDeliveryOrders = (page = 1, filters = {}) =>
  api.get('/api/v1/delivery-orders', { params: { page, ...filters } }).then((r) => r.data)

export const getDeliveryOrder = (id) =>
  api.get(`/api/v1/delivery-orders/${id}`).then((r) => r.data)

/** Server forces status draft on create — the app never confirms/dispatches. */
export const createDeliveryOrder = (data) =>
  api.post('/api/v1/delivery-orders', data).then((r) => r.data)

/** "Recall SO" — undelivered remainder + still-available allocated rolls of a confirmed SO. */
export const getDoSourceSalesOrder = (soId) =>
  api.get(`/api/v1/delivery-orders/from-so/${soId}`).then((r) => r.data.data)

export const getAllStores = () =>
  api.get('/api/v1/stores/all').then((r) => r.data.data)

export const getAllLocations = () =>
  api.get('/api/v1/locations/all').then((r) => r.data.data)
