import api from './axios'

export const getProformaInvoices = (page = 1, filters = {}) =>
  api.get('/api/v1/proforma-invoices', { params: { page, ...filters } }).then((r) => r.data)

export const getProformaInvoice = (id) =>
  api.get(`/api/v1/proforma-invoices/${id}`).then((r) => r.data)

export const createProformaInvoice = (data) =>
  api.post('/api/v1/proforma-invoices', data).then((r) => r.data)

export const updateProformaInvoice = (id, data) =>
  api.put(`/api/v1/proforma-invoices/${id}`, data).then((r) => r.data)

export const deleteProformaInvoice = (id) =>
  api.delete(`/api/v1/proforma-invoices/${id}`)

/** issued | cancelled — Partially Invoiced / Converted are set by the system */
export const updateProformaInvoiceStatus = (id, status) =>
  api.patch(`/api/v1/proforma-invoices/${id}/status`, { status }).then((r) => r.data)

/** Preview needs the SO's customer to build QQQQ, so nothing is returned until an SO is picked. */
export const getNextProformaNo = (soId, proformaDate) =>
  api.get('/api/v1/proforma-invoices/next-no', { params: { so_id: soId, proforma_date: proformaDate } })
    .then((r) => r.data.data)

/** Quote preview of a sales order — lines with both Tax and Non-Tax prices */
export const getProformaSourceForSo = (soId) =>
  api.get(`/api/v1/proforma-invoices/source/so/${soId}`).then((r) => r.data.data)

/** Download proforma as PDF — returns a Blob */
export const downloadProformaInvoicePdf = (id) =>
  api.get(`/api/v1/proforma-invoices/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)
