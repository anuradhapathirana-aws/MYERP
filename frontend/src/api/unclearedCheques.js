import api from './axios'

const BASE = '/api/v1/finance-reports/uncleared-cheques'

/** Companies and bank accounts the user may report on. */
export const getUnclearedChequesOptions = () =>
  api.get(`${BASE}/options`).then((r) => r.data?.data ?? {})

/** filters: { as_at, company_id?, bank_account_id?, type?, search? } */
export const getUnclearedChequesReport = (filters) =>
  api.get(BASE, { params: clean(filters) }).then((r) => r.data?.data)

export const downloadUnclearedChequesPdf = (filters) =>
  api.get(`${BASE}/pdf`, { params: clean(filters), responseType: 'blob' }).then((r) => r.data)

export const downloadUnclearedChequesCsv = (filters) =>
  api.get(`${BASE}/csv`, { params: clean(filters), responseType: 'blob' }).then((r) => r.data)

/** Drop empty filters so the server sees only what was chosen. */
const clean = (filters = {}) =>
  Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null && v !== undefined))
