import api from './axios'

const BASE = '/api/v1/tax-codes'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total, vat_categories } }>} */
export const getTaxCodes = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getTaxCode = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createTaxCode = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateTaxCode = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteTaxCode = (id) =>
  api.delete(`${BASE}/${id}`)

/**
 * Active purchase codes with each tax's rate on a date (today when omitted):
 * [{ id, code, name, vat_category, components: [{ tax_code_ref, tax_name, rate, is_compound, is_recoverable }] }]
 */
export const getPurchaseTaxCodeOptions = (date) =>
  api.get(`${BASE}/options`, { params: date ? { date } : {} }).then((r) => r.data?.data ?? [])

/** TanStack Query options for GRN lines and the supplier form. */
export const purchaseTaxCodesQuery = (date) => ({
  queryKey: ['tax-code-options', date ?? 'today'],
  queryFn:  () => getPurchaseTaxCodeOptions(date),
  staleTime: 5 * 60 * 1000,
})
