import api from './axios'

const BASE = '/api/v1/employees'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getEmployees = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const getEmployee = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const createEmployee = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** @returns {Promise<{ data: object }>} */
export const updateEmployee = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteEmployee = (id) =>
  api.delete(`${BASE}/${id}`)

/** Flat list for <select> dropdowns — active employees only */
export const getAllEmployees = () =>
  api.get(`${BASE}/all`).then((r) => r.data?.data ?? [])
