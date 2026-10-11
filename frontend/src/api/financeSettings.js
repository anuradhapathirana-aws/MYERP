import api from './axios'

const BASE = '/api/v1/finance-settings'

/**
 * Every posting role with the account it currently resolves to, which roles
 * are unmapped, and the posting mode.
 *
 * @returns {Promise<{ mappings: object[], missing_required: object[], posting_mode: string, posting_modes: object[] }>}
 */
export const getFinanceSettings = (companyId) =>
  api.get(BASE, { params: companyId ? { company_id: companyId } : {} })
    .then((r) => r.data?.data ?? {})

/**
 * Apply mapping changes.
 *
 * The server refuses an account whose TYPE does not suit the role -- mapping
 * Accounts Payable to Sales Revenue comes back as a 422 on
 * `mappings.accounts_payable`, not as a wrong Balance Sheet next month.
 *
 * @param {Record<string, number|null>} mappings rule_key => ledger_account_id
 */
export const updateAccountMappings = (mappings, companyId) =>
  api.put(`${BASE}/mappings`, { mappings, company_id: companyId ?? null })
    .then((r) => r.data?.data ?? {})

/**
 * Switch one side of the chart between a shared control account and one
 * account per party.
 *
 * Not retrospective: posted lines stay where they are. Because every line
 * carries the party either way, ageing reports read the same across the
 * change -- only a party's own ledger shows a partial history until the
 * balance is transferred with a journal voucher.
 *
 * @param {'supplier'|'customer'} partyType
 * @param {'shared'|'per_party'} mode
 */
export const updatePartyLedgerMode = (partyType, mode) =>
  api.put(`${BASE}/party-ledger-mode`, { party_type: partyType, mode })
    .then((r) => r.data?.data ?? {})

/** 'review' holds automatic postings as drafts; 'immediate' posts inline. */
export const updatePostingMode = (postingMode) =>
  api.put(`${BASE}/posting-mode`, { posting_mode: postingMode })
    .then((r) => r.data?.data ?? {})
