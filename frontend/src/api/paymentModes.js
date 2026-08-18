import api from './axios'

const BASE = '/api/v1/payment-modes'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getPaymentModes = (page = 1) =>
  api.get(BASE, { params: { page } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getPaymentMode = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createPaymentMode = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updatePaymentMode = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deletePaymentMode = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — active modes only, ordered by sort_order */
export const getAllPaymentModes = () =>
  api.get(`${BASE}/all`).then((r) => r.data?.data ?? [])
