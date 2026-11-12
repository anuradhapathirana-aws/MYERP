import api from './axios'

// Home dashboard sections. Each is owned by its module and guarded by its own
// permission, so the page fetches only the sections the user may see.

export const getSalesDashboard = (period) =>
  api.get('/api/v1/dashboard/inventory/sales', { params: { period } }).then((r) => r.data.data)

export const getOrdersDashboard = (period) =>
  api.get('/api/v1/dashboard/inventory/orders', { params: { period } }).then((r) => r.data.data)

export const getReceivablesDashboard = () =>
  api.get('/api/v1/dashboard/inventory/receivables').then((r) => r.data.data)

export const getPayablesDashboard = () =>
  api.get('/api/v1/dashboard/inventory/payables').then((r) => r.data.data)

export const getStockDashboard = (period) =>
  api.get('/api/v1/dashboard/inventory/stock', { params: { period } }).then((r) => r.data.data)

export const getCashDashboard = () =>
  api.get('/api/v1/dashboard/finance/cash').then((r) => r.data.data)
