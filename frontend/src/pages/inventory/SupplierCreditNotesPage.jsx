import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getSupplierCreditNotes } from '../../api/supplierCreditNotes'
import { getAllSuppliers } from '../../api/suppliers'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Purchasing' },
  { label: 'Supplier Credit Notes' },
]

const INITIAL_FILTERS = { supplier_id: '', credit_type: '', status: '' }

const TYPE_STYLES = {
  customer_return: 'bg-sky-100 text-sky-700',
  over_payment:    'bg-purple-100 text-purple-700',
  advance:         'bg-indigo-100 text-indigo-700',
}

const STATUS_STYLES = {
  open:      'bg-green-100 text-green-700',
  exhausted: 'bg-slate-100 text-slate-500',
}

export default function SupplierCreditNotesPage() {
  const [page, setPage] = useState(1)
  const resetPage       = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const { data: suppliersData } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn:  getAllSuppliers,
    staleTime: Infinity,
  })
  const supplierOptions = (suppliersData ?? []).map((s) => ({ value: s.id, label: s.name }))
  const supplierNameById = new Map((suppliersData ?? []).map((s) => [s.id, s.name]))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['supplier-credit-notes', page, applied],
    queryFn:  () => getSupplierCreditNotes(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Supplier Credit Notes</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Supplier">
          <FilterSearchSelect
            value={draft.supplier_id}
            onChange={(val) => setDraft((d) => ({ ...d, supplier_id: val }))}
            options={supplierOptions}
            placeholder="All suppliers"
          />
        </FilterField>
        <FilterField label="Type">
          <select className={FILTER_SELECT_CLS} value={draft.credit_type} onChange={(e) => setDraft((d) => ({ ...d, credit_type: e.target.value }))}>
            <option value="">All types</option>
            <option value="customer_return">Customer Return</option>
            <option value="over_payment">Over Payment</option>
            <option value="advance">Advance</option>
          </select>
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            <option value="open">Open</option>
            <option value="exhausted">Exhausted</option>
          </select>
        </FilterField>
      </TableFilter>

      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load credit notes.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Credit Note No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Remaining</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Remark</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No credit notes match the current filters.' : 'No supplier credit notes yet.'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((cn, i) => (
                      <tr key={cn.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                        <td className="px-3 py-2 font-mono text-amber-600">{cn.credit_note_no}</td>
                        <td className="px-3 py-2 font-medium text-slate-700">{supplierNameById.get(cn.supplier_id) || `#${cn.supplier_id}`}</td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${TYPE_STYLES[cn.credit_type] ?? 'bg-slate-100 text-slate-500'}`}>
                            {cn.credit_type_label}
                          </span>
                        </td>
                        <td className="px-3 py-2 text-right text-slate-600"><Money value={cn.amount} /></td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700"><Money value={cn.remaining_balance} /></td>
                        <td className="px-3 py-2 text-slate-500 truncate max-w-xs" title={cn.remark}>{cn.remark || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[cn.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {cn.status_label}
                          </span>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{cn.created_at?.slice(0, 10)}</td>
                      </tr>
                    ))
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
