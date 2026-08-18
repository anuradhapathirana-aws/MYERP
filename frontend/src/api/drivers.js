import api from './axios'

export const getAllDrivers = () =>
  api.get('/api/v1/drivers/all').then((r) => r.data.data)

export const getDrivers = (page = 1, search = '') =>
  api.get('/api/v1/drivers', { params: { page, ...(search ? { search } : {}) } }).then((r) => r.data)

export const getDriver = (id) =>
  api.get(`/api/v1/drivers/${id}`).then((r) => r.data)

export const createDriver = (data) =>
  api.post('/api/v1/drivers', data).then((r) => r.data)

export const updateDriver = (id, data) =>
  api.put(`/api/v1/drivers/${id}`, data).then((r) => r.data)

export const deleteDriver = (id) =>
  api.delete(`/api/v1/drivers/${id}`)
