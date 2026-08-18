import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { PackageCheck } from 'lucide-react'
import { getGrnReport } from '../../../api/reports'
import { getAllSuppliers } from '../../../api/suppliers'
import { getAllLocations } from '../../../api/locations'
import { getAllStores } from '../../../api/stores'
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
  { label: 'GRN Report' },
]

const INITIAL_FILTERS = { search: '', grn_no: '', status: '', supplier_id: '', location_id: '', store_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-slate-100 text-slate-500',
  confirmed: 'bg-green-100 text-green-700',
}

const fmt = (n) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })

export default function GrnReport() {
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: suppliersData } = useQuery({ queryKey: ['suppliers-all'], queryFn: getAllSuppliers, staleTime: Infinity })
  const { data: locationsData } = useQuery({ queryKey: ['locations-all'], queryFn: getAllLocations, staleTime: Infinity })
  const { data: storesData }    = useQuery({ queryKey: ['stores-all'],    queryFn: getAllStores,    staleTime: Infinity })

  const supplierOptions = (suppliersData ?? []).map((s) => ({ value: s.id, label: s.name }))
  const storeOptions    = (storesData    ?? []).map((s) => ({ value: s.id, label: s.store_name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-grn', page, applied],
    queryFn: () => getGrnReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <PackageCheck size={18} className="text-green-600" />
            <h1 className="text-xl font-bold leading-none text-slate-800">GRN Report</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="GRN No, PO No, supplier…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="GRN No">
          <input className={FILTER_INPUT_CLS} placeholder="GRN number…" value={draft.grn_no} onChange={(e) => setDraft((d) => ({ ...d, grn_no: e.target.value }))} />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="confirmed">Confirmed</option>
          </select>
        </FilterField>
        <FilterField label="Supplier">
          <FilterSearchSelect value={draft.supplier_id} onChange={(val) => setDraft((d) => ({ ...d, supplier_id: val }))} options={supplierOptions} placeholder="All suppliers" />
        </FilterField>
        <FilterField label="Location">
          <FilterSearchSelect value={draft.location_id} onChange={(val) => setDraft((d) => ({ ...d, location_id: val }))} options={(locationsData ?? []).map((l) => ({ value: l.id, label: l.location_name }))} placeholder="All locations" />
        </FilterField>
        <FilterField label="Store">
          <FilterSearchSelect value={draft.store_id} onChange={(val) => setDraft((d) => ({ ...d, store_id: val }))} options={storeOptions} placeholder="All stores" />
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
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load GRN data.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">GRN No</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">PO No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Location</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Store</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">GRN Date</th>
                    <th className="w-12 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Items</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total Qty</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={11} className="px-4 py-12 text-center text-sm text-slate-400">No GRN records found.</td>
                    </tr>
                  ) : (
                    rows.map((row, i) => (
                      <tr key={row.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">{row.grn_no}</td>
                        <td className="px-3 py-2 font-mono text-slate-500">{row.po_no || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 font-medium text-slate-700">{row.supplier_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-500">{row.location_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-500">{row.store_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.grn_date}</td>
                        <td className="px-3 py-2 text-right text-slate-600">{row.item_count}</td>
                        <td className="px-3 py-2 text-right text-slate-600">{fmt(row.total_qty)}</td>
                        <td className="px-3 py-2 text-right font-bold text-slate-800"><Money value={row.total_amount} /></td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize ${STATUS_STYLES[row.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {row.status}
                          </span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
                {rows.length > 0 && (
                  <tfoot>
                    <tr className="border-t-2 border-slate-200 bg-slate-50">
                      <td colSpan={9} className="px-3 py-2 text-right text-xs font-bold uppercase tracking-wider text-slate-600">Page Total</td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-slate-800">
                        <Money value={rows.reduce((s, r) => s + Number(r.total_amount), 0)} />
                      </td>
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
