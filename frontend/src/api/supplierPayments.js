import api from './axios'

export const getSupplierPayments = (page = 1, filters = {}) =>
  api.get('/api/v1/supplier-payments', { params: { page, ...filters } }).then((r) => r.data)

export const getSupplierPayment = (id) =>
  api.get(`/api/v1/supplier-payments/${id}`).then((r) => r.data)

export const createSupplierPayment = (data) =>
  api.post('/api/v1/supplier-payments', data).then((r) => r.data)

export const updateSupplierPayment = (id, data) =>
  api.put(`/api/v1/supplier-payments/${id}`, data).then((r) => r.data)

export const deleteSupplierPayment = (id) =>
  api.delete(`/api/v1/supplier-payments/${id}`)

export const confirmSupplierPayment = (id) =>
  api.post(`/api/v1/supplier-payments/${id}/confirm`).then((r) => r.data)

export const getNextPaymentNo = () =>
  api.get('/api/v1/supplier-payments/next-payment-no').then((r) => r.data.data.payment_no)

export const getOutstandingGrns = (supplierId) =>
  api.get(`/api/v1/supplier-payments/outstanding-grns/${supplierId}`).then((r) => r.data.data)

export const getOpenCreditNotes = (supplierId, type = null) =>
  api.get('/api/v1/supplier-payments/open-credit-notes', {
    params: { supplier_id: supplierId, ...(type ? { type } : {}) },
  }).then((r) => r.data.data)
