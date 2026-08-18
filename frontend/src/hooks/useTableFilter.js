import { useState } from 'react'

/**
 * Manages draft/applied filter state for any data table.
 *
 * Usage:
 *   const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
 *     useTableFilter({ search: '', type: '' })
 *
 *   Pass `applied` to your query key and API call.
 *   Pass `apply` / `clear` with an optional page-reset callback.
 *
 *   Pass `{ openByDefault: true }` to start with the filter panel expanded
 *   (used by report pages) — every other table keeps the collapsed default.
 */
export function useTableFilter(initialFilters = {}, { openByDefault = false } = {}) {
  const [open, setOpen] = useState(openByDefault)
  const [draft, setDraft] = useState(initialFilters)
  const [applied, setApplied] = useState(initialFilters)

  const toggle = () => setOpen((o) => !o)

  const apply = (onPageReset) => {
    setApplied({ ...draft })
    onPageReset?.()
  }

  const clear = (onPageReset) => {
    setDraft({ ...initialFilters })
    setApplied({ ...initialFilters })
    onPageReset?.()
  }

  // Sets both draft and applied at once, bypassing the normal draft → Apply Filter
  // flow. For defaults that only become known after mount (e.g. "first location"
  // once the location list has loaded) rather than at initialFilters time.
  const setDefaults = (patch) => {
    setDraft((d) => ({ ...d, ...patch }))
    setApplied((a) => ({ ...a, ...patch }))
  }

  const activeCount = Object.values(applied).filter(
    (v) => v !== '' && v !== null && v !== undefined,
  ).length

  return { open, toggle, draft, setDraft, applied, apply, clear, activeCount, setDefaults }
}
