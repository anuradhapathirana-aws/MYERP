import api from './axios'

export const getLocations = (page = 1, filters = {}) => {
  const params = { page, ...filters }
  return api.get('/api/v1/locations', { params }).then((r) => r.data)
}

export const getAllLocations = () =>
  api.get('/api/v1/locations/all').then((r) => r.data)

export const getLocation = (id) =>
  api.get(`/api/v1/locations/${id}`).then((r) => r.data)

export const createLocation = (data) =>
  api.post('/api/v1/locations', data).then((r) => r.data)

export const updateLocation = (id, data) =>
  api.put(`/api/v1/locations/${id}`, data).then((r) => r.data)

export const deleteLocation = (id) =>
  api.delete(`/api/v1/locations/${id}`)
