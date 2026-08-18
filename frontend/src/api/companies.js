import api from './axios'

export const getAllCompanies = () =>
  api.get('/api/v1/companies/all').then((r) => r.data.data)

export const getCompanies = (page = 1) =>
  api.get('/api/v1/companies', { params: { page } }).then((r) => r.data)

export const getCompany = (id) =>
  api.get(`/api/v1/companies/${id}`).then((r) => r.data)

export const createCompany = (data) =>
  api.post('/api/v1/companies', data).then((r) => r.data)

export const updateCompany = (id, data) =>
  api.put(`/api/v1/companies/${id}`, data).then((r) => r.data)

export const deleteCompany = (id) =>
  api.delete(`/api/v1/companies/${id}`)
