import api from './axios'

const BASE = '/api/v1/stock-reconciliations'

export const getStockReconciliations = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

export const getStockReconciliation = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

export const createStockReconciliation = (data) =>
  api.post(BASE, data).then((r) => r.data)

export const updateStockReconciliation = (id, data) =>
  api.put(`${BASE}/${id}`, data).then((r) => r.data)

export const deleteStockReconciliation = (id) =>
  api.delete(`${BASE}/${id}`)

export const submitStockReconciliation = (id) =>
  api.post(`${BASE}/${id}/submit`).then((r) => r.data)

export const approveStockReconciliation = (id) =>
  api.post(`${BASE}/${id}/approve`).then((r) => r.data)

export const rejectStockReconciliation = (id, reason) =>
  api.post(`${BASE}/${id}/reject`, { reason }).then((r) => r.data)

export const getNextStockReconciliationNo = () =>
  api.get(`${BASE}/next-reconciliation-no`).then((r) => r.data.data)

/** In-stock rolls for a product, for the "attach a roll to correct" picker. */
export const getAvailableRollsForReconciliation = (productId, search = '') =>
  api.get(`${BASE}/available-rolls/${productId}`, { params: search ? { search } : {} }).then((r) => r.data.data)
