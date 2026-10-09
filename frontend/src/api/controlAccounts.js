import api from './axios'

const BASE = '/api/v1/control-accounts'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getControlAccounts = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getControlAccount = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createControlAccount = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateControlAccount = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteControlAccount = (id) =>
  api.delete(`${BASE}/${id}`)

/**
 * Flat list for <select> dropdowns.
 * Pass { account_category_id } or { account_type } to narrow it.
 */
export const getAllControlAccounts = (filters = {}) =>
  api.get(`${BASE}/all`, { params: filters }).then((r) => r.data?.data ?? [])

/** Non-binding preview of the code a new control account under this category would get. */
export const getNextControlAccountCode = (accountCategoryId) =>
  api.get(`${BASE}/next-code`, { params: { account_category_id: accountCategoryId } })
    .then((r) => r.data?.data?.code ?? '')
