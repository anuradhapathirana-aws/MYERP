import api from './axios'

const BASE = '/api/v1/attribute-types'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getAttributeTypes = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getAttributeType = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createAttributeType = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateAttributeType = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteAttributeType = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — returns [{ id, attribute_type_name, category_id, category_name }] */
export const getAllAttributeTypes = () =>
  api.get(`${BASE}/all`).then((r) => r.data.data)
