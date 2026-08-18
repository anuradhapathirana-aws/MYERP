import api from './axios'

const BASE = '/api/v1/unit-conversions'

/**
 * Returns unit types in the category with current conversion rates.
 * @returns {Promise<{ data: { base_unit_id: number|null, units: object[] } }>}
 */
export const getUnitConversionsByCategory = (categoryId) =>
  api.get(`${BASE}/by-category/${categoryId}`).then((r) => r.data)

/**
 * Save (replace) conversion rates for all units in a category.
 * @param {{ category_id: number, base_unit_type_id: number, rates: { unit_type_id: number, rate: number }[] }} payload
 */
export const saveUnitConversionRates = (payload) =>
  api.post(`${BASE}/save-rates`, payload).then((r) => r.data)
