import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Users } from 'lucide-react'
import { getSupplierSummaryReport } from '../../../api/reports'
import Pagination from '../../../components/ui/Pagination'
import Money from '../../../components/ui/Money'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS } from '../../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Reports' },
  { label: 'Supplier Summary' },
]

const INITIAL_FILTERS = { search: '', date_from: '', date_to: '' }


export default function SupplierSummaryReport() {
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-supplier-summary', page, applied],
    queryFn: () => getSupplierSummaryReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <Users size={18} className="text-slate-600" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Supplier Purchase Summary</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Supplier name or code…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Date From">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_from} onChange={(e) => setDraft((d) => ({ ...d, date_from: e.target.value }))} />
        </FilterField>
        <FilterField label="Date To">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_to} onChange={(e) => setDraft((d) => ({ ...d, date_to: e.target.value }))} />
        </FilterField>
      </TableFilter>

      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load supplier summary.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Email</th>
                    <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">PO Count</th>
                    <th className="w-32 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">PO Value</th>
                    <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">GRN Count</th>
                    <th className="w-32 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">GRN Value</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Last Order</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="px-4 py-12 text-center text-sm text-slate-400">No supplier data found.</td>
                    </tr>
                  ) : (
                    rows.map((row, i) => (
                      <tr key={row.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                        <td className="px-3 py-2 font-mono text-slate-600">{row.supplier_code}</td>
                        <td className="px-3 py-2 font-medium text-slate-800">{row.supplier_name}</td>
                        <td className="px-3 py-2 capitalize text-slate-500">{row.supplier_type?.replace('_', ' ') || <span className="italic text-slate-300">—</span>}</td>
                        <td className="max-w-[120px] truncate px-3 py-2 text-slate-500" title={row.email}>{row.email || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700">{row.po_count}</td>
                        <td className="px-3 py-2 text-right font-bold text-slate-800"><Money value={row.po_value} /></td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700">{row.grn_count}</td>
                        <td className="px-3 py-2 text-right font-bold text-green-700"><Money value={row.grn_value} /></td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.last_order_date || <span className="italic text-slate-300">—</span>}</td>
                      </tr>
                    ))
                  )}
                </tbody>
                {rows.length > 0 && (
                  <tfoot>
                    <tr className="border-t-2 border-slate-200 bg-slate-50">
                      <td colSpan={5} className="px-3 py-2 text-right text-xs font-bold uppercase tracking-wider text-slate-600">Totals</td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-slate-800">{rows.reduce((s, r) => s + Number(r.po_count), 0)}</td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-slate-800"><Money value={rows.reduce((s, r) => s + Number(r.po_value), 0)} /></td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-slate-800">{rows.reduce((s, r) => s + Number(r.grn_count), 0)}</td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-green-700"><Money value={rows.reduce((s, r) => s + Number(r.grn_value), 0)} /></td>
                      <td />
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
            <Pagination meta={meta} page={page} onPageChange={setPage} />
          </>
        )}
      </div>
    </div>
  )
}
