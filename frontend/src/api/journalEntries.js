import api from './axios'

const BASE = '/api/v1/journal-entries'

/** @returns {Promise<{ data: object[], meta: { current_page, last_page, per_page, total } }>} */
export const getJournalEntries = (page = 1, filters = {}) =>
  api.get(BASE, { params: { page, ...filters } }).then((r) => r.data)

/** One voucher with its lines, its reversal chain and the From -> To view. */
export const getJournalEntry = (id) =>
  api.get(`${BASE}/${id}`).then((r) => r.data)

export const createJournalEntry = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

/** Promote a review-mode draft to posted. */
export const postJournalEntry = (id) =>
  api.post(`${BASE}/${id}/post`).then((r) => r.data)

/** Post the mirror image. The original stays in the ledger -- both count. */
export const reverseJournalEntry = (id, narration) =>
  api.post(`${BASE}/${id}/reverse`, { narration }).then((r) => r.data)

/** Drafts only; the server refuses anything posted. */
export const deleteJournalEntry = (id) =>
  api.delete(`${BASE}/${id}`)

/** Journal types, statuses, party types and the current posting mode. */
export const getJournalOptions = () =>
  api.get(`${BASE}/options`).then((r) => r.data?.data ?? {})

/** Non-binding preview of the number this voucher would receive. */
export const getNextJournalNo = (journalDate) =>
  api.get(`${BASE}/next-no`, { params: { journal_date: journalDate } })
    .then((r) => r.data?.data?.journal_no ?? '')

/** @returns {Promise<{ rows: object[], totals: object }>} */
export const getTrialBalance = (filters = {}) =>
  api.get('/api/v1/finance-reports/trial-balance', { params: filters })
    .then((r) => r.data?.data ?? { rows: [], totals: {} })
