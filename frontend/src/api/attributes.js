import api from './axios'

const BASE = '/api/v1/attributes'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getAttributes = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getAttribute = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createAttribute = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateAttribute = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteAttribute = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — returns [{ id, attribute_type_id, attribute_name }] */
export const getAllAttributes = () =>
  api.get(`${BASE}/all`).then((r) => r.data.data)
