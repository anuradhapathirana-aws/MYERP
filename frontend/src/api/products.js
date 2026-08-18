import api from './axios'

const BASE = '/api/v1/products'

/** Lightweight list for dropdowns — id, product_code, name only. */
export const getAllProducts = () =>
  api.get(`${BASE}/all`).then((r) => r.data.data)

/** @returns {Promise<{ data: object[], meta: object }>} */
export const getProducts = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getProduct = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createProduct = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateProduct = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteProduct = (id) =>
  api.delete(`${BASE}/${id}`)

export const getNextProductCode = () =>
  api.get(`${BASE}/next-code`).then((r) => r.data.data.product_code)
