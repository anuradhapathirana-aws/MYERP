import api from './axios'

const BASE = '/api/v1/bank-branches'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getBankBranches = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getBankBranch = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createBankBranch = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateBankBranch = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteBankBranch = (id) =>
  api.delete(`${BASE}/${id}`)

/**
 * Flat list for <select> dropdowns — active branches only.
 * Pass a bankId to narrow to one bank (used by the Ledger Account form).
 */
export const getAllBankBranches = (bankId) =>
  api.get(`${BASE}/all`, { params: bankId ? { bank_id: bankId } : {} })
    .then((r) => r.data?.data ?? [])
