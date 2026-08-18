import api from './axios'

export const getSalesOrders = (page = 1, filters = {}) =>
  api.get('/api/v1/sales-orders', { params: { page, ...filters } }).then((r) => r.data)

export const getSalesOrder = (id) =>
  api.get(`/api/v1/sales-orders/${id}`).then((r) => r.data)

export const createSalesOrder = (data) =>
  api.post('/api/v1/sales-orders', data).then((r) => r.data)

export const updateSalesOrder = (id, data) =>
  api.put(`/api/v1/sales-orders/${id}`, data).then((r) => r.data)

export const deleteSalesOrder = (id) =>
  api.delete(`/api/v1/sales-orders/${id}`)

export const updateSalesOrderStatus = (id, status) =>
  api.patch(`/api/v1/sales-orders/${id}/status`, { status }).then((r) => r.data)

export const getNextSoNo = () =>
  api.get('/api/v1/sales-orders/next-so-no').then((r) => r.data.data)

export const getOrderSources = () =>
  api.get('/api/v1/sales-orders/order-sources').then((r) => r.data.data)

/** Resolve a scanned piece QR — returns { piece, product, grn_unit_price, selling_price, available, unavailable_reason } */
export const scanSalesPiece = (pieceCode) =>
  api.get(`/api/v1/sales-orders/scan-piece/${encodeURIComponent(pieceCode)}`).then((r) => r.data.data)

/** Product pricing for a manually picked product — { unit_price: price-list selling price, cost_price: latest GRN cost } */
export const getProductSalePrice = (productId) =>
  api.get(`/api/v1/sales-orders/product-price/${productId}`).then((r) => r.data.data)

/** In-stock rolls of a product for the roll picker — { pieces, count, total_weight } */
export const getAvailablePieces = (productId) =>
  api.get(`/api/v1/sales-orders/available-pieces/${productId}`).then((r) => r.data.data)
