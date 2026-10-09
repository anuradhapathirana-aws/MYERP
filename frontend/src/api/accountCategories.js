import api from './axios'

const BASE = '/api/v1/account-categories'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getAccountCategories = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getAccountCategory = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createAccountCategory = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateAccountCategory = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteAccountCategory = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — active categories only, ordered by code. */
export const getAllAccountCategories = (filters = {}) =>
  api.get(`${BASE}/all`, { params: filters }).then((r) => r.data?.data ?? [])

/**
 * The five account types, served from the backend enum.
 *
 * Deliberately fetched rather than hardcoded: each type maps to the first
 * digit of every account code beneath it, so a stale copy in JavaScript would
 * show the wrong code preview.
 */
export const getAccountTypes = () =>
  api.get(`${BASE}/account-types`).then((r) => r.data?.data ?? [])

/** Non-binding preview of the code a new category under this type would get. */
export const getNextAccountCategoryCode = (accountType) =>
  api.get(`${BASE}/next-code`, { params: { account_type: accountType } })
    .then((r) => r.data?.data?.code ?? '')
