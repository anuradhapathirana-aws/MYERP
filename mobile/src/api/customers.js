import api from './client'

/** All customers for the picker — { id, name, customer_code, customer_type, shipping_address }. */
export const getAllCustomers = () =>
  api.get('/api/v1/customer-masters/all').then((r) => r.data.data)
