import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FlaskConical } from 'lucide-react'
import { getBatchExpiryReport } from '../../../api/reports'
import { getAllProducts } from '../../../api/products'
import Pagination from '../../../components/ui/Pagination'
import Money from '../../../components/ui/Money'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Reports' },
  { label: 'Batch / Expiry' },
]

const INITIAL_FILTERS = {
  search: '',
  batch_no: '',
  product_code: '',
  product_id: '',
  status: '',
  expiry_days: '',
  date_from: '',
  date_to: '',
}

const BATCH_STATUSES = [
  { value: 'active', label: 'Active' },
  { value: 'quarantine', label: 'Quarantine' },
  { value: 'on_hold', label: 'On Hold' },
  { value: 'recalled', label: 'Recalled' },
  { value: 'expired', label: 'Expired' },
  { value: 'exhausted', label: 'Exhausted' },
]

const STATUS_STYLES = {
  active:     'bg-green-100 text-green-700',
  quarantine: 'bg-amber-100 text-amber-700',
  on_hold:    'bg-blue-100 text-blue-700',
  recalled:   'bg-red-100 text-red-600',
  expired:    'bg-slate-100 text-slate-500',
  exhausted:  'bg-slate-100 text-slate-400',
}

const EXPIRY_OPTIONS = [
  { value: '30', label: 'Expiring in 30 days' },
  { value: '60', label: 'Expiring in 60 days' },
  { value: '90', label: 'Expiring in 90 days' },
]

const fmt = (n) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })

export default function BatchExpiryReport() {
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: productsData } = useQuery({
    queryKey: ['products-all'],
    queryFn: getAllProducts,
    staleTime: Infinity,
  })
  const productOptions = (productsData ?? []).map((p) => ({
    value: p.id,
    label: p.product_code ? `[${p.product_code}] ${p.name}` : p.name,
  }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-batch-expiry', page, applied],
    queryFn: () => getBatchExpiryReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <FlaskConical size={18} className="text-purple-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Batch / Expiry Tracking Report</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Batch no or product…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Batch No">
          <input className={FILTER_INPUT_CLS} placeholder="Batch number…" value={draft.batch_no} onChange={(e) => setDraft((d) => ({ ...d, batch_no: e.target.value }))} />
        </FilterField>
        <FilterField label="Product Code">
          <input className={FILTER_INPUT_CLS} placeholder="Product code…" value={draft.product_code} onChange={(e) => setDraft((d) => ({ ...d, product_code: e.target.value }))} />
        </FilterField>
        <FilterField label="Product">
          <FilterSearchSelect
            value={draft.product_id}
            onChange={(val) => setDraft((d) => ({ ...d, product_id: val }))}
            options={productOptions} wide
            placeholder="All products"
          />
        </FilterField>
        <FilterField label="Batch Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            {BATCH_STATUSES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </FilterField>
        <FilterField label="Expiring In">
          <select className={FILTER_SELECT_CLS} value={draft.expiry_days} onChange={(e) => setDraft((d) => ({ ...d, expiry_days: e.target.value, date_from: '', date_to: '' }))}>
            <option value="">Any date</option>
            {EXPIRY_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </FilterField>
        <FilterField label="Expiry From">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_from} onChange={(e) => setDraft((d) => ({ ...d, date_from: e.target.value, expiry_days: '' }))} />
        </FilterField>
        <FilterField label="Expiry To">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_to} onChange={(e) => setDraft((d) => ({ ...d, date_to: e.target.value, expiry_days: '' }))} />
        </FilterField>
      </TableFilter>

      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load batch data.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Batch No</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Product Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Product</th>
                    <th className="w-36 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Mfg Date</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Expiry Date</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Days Left</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Initial Qty</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Current Qty</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Unit Cost</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={12} className="px-4 py-12 text-center text-sm text-slate-400">No batches found.</td>
                    </tr>
                  ) : (
                    rows.map((row, i) => {
                      const days = Number(row.days_to_expiry)
                      const expiryClass = !row.expiry_date ? '' : days < 0 ? 'text-red-600 font-bold' : days <= 30 ? 'text-amber-600 font-semibold' : 'text-slate-600'
                      return (
                        <tr key={row.id} className="transition-colors hover:bg-slate-50">
                          <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                          <td className="px-3 py-2 font-mono font-medium text-indigo-600">{row.batch_no}</td>
                          <td className="px-3 py-2 font-mono text-slate-600">{row.product_code}</td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.product_name}</td>
                          <td className="px-3 py-2 text-slate-500">{row.supplier_name || <span className="italic text-slate-300">—</span>}</td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.mfg_date || <span className="italic text-slate-300">—</span>}</td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.expiry_date || <span className="italic text-slate-300">—</span>}</td>
                          <td className={`px-3 py-2 text-right ${expiryClass}`}>
                            {row.expiry_date ? (days < 0 ? `${Math.abs(days)}d overdue` : `${days}d`) : '—'}
                          </td>
                          <td className="px-3 py-2 text-right text-slate-600">{fmt(row.initial_qty)}</td>
                          <td className="px-3 py-2 text-right font-medium text-slate-700">{fmt(row.current_qty)}</td>
                          <td className="px-3 py-2 text-right text-slate-600"><Money value={row.unit_cost} /></td>
                          <td className="px-3 py-2">
                            <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize ${STATUS_STYLES[row.status] ?? 'bg-slate-100 text-slate-500'}`}>
                              {row.status?.replace('_', ' ')}
                            </span>
                          </td>
                        </tr>
                      )
                    })
                  )}
                </tbody>
              </table>
            </div>
            <Pagination meta={meta} page={page} onPageChange={setPage} />
          </>
        )}
      </div>
    </div>
  )
}
