import api from './axios'

const BASE = '/api/v1/unit-categories'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getUnitCategories = (page = 1) =>
  api.get(BASE, { params: { page } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getUnitCategory = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createUnitCategory = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** Bulk-create: payload = { names: string[], description?: string }
 *  @returns {Promise<{ data: object[] }>} */
export const createUnitCategories = (payload) =>
  api.post(`${BASE}/bulk`, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateUnitCategory = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteUnitCategory = (id) =>
  api.delete(`${BASE}/${id}`)

/** Mark a category as the system default (clears any previous default). */
export const setDefaultUnitCategory = (id) =>
  api.post(`${BASE}/${id}/set-default`).then((r) => r.data)

/** Remove the default flag from a category. */
export const clearDefaultUnitCategory = (id) =>
  api.post(`${BASE}/${id}/clear-default`).then((r) => r.data)

/** Flat list for <select> dropdowns — returns [{ id, name }] */
export const getAllUnitCategories = () =>
  api.get(`${BASE}/all`).then((r) => r.data)
