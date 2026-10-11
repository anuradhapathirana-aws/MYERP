import { Fragment, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Check, Scale } from 'lucide-react'
import { getTrialBalance } from '../../api/journalEntries'
import { getAllLocations } from '../../api/locations'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Breadcrumb from '../../components/Breadcrumb'
import { usePermissions } from '../../hooks/usePermissions'
import { FILTER_INPUT_CLS, LABEL_CLS } from '../../utils/fieldStyles'

const CRUMBS = [{ label: 'Finance' }, { label: 'Trial Balance' }]

// Balance-sheet types first in A = L + E order, then the P&L types. Same
// ordering the account codes encode, so this agrees with the chart.
const TYPE_ORDER = ['asset', 'liability', 'equity', 'income', 'expense']

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/**
 * The Trial Balance -- and the health check on the whole ledger.
 *
 * Nothing here is stored. Every figure is summed from fin_journal_lines on
 * demand, which is why a new document type never needs a schema change.
 *
 * If the two totals ever disagree, something wrote to the journal tables
 * without going through JournalPostingService, and no other financial report
 * can be trusted until that is found. That is why the verdict is shown as a
 * banner rather than buried in a footer.
 */
export default function TrialBalancePage() {
  const { can } = usePermissions()

  const [filters, setFilters] = useState({
    date_from:   '',
    date_to:     '',
    location_id: '',
  })

  const { data: locations = [] } = useQuery({
    queryKey: ['locations', 'all'],
    queryFn:  getAllLocations,
    staleTime: 5 * 60 * 1000,
  })

  const { data, isLoading } = useQuery({
    queryKey: ['trial-balance', filters],
    queryFn:  () => getTrialBalance(filters),
    enabled:  can('view_finance_reports'),
  })

  // Memoised because the grouping below depends on it: a fresh [] every render
  // would defeat that memo entirely.
  const rows   = useMemo(() => data?.rows ?? [], [data])
  const totals = data?.totals ?? { debit: 0, credit: 0, difference: 0, balanced: true }

  /* ── Grouped by account type, with a subtotal per group ── */
  const groups = useMemo(() => {
    const byType = new Map()

    for (const row of rows) {
      const key = row.account_type ?? 'other'
      if (!byType.has(key)) {
        byType.set(key, { type: key, label: row.account_type_label ?? 'Other', rows: [], debit: 0, credit: 0 })
      }
      const g = byType.get(key)
      g.rows.push(row)
      g.debit  += Number(row.debit_balance)  || 0
      g.credit += Number(row.credit_balance) || 0
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

  return (
    <div className="p-3">
      <Breadcrumb crumbs={CRUMBS} />

      {/* ── Filters ─────────────────────────────────────────────────── */}
      <div className="mt-2 rounded-lg border border-slate-200 bg-white p-2">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
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
          totals.balanced
            ? 'border-green-200 bg-green-50 text-green-700'
            : 'border-red-200 bg-red-50 text-red-700'
        }`}
      >
        {totals.balanced ? <Check size={15} /> : <AlertTriangle size={15} />}
        {totals.balanced ? (
          <span>Balanced — debits equal credits.</span>
        ) : (
          <span>
            Out of balance by {money(totals.difference)}. Something wrote to the ledger without
            going through the posting service.
          </span>
        )}
        <span className="ml-auto flex items-center gap-1 text-xs font-normal text-slate-500">
          <Scale size={12} /> {rows.length} account{rows.length === 1 ? '' : 's'} with movement
        </span>
      </div>

      {/* ── The report ──────────────────────────────────────────────── */}
      <div className="mt-2 overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead className="sticky top-0 bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
            <tr>
              <th className="px-2 py-2 text-left">Code</th>
              <th className="px-2 py-2 text-left">Account</th>
              <th className="px-2 py-2 text-left">Control Account</th>
              <th className="px-2 py-2 text-right">Debit</th>
              <th className="px-2 py-2 text-right">Credit</th>
            </tr>
          </thead>

          <tbody>
            {isLoading && (
              <tr><td colSpan={5} className="px-2 py-6 text-center text-sm text-slate-400">Loading…</td></tr>
            )}

            {!isLoading && rows.length === 0 && (
              <tr>
                <td colSpan={5} className="px-2 py-6 text-center text-sm text-slate-400">
                  No journal entries in this range yet. Post a Journal Voucher to see it here.
                </td>
              </tr>
            )}

            {groups.map((g) => (
              // Fragment carries the key: a bare <> inside a map leaves React
              // without one, and it cannot go on the children instead.
              <Fragment key={g.type}>
                <tr className="bg-slate-100/70">
                  <td colSpan={5} className="px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                    {g.label}
                  </td>
                </tr>

                {g.rows.map((row) => (
                  <tr key={row.ledger_account_id} className="border-t border-slate-100 hover:bg-slate-50">
                    <td className="px-2 py-1.5 font-mono text-xs text-slate-500">{row.code}</td>
                    <td className="px-2 py-1.5 text-slate-800">
                      <span className="line-clamp-1" title={row.ledger_account_name}>
                        {row.ledger_account_name}
                      </span>
                    </td>
                    <td className="px-2 py-1.5 text-xs text-slate-400">
                      <span className="line-clamp-1">{row.control_account_name}</span>
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums text-slate-800">
                      {Number(row.debit_balance) > 0 ? money(row.debit_balance) : ''}
                    </td>
                    <td className="px-2 py-1.5 text-right tabular-nums text-slate-800">
                      {Number(row.credit_balance) > 0 ? money(row.credit_balance) : ''}
                    </td>
                  </tr>
                ))}

                <tr className="border-t border-slate-200 bg-slate-50/60 text-xs font-semibold">
                  <td colSpan={3} className="px-2 py-1 text-right text-slate-500">{g.label} subtotal</td>
                  <td className="px-2 py-1 text-right tabular-nums text-slate-700">{money(g.debit)}</td>
                  <td className="px-2 py-1 text-right tabular-nums text-slate-700">{money(g.credit)}</td>
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
                <td className="px-2 py-2 text-right tabular-nums text-slate-900">{money(totals.debit)}</td>
                <td className="px-2 py-2 text-right tabular-nums text-slate-900">{money(totals.credit)}</td>
              </tr>
            </tfoot>
          )}
        </table>
      </div>
    </div>
  )
}
