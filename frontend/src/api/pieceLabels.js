import api from './axios'

/** Fetch sealed piece labels (with QR data URIs) matching the filters */
export const getPieceLabels = (filters = {}) =>
  api.get('/api/v1/piece-labels', { params: filters }).then((r) => r.data.data)

/** Distinct shipping codes of confirmed GRNs for the filter dropdown */
export const getShippingCodes = () =>
  api.get('/api/v1/piece-labels/shipping-codes').then((r) => r.data.data)

/** GRN numbers of confirmed GRNs for the filter dropdown */
export const getGrnNos = () =>
  api.get('/api/v1/piece-labels/grn-nos').then((r) => r.data.data)

/** Download the filtered piece labels as a printable PDF — returns a Blob */
export const downloadPieceLabelsPdf = (filters = {}) =>
  api.get('/api/v1/piece-labels/pdf', { params: filters, responseType: 'blob' }).then((r) => r.data)
