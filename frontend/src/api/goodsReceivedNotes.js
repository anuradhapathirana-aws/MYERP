import api from './axios'

export const getGoodsReceivedNotes = (page = 1, filters = {}) =>
  api.get('/api/v1/goods-received-notes', { params: { page, ...filters } }).then((r) => r.data)

export const getAllGrns = () =>
  api.get('/api/v1/goods-received-notes/all').then((r) => r.data.data)

export const getGoodsReceivedNote = (id) =>
  api.get(`/api/v1/goods-received-notes/${id}`).then((r) => r.data)

export const createGoodsReceivedNote = (data) =>
  api.post('/api/v1/goods-received-notes', data).then((r) => r.data)

export const updateGoodsReceivedNote = (id, data) =>
  api.put(`/api/v1/goods-received-notes/${id}`, data).then((r) => r.data)

export const deleteGoodsReceivedNote = (id) =>
  api.delete(`/api/v1/goods-received-notes/${id}`)

export const confirmGoodsReceivedNote = (id) =>
  api.post(`/api/v1/goods-received-notes/${id}/confirm`).then((r) => r.data)

export const getPoOutstandingItems = (poId) =>
  api.get(`/api/v1/goods-received-notes/po-items/${poId}`).then((r) => r.data)

/** Fetch outstanding items for multiple POs in one request */
export const getPoOutstandingItemsMultiple = (poIds = []) =>
  api.get('/api/v1/goods-received-notes/po-items-multi', {
    params: { po_ids: poIds },
    paramsSerializer: { indexes: null },
  }).then((r) => r.data.data ?? [])

export const getNextGrnNo = () =>
  api.get('/api/v1/goods-received-notes/next-grn-no').then((r) => r.data.data.grn_no)

export const getLastGrn = () =>
  api.get('/api/v1/goods-received-notes/last').then((r) => r.data.data)

/** Realtime shipping-code uniqueness check — resolves true when available */
export const checkShippingCode = (shippingCode, excludeId = null) =>
  api.get('/api/v1/goods-received-notes/check-shipping-code', {
    params: { shipping_code: shippingCode, ...(excludeId ? { exclude_id: excludeId } : {}) },
  }).then((r) => r.data.data.available)

/** Download GRN as PDF — returns a Blob */
export const downloadGrnPdf = (id) =>
  api.get(`/api/v1/goods-received-notes/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)

/** Download GRN piece QR labels as PDF — returns a Blob */
export const downloadGrnPieceLabelsPdf = (id) =>
  api.get(`/api/v1/goods-received-notes/${id}/piece-labels/pdf`, { responseType: 'blob' }).then((r) => r.data)

/** Fetch last recorded unit_price per product from GRN history { product_id: "price" } */
export const getLastGrnProductPrices = (productIds = []) =>
  api.get('/api/v1/goods-received-notes/last-product-prices', {
    params: { product_ids: productIds },
    paramsSerializer: { indexes: null },
  }).then((r) => r.data.data ?? {})
