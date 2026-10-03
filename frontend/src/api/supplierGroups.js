import api from './axios'

const BASE = '/api/v1/supplier-groups'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getSupplierGroups = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getSupplierGroup = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createSupplierGroup = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateSupplierGroup = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteSupplierGroup = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — active groups only, ordered by sort_order */
export const getAllSupplierGroups = () =>
  api.get(`${BASE}/all`).then((r) => r.data?.data ?? [])
