import api from './axios'

const BASE = '/api/v1/store-types'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getStoreTypes = (page = 1) =>
  api.get(BASE, { params: { page } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getStoreType = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createStoreType = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateStoreType = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteStoreType = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — returns [{ id, store_type_name }] */
export const getAllStoreTypes = () =>
  api.get(`${BASE}/all`).then((r) => r.data?.data ?? [])
