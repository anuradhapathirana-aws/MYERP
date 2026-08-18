import api from './axios'

export const getAllVehicles = () =>
  api.get('/api/v1/vehicle-masters/all').then((r) => r.data.data)

export const getVehicles = (page = 1, search = '') =>
  api.get('/api/v1/vehicle-masters', { params: { page, ...(search ? { search } : {}) } }).then((r) => r.data)

export const getVehicle = (id) =>
  api.get(`/api/v1/vehicle-masters/${id}`).then((r) => r.data)

export const createVehicle = (data) =>
  api.post('/api/v1/vehicle-masters', data).then((r) => r.data)

export const updateVehicle = (id, data) =>
  api.put(`/api/v1/vehicle-masters/${id}`, data).then((r) => r.data)

export const deleteVehicle = (id) =>
  api.delete(`/api/v1/vehicle-masters/${id}`)
