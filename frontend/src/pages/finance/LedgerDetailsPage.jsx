import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BookOpenText, ChevronsDownUp, ChevronsUpDown, RotateCcw } from 'lucide-react'
import { downloadLedgerDetailsPdf, getLedgerDetails, getLedgerDetailsOptions } from '../../api/ledgerDetails'
import { getAllLocations } from '../../api/locations'
import Breadcrumb from '../../components/Breadcrumb'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import MultiSearchSelect from '../../components/ui/MultiSearchSelect'
import Pagination from '../../components/ui/Pagination'
import { PdfBtn, PrintBtn } from '../../components/ui/ActionButtons'
import { useDebouncedValue } from '../../hooks/useDebouncedValue'
import { usePermissions } from '../../hooks/usePermissions'
import { showError } from '../../utils/alerts'
import { FILTER_INPUT_CLS, LABEL_CLS } from '../../utils/fieldStyles'
import { downloadPdfBlob, printPdfBlob } from '../../utils/pdf'
import LedgerAccountCard from './ledger/LedgerAccountCard'
import LedgerViewSwitch from './ledger/LedgerViewSwitch'
import SummaryTile from './ledger/SummaryTile'
import { money, periodLabel } from './ledger/format'
import { useLedgerView } from './ledger/useLedgerView'

const CRUMBS = [{ label: 'Finance' }, { label: 'General Ledger' }, { label: 'Ledger Details' }]

// A selection this small opens every card straight away.
const AUTO_EXPAND_MAX = 5

const SHORTCUTS = [
  { keys: ['Alt', 'T'], desc: 'Switch List / T-Account' },
  { keys: ['Alt', 'E'], desc: 'Expand / collapse all accounts' },
]

const isoDate = (d) => d.toLocaleDateString('en-CA')
const monthStart = () => { const d = new Date(); d.setDate(1); return isoDate(d) }

const defaultFilters = () => ({ date_from: monthStart(), date_to: isoDate(new Date()), location_id: '' })

const EMPTY_SELECTION = {
  account_ids:         [],
  code_from:           '',
  code_to:             '',
  account_type:        '',
  account_category_id: '',
  control_account_id:  '',
  include_parties:     true,
  include_no_activity: false,
}

/** The request parameters: blanks dropped, booleans as 1 / 0. */
const toParams = (filters, selection) => ({
  date_from:           filters.date_from || undefined,
  date_to:             filters.date_to || undefined,
  location_id:         filters.location_id || undefined,
  account_ids:         selection.account_ids.length ? selection.account_ids : undefined,
  code_from:           selection.code_from.trim() || undefined,
  code_to:             selection.code_to.trim() || undefined,
  account_type:        selection.account_type || undefined,
  account_category_id: selection.account_category_id || undefined,
  control_account_id:  selection.control_account_id || undefined,
  include_parties:     selection.include_parties ? 1 : 0,
  include_no_activity: selection.include_no_activity ? 1 : undefined,
})

/**
 * Finance > General Ledger > Ledger Details.
 *
 * The General Ledger over any selection of accounts: hand-picked, a code
 * range, a control account, a category or a type (they narrow together; none
 * means the whole chart). Each account is a card whose header carries its
 * B/F, period debits and credits and C/F; opening it loads the lines, 50 a
 * page with balances carried across pages, as a list or a T-account.
 *
 * The cards reuse the Trial Balance popup's ledger/ components, so both
 * screens always show the same figures the same way.
 */
export default function LedgerDetailsPage() {
  const { can } = usePermissions()
  const allowed = can('view_finance_reports')

  const [filters, setFilters]     = useState(defaultFilters)
  const [selection, setSelection] = useState(EMPTY_SELECTION)
  const { view, changeView, toggleView, adoptDefault } = useLedgerView()
  const [exportBusy, setExportBusy] = useState(null) // 'pdf' | 'print'

  // Typed code ranges wait for a pause; everything else applies at once.
  const codeFrom = useDebouncedValue(selection.code_from)
  const codeTo   = useDebouncedValue(selection.code_to)
  const params   = toParams(filters, { ...selection, code_from: codeFrom, code_to: codeTo })

  // The account page belongs to one set of params; any change returns to page 1.
  const paramsKey = JSON.stringify(params)
  const [paging, setPaging] = useState({ key: paramsKey, page: 1 })
  const page = paging.key === paramsKey ? paging.page : 1

  // Expanded cards: 'auto' opens all of a small selection; the user's own
  // clicks flip individual cards from whatever the mode says.
  const [expand, setExpand] = useState({ mode: 'auto', flipped: new Set() })

  const set    = (patch) => setFilters((f) => ({ ...f, ...patch }))
  const select = (patch) => setSelection((s) => ({ ...s, ...patch }))

  const { data: locations = [] } = useQuery({
    queryKey: ['locations', 'all'],
    queryFn:  getAllLocations,
    staleTime: 5 * 60 * 1000,
    enabled:  allowed,
  })

  const { data: options } = useQuery({
    queryKey: ['ledger-details', 'options'],
    queryFn:  getLedgerDetailsOptions,
    staleTime: 5 * 60 * 1000,
    enabled:  allowed,
  })

  const { data, isLoading, isFetching, isError } = useQuery({
    queryKey: ['ledger-details', params, page],
    queryFn:  () => getLedgerDetails({ ...params, page }),
    placeholderData: (prev) => prev,
    enabled:  allowed,
  })

  adoptDefault(data?.default_view)

  const accounts = data?.accounts ?? []
  const totals   = data?.totals
  const meta     = data?.meta

  const autoOpen   = expand.mode === 'all' || (expand.mode === 'auto' && (meta?.total ?? 0) <= AUTO_EXPAND_MAX)
  const isExpanded = (id) => autoOpen !== expand.flipped.has(id)
  const allOpen    = accounts.length > 0 && accounts.every((a) => isExpanded(a.ledger_account_id))

  const toggleCard = (id) => setExpand((e) => {
    const flipped = new Set(e.flipped)
    if (flipped.has(id)) flipped.delete(id)
    else flipped.add(id)
    return { ...e, flipped }
  })
  const setAll = (open) => setExpand({ mode: open ? 'all' : 'none', flipped: new Set() })

  useEffect(() => {
    const onKey = (e) => {
      if (!e.altKey) return
      if (e.key === 't' || e.key === 'T') { e.preventDefault(); toggleView() }
      if (e.key === 'e' || e.key === 'E') { e.preventDefault(); setAll(!allOpen) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [toggleView, allOpen])

  // ── Selection controls, narrowed by the choices above them ──
  const { accountOptions, categoryOptions, controlOptions } = useMemo(() => {
    const controls   = options?.control_accounts ?? []
    const categories = options?.categories ?? []
    const typeOfCat  = new Map(categories.map((c) => [c.id, c.account_type]))
    const ctrlById   = new Map(controls.map((c) => [c.id, c]))

    const catOk  = (c) => !selection.account_type || c.account_type === selection.account_type
    const ctrlOk = (c) => (!selection.account_category_id || String(c.account_category_id) === selection.account_category_id)
      && (!selection.account_type || typeOfCat.get(c.account_category_id) === selection.account_type)

    const okControls = controls.filter(ctrlOk)
    const okCtrlIds  = new Set(okControls.map((c) => c.id))

    return {
      categoryOptions: categories.filter(catOk).map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name}` })),
      controlOptions:  okControls.map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name}` })),
      accountOptions:  (options?.accounts ?? [])
        .filter((a) => okCtrlIds.has(a.control_account_id))
        .filter((a) => !selection.control_account_id || String(a.control_account_id) === selection.control_account_id)
        .filter((a) => selection.include_parties || !a.is_party)
        .map((a) => ({
          value: a.id,
          label: `${a.code} · ${a.name}${a.is_active ? '' : ' (inactive)'}`,
          group: ctrlById.get(a.control_account_id)?.name,
          muted: !a.is_active,
        })),
    }
  }, [options, selection.account_type, selection.account_category_id, selection.control_account_id, selection.include_parties])

  const exportPdf = async (action) => {
    setExportBusy(action)
    try {
      const blob = await downloadLedgerDetailsPdf({ ...params, view })
      if (action === 'print') printPdfBlob(blob)
      else downloadPdfBlob(blob, `Ledger_Details_${filters.date_from || 'all'}_${filters.date_to || 'today'}.pdf`)
    } catch {
      showError('Could not create the Ledger Details PDF.')
    } finally {
      setExportBusy(null)
    }
  }

  const reset = () => {
    setFilters(defaultFilters())
    setSelection(EMPTY_SELECTION)
    setExpand({ mode: 'auto', flipped: new Set() })
  }

  if (!allowed) {
    return (
      <div className="p-3">
        <Breadcrumb crumbs={CRUMBS} />
        <p className="mt-3 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500">
          You do not have permission to view financial reports.
        </p>
      </div>
    )
  }

  const hasAccounts = accounts.length > 0

  return (
    <div className="p-3">
      {/* ── Title + actions (top right, like every index screen) ─────── */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Breadcrumb crumbs={CRUMBS} />
        <div className="flex items-center gap-1.5">
          <LedgerViewSwitch view={view} onChange={changeView} />
          <PrintBtn
            onClick={() => exportPdf('print')}
            disabled={exportBusy !== null || !hasAccounts}
            title={`Print the selected ledgers (${view === 'list' ? 'list' : 'T-accounts'})`}
          />
          <PdfBtn
            onClick={() => exportPdf('pdf')}
            disabled={exportBusy !== null || !hasAccounts}
            title={`Download the selected ledgers as PDF (${view === 'list' ? 'list' : 'T-accounts'})`}
          />
        </div>
      </div>

      {/* ── Filters ─────────────────────────────────────────────────── */}
      <div className="mt-2 rounded-lg border border-slate-200 bg-white p-2">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:grid-cols-6">
          <div>
            <label className={LABEL_CLS}>From</label>
            <input type="date" value={filters.date_from} onChange={(e) => set({ date_from: e.target.value })} className={FILTER_INPUT_CLS} />
          </div>
          <div>
            <label className={LABEL_CLS}>To</label>
            <input type="date" value={filters.date_to} onChange={(e) => set({ date_to: e.target.value })} className={FILTER_INPUT_CLS} />
          </div>
          <div>
            <label className={LABEL_CLS}>Location</label>
            <FilterSearchSelect
              value={filters.location_id}
              onChange={(v) => set({ location_id: v })}
              options={locations.map((l) => ({ value: String(l.id), label: l.location_name ?? l.name }))}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Account Type</label>
            <FilterSearchSelect
              value={selection.account_type}
              onChange={(v) => select({ account_type: v, account_category_id: '', control_account_id: '' })}
              options={(options?.account_types ?? []).map((t) => ({ value: t.value, label: t.label }))}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Category</label>
            <FilterSearchSelect
              value={selection.account_category_id}
              onChange={(v) => select({ account_category_id: v, control_account_id: '' })}
              options={categoryOptions}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Control Account</label>
            <FilterSearchSelect
              value={selection.control_account_id}
              onChange={(v) => select({ control_account_id: v })}
              options={controlOptions}
              wide
            />
          </div>

          <div className="sm:col-span-2">
            <label className={LABEL_CLS}>Accounts</label>
            <MultiSearchSelect
              value={selection.account_ids}
              onChange={(ids) => select({ account_ids: ids })}
              options={accountOptions}
              placeholder="All accounts"
              wide
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Code From</label>
            <input
              type="text"
              value={selection.code_from}
              onChange={(e) => select({ code_from: e.target.value })}
              placeholder="e.g. 1"
              maxLength={30}
              className={`${FILTER_INPUT_CLS} font-mono`}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Code To</label>
            <input
              type="text"
              value={selection.code_to}
              onChange={(e) => select({ code_to: e.target.value })}
              placeholder="e.g. 2 (incl. 2xxx)"
              maxLength={30}
              className={`${FILTER_INPUT_CLS} font-mono`}
            />
          </div>
          <div className="flex flex-col justify-end gap-1 text-xs text-slate-600">
            <label className="inline-flex cursor-pointer items-center gap-1.5">
              <input
                type="checkbox"
                checked={selection.include_parties}
                onChange={(e) => select({ include_parties: e.target.checked })}
                className="h-3.5 w-3.5 accent-indigo-600"
              />
              Supplier / customer accounts
            </label>
            <label className="inline-flex cursor-pointer items-center gap-1.5">
              <input
                type="checkbox"
                checked={selection.include_no_activity}
                onChange={(e) => select({ include_no_activity: e.target.checked })}
                className="h-3.5 w-3.5 accent-indigo-600"
              />
              Accounts with no activity
            </label>
          </div>
          <div className="flex items-end">
            <button
              type="button"
              onClick={reset}
              title="Back to this month, all accounts"
              className="inline-flex items-center gap-1 rounded-md border-2 border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
            >
              <RotateCcw size={12} /> Reset
            </button>
          </div>
        </div>
      </div>

      {/* ── Grand totals over the whole selection ───────────────────── */}
      <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
        <SummaryTile label="Accounts" value={`${totals?.account_count ?? 0} · ${totals?.line_count ?? 0} lines`} />
        <SummaryTile label="Opening Dr" value={money(totals?.opening_debit)} cls="text-indigo-700" />
        <SummaryTile label="Opening Cr" value={money(totals?.opening_credit)} cls="text-amber-700" />
        <SummaryTile label="Period Debits" value={money(totals?.period_debit)} />
        <SummaryTile label="Period Credits" value={money(totals?.period_credit)} />
        <SummaryTile label="Closing Dr" value={money(totals?.closing_debit)} cls="text-indigo-700" strong />
        <SummaryTile label="Closing Cr" value={money(totals?.closing_credit)} cls="text-amber-700" strong />
      </div>

      {/* ── Account cards ───────────────────────────────────────────── */}
      <div className="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
        <BookOpenText size={13} className="text-indigo-600" />
        <span>Period: <b className="text-slate-700">{periodLabel(filters)}</b></span>
        {isFetching && !isLoading && <span className="text-slate-400">Updating…</span>}
        {hasAccounts && (
          <button
            type="button"
            onClick={() => setAll(!allOpen)}
            title="Expand / collapse all accounts (Alt+E)"
            className="ml-auto inline-flex items-center gap-1 rounded-md border-2 border-slate-200 bg-white px-2 py-0.5 font-semibold text-slate-600 transition-colors hover:bg-slate-50"
          >
            {allOpen ? <><ChevronsDownUp size={12} /> Collapse all</> : <><ChevronsUpDown size={12} /> Expand all</>}
          </button>
        )}
      </div>

      <div className="mt-1 space-y-1.5">
        {isLoading && <p className="rounded-lg border border-slate-200 bg-white py-6 text-center text-xs text-slate-400">Loading…</p>}
        {isError && <p className="rounded-lg border border-red-200 bg-red-50 py-6 text-center text-xs text-red-600">Could not load the ledger. Check the filters and try again.</p>}
        {!isLoading && !isError && !hasAccounts && (
          <p className="rounded-lg border border-slate-200 bg-white py-6 text-center text-xs text-slate-400">
            No account has a balance or movement for this selection and period.
          </p>
        )}

        {accounts.map((a) => (
          <LedgerAccountCard
            key={a.ledger_account_id}
            account={a}
            filters={filters}
            view={view}
            expanded={isExpanded(a.ledger_account_id)}
            onToggle={() => toggleCard(a.ledger_account_id)}
          />
        ))}
      </div>

      <Pagination meta={meta} page={page} onPageChange={(p) => setPaging({ key: paramsKey, page: p })} />

      <KeyboardShortcuts shortcuts={SHORTCUTS} />
    </div>
  )
}
