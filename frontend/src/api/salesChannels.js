import api from './axios'

export const getAllSalesChannels = () =>
  api.get('/api/v1/sales-channels/all').then((r) => r.data.data)

export const getSalesChannels = (page = 1) =>
  api.get('/api/v1/sales-channels', { params: { page } }).then((r) => r.data)

export const getSalesChannel = (id) =>
  api.get(`/api/v1/sales-channels/${id}`).then((r) => r.data)

export const createSalesChannel = (data) =>
  api.post('/api/v1/sales-channels', data).then((r) => r.data)

export const updateSalesChannel = (id, data) =>
  api.put(`/api/v1/sales-channels/${id}`, data).then((r) => r.data)

export const deleteSalesChannel = (id) =>
  api.delete(`/api/v1/sales-channels/${id}`)
