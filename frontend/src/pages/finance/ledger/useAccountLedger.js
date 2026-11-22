import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getAccountTransactions } from '../../../api/journalEntries'

const EMPTY_SUMMARY = { opening: 0, period_debit: 0, period_credit: 0, period_net: 0, closing: 0, is_filtered: false }

/**
 * One account's ledger lines, paged 50 at a time with b/f and c/f carried
 * across pages by the server. Used by the Trial Balance popup and every
 * Ledger Details account card, so both behave identically.
 *
 * Search and the document filter apply to the list only: a T-account only
 * balances over every entry. The page returns to 1 whenever the period,
 * location or view changes, without an effect (the page is keyed on them).
 *
 * @param {{ accountId: number, filters: object, view: 'list'|'t_account', enabled?: boolean }} args
 */
export function useAccountLedger({ accountId, filters, view, enabled = true }) {
  const base = {
    date_from:   filters.date_from || undefined,
    date_to:     filters.date_to || undefined,
    company_id:  filters.company_id || undefined,
    location_id: filters.location_id || undefined,
  }

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch]           = useState('')
  const [sourceType, setSourceTypeRaw] = useState('')

  // The page belongs to one combination of period, view and list filters.
  const pageKey = JSON.stringify([base, view, search, sourceType])
  const [paging, setPaging] = useState({ key: pageKey, page: 1 })
  const page    = paging.key === pageKey ? paging.page : 1
  const setPage = (p) => setPaging({ key: pageKey, page: p })

  // Debounced: one request per pause in typing, not per keystroke.
  useEffect(() => {
    const t = setTimeout(() => setSearch(searchInput.trim()), 300)
    return () => clearTimeout(t)
  }, [searchInput])

  const params = view === 'list'
    ? { ...base, search: search || undefined, source_type: sourceType || undefined, page }
    : { ...base, page }

  const query = useQuery({
    queryKey: ['account-transactions', accountId, params],
    queryFn:  () => getAccountTransactions(accountId, params),
    placeholderData: (prev) => prev,
    enabled,
  })

  const data = query.data
  const meta = data?.meta

  return {
    ...query,
    base,
    info:        data?.account,
    summary:     data?.summary ?? EMPTY_SUMMARY,
    rows:        data?.rows ?? [],
    sourceTypes: data?.source_types ?? [],
    meta,
    filtered:    Boolean(data?.summary?.is_filtered),
    page,
    setPage,
    isFirst:     page === 1,
    isLast:      !meta || page >= meta.last_page,
    searchInput,
    setSearchInput,
    sourceType,
    setSourceType: setSourceTypeRaw,
    hasListFilters: Boolean(searchInput || sourceType),
    clearListFilters: () => { setSearchInput(''); setSearch(''); setSourceTypeRaw('') },
  }
}
