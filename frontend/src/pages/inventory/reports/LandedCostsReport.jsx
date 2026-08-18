import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Receipt } from 'lucide-react'
import { getLandedCostsReport } from '../../../api/reports'
import { getAllSuppliers } from '../../../api/suppliers'
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
  { label: 'Landed Costs' },
]

const INITIAL_FILTERS = { search: '', status: '', costing_type: '', supplier_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-slate-100 text-slate-500',
  confirmed: 'bg-green-100 text-green-700',
}

const TYPE_STYLES = {
  fob: 'bg-blue-100 text-blue-700',
  cif: 'bg-purple-100 text-purple-700',
}


export default function LandedCostsReport() {
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: suppliersData } = useQuery({ queryKey: ['suppliers-all'], queryFn: getAllSuppliers, staleTime: Infinity })
  const supplierOptions = (suppliersData ?? []).map((s) => ({ value: s.id, label: s.name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-landed-costs', page, applied],
    queryFn: () => getLandedCostsReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <Receipt size={18} className="text-blue-600" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Landed Costs Report</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Doc No, ref, supplier…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="confirmed">Confirmed</option>
          </select>
        </FilterField>
        <FilterField label="Costing Type">
          <select className={FILTER_SELECT_CLS} value={draft.costing_type} onChange={(e) => setDraft((d) => ({ ...d, costing_type: e.target.value }))}>
            <option value="">All types</option>
            <option value="fob">FOB</option>
            <option value="cif">CIF</option>
          </select>
        </FilterField>
        <FilterField label="Supplier">
          <FilterSearchSelect value={draft.supplier_id} onChange={(val) => setDraft((d) => ({ ...d, supplier_id: val }))} options={supplierOptions} placeholder="All suppliers" />
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
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load landed costs.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Doc No</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Reference</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">GRNs</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Material Cost</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Add. Expenses</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">VAT</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total Landed</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={12} className="px-4 py-12 text-center text-sm text-slate-400">No costing records found.</td>
                    </tr>
                  ) : (
                    rows.map((row, i) => (
                      <tr key={row.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">{row.document_no}</td>
                        <td className="px-3 py-2 font-mono text-slate-500">{row.reference_no || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 font-medium text-slate-700">{row.supplier_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.transaction_date?.slice(0, 10)}</td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${TYPE_STYLES[row.costing_type] ?? 'bg-slate-100 text-slate-500'}`}>
                            {row.costing_type?.toUpperCase()}
                          </span>
                        </td>
                        <td className="px-3 py-2 text-right text-slate-600">{row.grn_count}</td>
                        <td className="px-3 py-2 text-right text-slate-700"><Money value={row.material_cost} /></td>
                        <td className="px-3 py-2 text-right text-slate-700"><Money value={row.total_additional_expenses} /></td>
                        <td className="px-3 py-2 text-right text-slate-700"><Money value={row.vat_amount} /></td>
                        <td className="px-3 py-2 text-right font-bold text-blue-700"><Money value={row.total_landed_cost} /></td>
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
                      <td colSpan={7} className="px-3 py-2 text-right text-xs font-bold uppercase tracking-wider text-slate-600">Page Total</td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-slate-800"><Money value={rows.reduce((s, r) => s + Number(r.material_cost), 0)} /></td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-slate-800"><Money value={rows.reduce((s, r) => s + Number(r.total_additional_expenses), 0)} /></td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-slate-800"><Money value={rows.reduce((s, r) => s + Number(r.vat_amount), 0)} /></td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-blue-700"><Money value={rows.reduce((s, r) => s + Number(r.total_landed_cost), 0)} /></td>
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
