import api from './axios'

const BASE = '/api/v1/stores'

/** @returns {Promise<{ data: object[], meta: object }>} */
export const getStores = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getStore = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createStore = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateStore = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteStore = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — returns [{ id, store_code, store_name }] */
export const getAllStores = () =>
  api.get(`${BASE}/all`).then((r) => r.data?.data ?? [])
