import api from './axios'

const BASE = '/api/v1/banks'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getBanks = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getBank = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createBank = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateBank = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteBank = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — active banks only */
export const getAllBanks = () =>
  api.get(`${BASE}/all`).then((r) => r.data?.data ?? [])
