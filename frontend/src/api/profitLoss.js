import api from './axios'

const BASE = '/api/v1/finance-reports/profit-loss'

/**
 * The Profit & Loss statement.
 *
 * params: date_from, date_to (both required), location_id, compare (1/0),
 * compare_from, compare_to, and optional typed stock figures
 * (opening_stock, closing_stock, compare_opening_stock, compare_closing_stock)
 * that replace the automatic ones. Nothing is saved.
 *
 * A period that starts before the Finance go-live date comes back as a 422
 * on date_from.
 */
export const getProfitLoss = (params) =>
  api.get(BASE, { params }).then((r) => r.data?.data)

/** The same statement as a PDF Blob, from the same parameters. */
export const downloadProfitLossPdf = (params) =>
  api.get(`${BASE}/pdf`, { params, responseType: 'blob' }).then((r) => r.data)
