import api from './axios'

const base = '/api/v1/reports/inventory'

export const getStockLevelsReport = (page = 1, filters = {}) =>
  api.get(`${base}/stock-levels`, { params: { page, ...filters } }).then((r) => r.data)

export const getStockMovementsReport = (page = 1, filters = {}) =>
  api.get(`${base}/stock-movements`, { params: { page, ...filters } }).then((r) => r.data)

export const getLowStockReport = (page = 1, filters = {}) =>
  api.get(`${base}/low-stock`, { params: { page, ...filters } }).then((r) => r.data)

export const getStockValuationReport = (page = 1, filters = {}) =>
  api.get(`${base}/stock-valuation`, { params: { page, ...filters } }).then((r) => r.data)

export const getBatchExpiryReport = (page = 1, filters = {}) =>
  api.get(`${base}/batch-expiry`, { params: { page, ...filters } }).then((r) => r.data)

export const getPurchaseRequestsReport = (page = 1, filters = {}) =>
  api.get(`${base}/purchase-requests`, { params: { page, ...filters } }).then((r) => r.data)

export const getPurchaseOrdersReport = (page = 1, filters = {}) =>
  api.get(`${base}/purchase-orders`, { params: { page, ...filters } }).then((r) => r.data)

export const getOutstandingPOsReport = (page = 1, filters = {}) =>
  api.get(`${base}/outstanding-pos`, { params: { page, ...filters } }).then((r) => r.data)

export const getGrnReport = (page = 1, filters = {}) =>
  api.get(`${base}/grn`, { params: { page, ...filters } }).then((r) => r.data)

export const getSupplierSummaryReport = (page = 1, filters = {}) =>
  api.get(`${base}/supplier-summary`, { params: { page, ...filters } }).then((r) => r.data)

export const getLandedCostsReport = (page = 1, filters = {}) =>
  api.get(`${base}/landed-costs`, { params: { page, ...filters } }).then((r) => r.data)

export const getBinCardReport = (filters = {}) =>
  api.get(`${base}/bin-card`, { params: filters }).then((r) => r.data)

export const downloadBinCardPdf = (filters = {}) =>
  api.get(`${base}/bin-card/pdf`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const downloadBinCardCsv = (filters = {}) =>
  api.get(`${base}/bin-card/csv`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const getStockMovementSummaryReport = (filters = {}) =>
  api.get(`${base}/movement-summary`, { params: filters }).then((r) => r.data)

export const downloadStockMovementSummaryPdf = (filters = {}) =>
  api.get(`${base}/movement-summary/pdf`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const downloadStockMovementSummaryCsv = (filters = {}) =>
  api.get(`${base}/movement-summary/csv`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const getSalesByItemSummaryReport = (filters = {}) =>
  api.get(`${base}/sales-by-item-summary`, { params: filters }).then((r) => r.data)

export const downloadSalesByItemSummaryPdf = (filters = {}) =>
  api.get(`${base}/sales-by-item-summary/pdf`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const downloadSalesByItemSummaryCsv = (filters = {}) =>
  api.get(`${base}/sales-by-item-summary/csv`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const getSalesByItemDetailSummaryReport = (filters = {}) =>
  api.get(`${base}/sales-by-item-detail-summary`, { params: filters }).then((r) => r.data)

export const downloadSalesByItemDetailSummaryPdf = (filters = {}) =>
  api.get(`${base}/sales-by-item-detail-summary/pdf`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const downloadSalesByItemDetailSummaryCsv = (filters = {}) =>
  api.get(`${base}/sales-by-item-detail-summary/csv`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const getSalesByCustomerDetailsReport = (filters = {}) =>
  api.get(`${base}/sales-by-customer-details`, { params: filters }).then((r) => r.data)

export const downloadSalesByCustomerDetailsPdf = (filters = {}) =>
  api.get(`${base}/sales-by-customer-details/pdf`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const downloadSalesByCustomerDetailsCsv = (filters = {}) =>
  api.get(`${base}/sales-by-customer-details/csv`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const getSupplierWiseGrnDetailsReport = (filters = {}) =>
  api.get(`${base}/supplier-wise-grn-details`, { params: filters }).then((r) => r.data)

export const downloadSupplierWiseGrnDetailsPdf = (filters = {}) =>
  api.get(`${base}/supplier-wise-grn-details/pdf`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const downloadSupplierWiseGrnDetailsCsv = (filters = {}) =>
  api.get(`${base}/supplier-wise-grn-details/csv`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const getItemSearchReport = (page = 1, filters = {}) =>
  api.get(`${base}/item-search`, { params: { page, ...filters } }).then((r) => r.data)

export const getOutstandingSummaryReport = (filters = {}) =>
  api.get(`${base}/outstanding-summary`, { params: filters }).then((r) => r.data)

export const downloadOutstandingSummaryPdf = (filters = {}) =>
  api.get(`${base}/outstanding-summary/pdf`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const downloadOutstandingSummaryCsv = (filters = {}) =>
  api.get(`${base}/outstanding-summary/csv`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const getSalesSummaryReport = (filters = {}) =>
  api.get(`${base}/sales-summary`, { params: filters }).then((r) => r.data)

export const downloadSalesSummaryPdf = (filters = {}) =>
  api.get(`${base}/sales-summary/pdf`, { params: filters, responseType: 'blob' }).then((r) => r.data)

export const downloadSalesSummaryCsv = (filters = {}) =>
  api.get(`${base}/sales-summary/csv`, { params: filters, responseType: 'blob' }).then((r) => r.data)
