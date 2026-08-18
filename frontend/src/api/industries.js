import api from './axios'

export const getAllIndustries = () =>
  api.get('/api/v1/industries/all').then((r) => r.data.data)

export const getIndustries = (page = 1) =>
  api.get('/api/v1/industries', { params: { page } }).then((r) => r.data)

export const getIndustry = (id) =>
  api.get(`/api/v1/industries/${id}`).then((r) => r.data)

export const createIndustry = (data) =>
  api.post('/api/v1/industries', data).then((r) => r.data)

export const updateIndustry = (id, data) =>
  api.put(`/api/v1/industries/${id}`, data).then((r) => r.data)

export const deleteIndustry = (id) =>
  api.delete(`/api/v1/industries/${id}`)
