import api from './axios'

export const getAllCategories = () =>
  api.get('/api/v1/categories/all').then((r) => r.data.data)

export const getCategories = (page = 1, filters = {}) =>
  api.get('/api/v1/categories', { params: { page, ...filters } }).then((r) => r.data)

export const getCategory = (id) =>
  api.get(`/api/v1/categories/${id}`).then((r) => r.data)

export const createCategory = (data) =>
  api.post('/api/v1/categories', data).then((r) => r.data)

export const updateCategory = (id, data) =>
  api.put(`/api/v1/categories/${id}`, data).then((r) => r.data)

export const deleteCategory = (id) =>
  api.delete(`/api/v1/categories/${id}`)
