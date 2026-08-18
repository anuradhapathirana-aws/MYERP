import api from './client'

export const getSalesOrders = (page = 1, filters = {}) =>
  api.get('/api/v1/sales-orders', { params: { page, ...filters } }).then((r) => r.data)

export const getSalesOrder = (id) =>
  api.get(`/api/v1/sales-orders/${id}`).then((r) => r.data)

/** Backend always assigns the SO number; the app only ever sends status "draft". */
export const createSalesOrder = (data) =>
  api.post('/api/v1/sales-orders', data).then((r) => r.data)

/** Draft SOs only — the backend re-syncs items and re-allocates/releases pieces. */
export const updateSalesOrder = (id, data) =>
  api.put(`/api/v1/sales-orders/${id}`, data).then((r) => r.data)

/** Resolve a scanned piece QR — { piece, product, grn_unit_price, selling_price, available, unavailable_reason }. */
export const scanSalesPiece = (pieceCode) =>
  api.get(`/api/v1/sales-orders/scan-piece/${encodeURIComponent(pieceCode)}`).then((r) => r.data.data)
