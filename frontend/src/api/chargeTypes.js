import api from './axios'

const BASE = '/api/v1/charge-types'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getChargeTypes = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

export const createChargeType = (payload) =>
  api.post(BASE, payload).then((r) => r.data?.data)

export const updateChargeType = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data?.data)

export const deleteChargeType = (id) =>
  api.delete(`${BASE}/${id}`)

/** Treatments, billable expense accounts and Inventory's Costing rows (FOB / CIF). */
export const getChargeTypeOptions = () =>
  api.get(`${BASE}/options`).then((r) => r.data?.data ?? {})
