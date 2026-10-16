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

/** Preview needs the customer's code for QQQQ, so nothing is returned until a customer is picked. */
export const getNextProformaNo = (customerId, proformaDate) =>
  api.get('/api/v1/proforma-invoices/next-no', { params: { customer_id: customerId, proforma_date: proformaDate } })
    .then((r) => r.data.data)

/** Live stock per form line — [{ available_qty, stock_status: in_stock|partial|non_stock }] in line order */
export const checkProformaStock = (lines) =>
  api.post('/api/v1/proforma-invoices/stock-check', { lines }).then((r) => r.data.data)

/** "Create SO from Proforma" pre-fill — customer + lines still to recall, priced as SO lines */
export const getProformaRecall = (id) =>
  api.get(`/api/v1/proforma-invoices/${id}/recall`).then((r) => r.data.data)

/** Download proforma as PDF — returns a Blob */
export const downloadProformaInvoicePdf = (id) =>
  api.get(`/api/v1/proforma-invoices/${id}/pdf`, { responseType: 'blob' }).then((r) => r.data)

/** Both price sets per form line — [{ unit_price (Non-Tax, VAT inside), tax_unit_price, tax_vat_pct, source }] */
export const getProformaPrices = (lines) =>
  api.post('/api/v1/proforma-invoices/price-preview', { lines }).then((r) => r.data.data)
