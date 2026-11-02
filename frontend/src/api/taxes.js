import api from './axios'

const BASE = '/api/v1/taxes'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total, current_ids } }>} */
export const getTaxes = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getTax = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createTax = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateTax = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteTax = (id) =>
  api.delete(`${BASE}/${id}`)

/**
 * Today's rates for form defaults — { vat, sscl, sscl_rate, sscl_active }.
 * sscl = what invoices split out (0 while SSCL is off); sscl_rate = configured rate (costing default).
 */
export const getCurrentTaxes = () =>
  api.get(`${BASE}/current`).then((r) => r.data?.data ?? {})

/** TanStack Query options shared by every form that needs today's rates. */
export const currentTaxesQuery = {
  queryKey: ['taxes-current'],
  queryFn:  getCurrentTaxes,
  staleTime: 5 * 60 * 1000,
}
