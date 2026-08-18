import api from './axios'

export const getAllStockReferenceTypes = () =>
  api.get('/api/v1/stock-reference-types/all').then((r) => r.data?.data ?? [])
