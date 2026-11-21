import { Fragment, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Check, Scale } from 'lucide-react'
import { getTrialBalance } from '../../api/journalEntries'
import { getAllLocations } from '../../api/locations'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Breadcrumb from '../../components/Breadcrumb'
import { usePermissions } from '../../hooks/usePermissions'
import { FILTER_INPUT_CLS, LABEL_CLS } from '../../utils/fieldStyles'
import AccountTransactionsModal from './AccountTransactionsModal'

const CRUMBS = [{ label: 'Finance' }, { label: 'Trial Balance' }]

// Balance-sheet types first in A = L + E order, then the P&L types. Same
// ordering the account codes encode, so this agrees with the chart.
const TYPE_ORDER = ['asset', 'liability', 'equity', 'income', 'expense']

// The six numeric columns of the detailed view, in display order.
const DETAIL_KEYS = [
  'opening_debit', 'opening_credit',
  'debit_total', 'credit_total',
  'closing_debit', 'closing_credit',
]

const VIEW_KEY = 'tb.view'

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** Blank instead of 0.00, so a column of figures reads at a glance. */
const cell = (n) => (Number(n) > 0 ? money(n) : '')

const readView = () => {
  try { return localStorage.getItem(VIEW_KEY) === 'detailed' ? 'detailed' : 'standard' } catch { return 'standard' }
}

/**
 * The Trial Balance -- and the health check on the whole ledger.
 *
 * Nothing here is stored. Every figure is summed from fin_journal_lines on
 * demand, which is why a new document type never needs a schema change.
 *
 * Two views:
 *   Standard  each account's net balance for the period, Dr or Cr.
 *   Detailed  the six-column form: Opening (b/f), Period Dr/Cr, Closing (c/f).
 *             Accounts with only a b/f balance are listed too, because their
 *             balance still carries forward.
 *
 * If the totals ever disagree, something wrote to the journal tables without
 * going through JournalPostingService, and no other financial report can be
 * trusted until that is found. That is why the verdict is shown as a banner
 * rather than buried in a footer.
 */
export default function TrialBalancePage() {
  const { can } = usePermissions()

  const [filters, setFilters] = useState({
    date_from:   '',
    date_to:     '',
    location_id: '',
  })

  const [view, setView] = useState(readView)
  const detailed = view === 'detailed'

  const changeView = (v) => {
    setView(v)
    try { localStorage.setItem(VIEW_KEY, v) } catch { /* storage unavailable: keep in memory */ }
  }

  // The row whose transactions are open in the drill-down popup.
  const [selected, setSelected] = useState(null)

  const { data: locations = [] } = useQuery({
    queryKey: ['locations', 'all'],
    queryFn:  getAllLocations,
    staleTime: 5 * 60 * 1000,
  })

  const { data, isLoading } = useQuery({
    queryKey: ['trial-balance', filters, view],
    queryFn:  () => getTrialBalance({ ...filters, detailed: detailed ? 1 : undefined }),
    enabled:  can('view_finance_reports'),
    placeholderData: (prev) => prev,
  })

  // Memoised because the grouping below depends on it: a fresh [] every render
  // would defeat that memo entirely.
  const rows         = useMemo(() => data?.rows ?? [], [data])
  const totals       = data?.totals ?? { debit: 0, credit: 0, difference: 0, balanced: true }
  const detailTotals = data?.detail_totals ?? {}
  const balanced     = detailed ? (detailTotals.balanced ?? true) : totals.balanced

  /* ── Grouped by account type, with a subtotal per group ── */
  const groups = useMemo(() => {
    const byType = new Map()

    for (const row of rows) {
      const key = row.account_type ?? 'other'
      if (!byType.has(key)) {
        byType.set(key, {
          type: key,
          label: row.account_type_label ?? 'Other',
          rows: [],
          debit: 0,
          credit: 0,
          ...Object.fromEntries(DETAIL_KEYS.map((k) => [k, 0])),
        })
      }
      const g = byType.get(key)
      g.rows.push(row)
      g.debit  += Number(row.debit_balance)  || 0
      g.credit += Number(row.credit_balance) || 0
      for (const k of DETAIL_KEYS) g[k] += Number(row[k]) || 0
    }

    return [...byType.values()].sort(
      (a, b) => TYPE_ORDER.indexOf(a.type) - TYPE_ORDER.indexOf(b.type),
    )
  }, [rows])

  if (!can('view_finance_reports')) {
    return (
      <div className="p-3">
        <Breadcrumb crumbs={CRUMBS} />
        <p className="mt-3 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500">
          You do not have permission to view financial reports.
        </p>
      </div>
    )
  }

  const cols = detailed ? 9 : 5

  const detailFooter = [
    detailTotals.opening_debit, detailTotals.opening_credit,
    detailTotals.period_debit, detailTotals.period_credit,
    detailTotals.closing_debit, detailTotals.closing_credit,
  ]

  return (
    <div className="p-3">
      <Breadcrumb crumbs={CRUMBS} />

      {/* ── Filters ─────────────────────────────────────────────────── */}
      <div className="mt-2 rounded-lg border border-slate-200 bg-white p-2">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label className={LABEL_CLS}>From</label>
            <input
              type="date"
              value={filters.date_from}
              onChange={(e) => setFilters({ ...filters, date_from: e.target.value })}
              className={FILTER_INPUT_CLS}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>To</label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => setFilters({ ...filters, date_to: e.target.value })}
              className={FILTER_INPUT_CLS}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Location</label>
            <FilterSearchSelect
              value={filters.location_id}
              onChange={(v) => setFilters({ ...filters, location_id: v })}
              options={locations.map((l) => ({ value: String(l.id), label: l.location_name ?? l.name }))}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>View</label>
            <div className="inline-flex rounded-md border-2 border-slate-200 bg-slate-50 p-0.5 text-xs font-semibold">
              {[
                ['standard', 'Standard'],
                ['detailed', 'Detailed (B/F & C/F)'],
              ].map(([v, label]) => (
                <button
                  key={v}
                  type="button"
                  onClick={() => changeView(v)}
                  className={`rounded px-2 py-0.5 transition-colors ${
                    view === v ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-white'
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
          <div className="flex items-end">
            <button
              type="button"
              onClick={() => setFilters({ date_from: '', date_to: '', location_id: '' })}
              className="rounded-md border-2 border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
            >
              Clear
            </button>
          </div>
        </div>
      </div>

      {/* ── The verdict ─────────────────────────────────────────────── */}
      <div
        className={`mt-2 flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold ${
          balanced
            ? 'border-green-200 bg-green-50 text-green-700'
            : 'border-red-200 bg-red-50 text-red-700'
        }`}
      >
        {balanced ? <Check size={15} /> : <AlertTriangle size={15} />}
        {balanced ? (
          <span>
            Balanced — debits equal credits{detailed ? ' for opening, period and closing.' : '.'}
          </span>
        ) : (
          <span>
            Out of balance{detailed ? '' : ` by ${money(totals.difference)}`}. Something wrote to the ledger without
            going through the posting service.
          </span>
        )}
        <span className="ml-auto flex items-center gap-1 text-xs font-normal text-slate-500">
          <Scale size={12} /> {rows.length} account{rows.length === 1 ? '' : 's'}
          {detailed ? '' : ' with movement'}
        </span>
      </div>

      {detailed && !filters.date_from && (
        <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] text-amber-700">
          Set a <b>From</b> date to see balances brought forward. With no From date every entry is inside the
          period, so Opening (B/F) is zero.
        </p>
      )}

      {/* ── The report ──────────────────────────────────────────────── */}
      <div className="mt-2 overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead className="sticky top-0 bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
            {detailed ? (
              <>
                <tr>
                  <th rowSpan={2} className="px-2 py-1 text-left align-bottom">Code</th>
                  <th rowSpan={2} className="px-2 py-1 text-left align-bottom">Account</th>
                  <th rowSpan={2} className="px-2 py-1 text-left align-bottom">Control Account</th>
                  <th colSpan={2} className="border-l border-slate-200 px-2 py-1 text-center text-slate-600">Opening (B/F)</th>
                  <th colSpan={2} className="border-l border-slate-200 px-2 py-1 text-center text-slate-600">Period Movement</th>
                  <th colSpan={2} className="border-l border-slate-200 bg-indigo-50 px-2 py-1 text-center text-indigo-700">Closing (C/F)</th>
                </tr>
                <tr>
                  {['Debit', 'Credit', 'Debit', 'Credit', 'Debit', 'Credit'].map((h, i) => (
                    <th
                      key={i}
                      className={`px-2 py-1 text-right ${i % 2 === 0 ? 'border-l border-slate-200' : ''} ${i >= 4 ? 'bg-indigo-50 text-indigo-700' : ''}`}
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </>
            ) : (
              <tr>
                <th className="px-2 py-2 text-left">Code</th>
                <th className="px-2 py-2 text-left">Account</th>
                <th className="px-2 py-2 text-left">Control Account</th>
                <th className="px-2 py-2 text-right">Debit</th>
                <th className="px-2 py-2 text-right">Credit</th>
              </tr>
            )}
          </thead>

          <tbody>
            {isLoading && (
              <tr><td colSpan={cols} className="px-2 py-6 text-center text-sm text-slate-400">Loading…</td></tr>
            )}

            {!isLoading && rows.length === 0 && (
              <tr>
                <td colSpan={cols} className="px-2 py-6 text-center text-sm text-slate-400">
                  No journal entries in this range yet. Post a Journal Voucher to see it here.
                </td>
              </tr>
            )}

            {groups.map((g) => (
              // Fragment carries the key: a bare <> inside a map leaves React
              // without one, and it cannot go on the children instead.
              <Fragment key={g.type}>
                <tr className="bg-slate-100/70">
                  <td colSpan={cols} className="px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                    {g.label}
                  </td>
                </tr>

                {g.rows.map((row) => (
                  <tr key={row.ledger_account_id} className="border-t border-slate-100 hover:bg-slate-50">
                    <td className="px-2 py-1.5 font-mono text-xs text-slate-500">{row.code}</td>
                    <td className="px-2 py-1.5">
                      <button
                        type="button"
                        onClick={() => setSelected(row)}
                        title={`View transactions of ${row.ledger_account_name}`}
                        className="line-clamp-1 text-left font-medium text-indigo-700 hover:text-indigo-900 hover:underline"
                      >
                        {row.ledger_account_name}
                      </button>
                    </td>
                    <td className="px-2 py-1.5 text-xs text-slate-400">
                      <span className="line-clamp-1">{row.control_account_name}</span>
                    </td>

                    {detailed ? (
                      DETAIL_KEYS.map((k, i) => (
                        <td
                          key={k}
                          className={`whitespace-nowrap px-2 py-1.5 text-right text-xs tabular-nums ${
                            i % 2 === 0 ? 'border-l border-slate-100' : ''
                          } ${i >= 4 ? 'bg-indigo-50/40 font-semibold text-slate-900' : i < 2 ? 'text-slate-500' : 'text-slate-800'}`}
                        >
                          {cell(row[k])}
                        </td>
                      ))
                    ) : (
                      <>
                        <td className="px-2 py-1.5 text-right tabular-nums text-slate-800">{cell(row.debit_balance)}</td>
                        <td className="px-2 py-1.5 text-right tabular-nums text-slate-800">{cell(row.credit_balance)}</td>
                      </>
                    )}
                  </tr>
                ))}

                <tr className="border-t border-slate-200 bg-slate-50/60 text-xs font-semibold">
                  <td colSpan={3} className="px-2 py-1 text-right text-slate-500">{g.label} subtotal</td>
                  {detailed ? (
                    DETAIL_KEYS.map((k, i) => (
                      <td
                        key={k}
                        className={`whitespace-nowrap px-2 py-1 text-right tabular-nums text-slate-700 ${i % 2 === 0 ? 'border-l border-slate-200' : ''}`}
                      >
                        {money(g[k])}
                      </td>
                    ))
                  ) : (
                    <>
                      <td className="px-2 py-1 text-right tabular-nums text-slate-700">{money(g.debit)}</td>
                      <td className="px-2 py-1 text-right tabular-nums text-slate-700">{money(g.credit)}</td>
                    </>
                  )}
                </tr>
              </Fragment>
            ))}
          </tbody>

          {rows.length > 0 && (
            <tfoot className="sticky bottom-0 bg-slate-100 text-sm font-bold">
              <tr className="border-t-2 border-slate-300">
                <td colSpan={3} className="px-2 py-2 text-right text-xs uppercase tracking-wider text-slate-600">
                  Total
                </td>
                {detailed ? (
                  detailFooter.map((v, i) => (
                    <td
                      key={i}
                      className={`whitespace-nowrap px-2 py-2 text-right text-xs tabular-nums text-slate-900 ${i % 2 === 0 ? 'border-l border-slate-300' : ''}`}
                    >
                      {money(v)}
                    </td>
                  ))
                ) : (
                  <>
                    <td className="px-2 py-2 text-right tabular-nums text-slate-900">{money(totals.debit)}</td>
                    <td className="px-2 py-2 text-right tabular-nums text-slate-900">{money(totals.credit)}</td>
                  </>
                )}
              </tr>
            </tfoot>
          )}
        </table>
      </div>

      {selected && (
        <AccountTransactionsModal
          account={selected}
          filters={filters}
          onClose={() => setSelected(null)}
        />
      )}
    </div>
  )
}
