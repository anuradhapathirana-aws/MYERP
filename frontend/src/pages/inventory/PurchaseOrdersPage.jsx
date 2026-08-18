import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import {
  deletePurchaseOrder,
  downloadPoPdf,
  getPurchaseOrders,
} from '../../api/purchaseOrders'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'
import { getAllSuppliers } from '../../api/suppliers'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'
import { ViewBtn, EditBtn, DeleteBtn, PrintBtn, PdfBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Purchasing' },
  { label: 'Purchase Orders' },
]

const INITIAL_FILTERS = { search: '', status: '', supplier_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:              'bg-slate-100 text-slate-600',
  sent:               'bg-blue-100 text-blue-700',
  confirmed:          'bg-indigo-100 text-indigo-700',
  partially_received: 'bg-amber-100 text-amber-700',
  completed:          'bg-green-100 text-green-700',
  cancelled:          'bg-red-100 text-red-500',
}

const STATUS_OPTIONS = [
  { value: 'draft',              label: 'Draft' },
  { value: 'sent',               label: 'Sent to Supplier' },
  { value: 'confirmed',          label: 'Confirmed' },
  { value: 'partially_received', label: 'Partially Received' },
  { value: 'completed',          label: 'Completed' },
  { value: 'cancelled',          label: 'Cancelled' },
]

export default function PurchaseOrdersPage() {
  const [page, setPage] = useState(1)
  const [pdfBusy, setPdfBusy] = useState(null) // { id, action: 'print' | 'download' }
  const queryClient     = useQueryClient()
  const resetPage       = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const { data: suppliersData } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn:  getAllSuppliers,
    staleTime: Infinity,
  })
  const supplierOptions = (suppliersData ?? []).map((s) => ({ value: s.id, label: s.name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['purchase-orders', page, applied],
    queryFn:  () => getPurchaseOrders(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deletePurchaseOrder,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['purchase-orders'] }); showSuccess('Purchase order deleted.') },
    onError:    () => showError('Cannot delete — only draft POs can be removed.'),
  })

  const handleDelete = async (id, poNo) => {
    if (await confirmDelete(poNo)) deleteMutation.mutate(id)
  }

  const handleDownloadPdf = async (id, poNo) => {
    setPdfBusy({ id, action: 'download' })
    try {
      const blob = await downloadPoPdf(id)
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      a.download = `PO_${poNo}.pdf`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      showError('Failed to download PDF.')
    } finally {
      setPdfBusy(null)
    }
  }

  const handlePrintPdf = async (id) => {
    setPdfBusy({ id, action: 'print' })
    try {
      const blob = await downloadPoPdf(id)
      printPdfBlob(blob)
    } catch {
      showError('Failed to print PDF.')
    } finally {
      setPdfBusy(null)
    }
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Purchase Orders</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/purchase-orders/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New PO
        </Link>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="PO No or supplier…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            {STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </FilterField>
        <FilterField label="Supplier">
          <FilterSearchSelect
            value={draft.supplier_id}
            onChange={(val) => setDraft((d) => ({ ...d, supplier_id: val }))}
            options={supplierOptions}
            placeholder="All suppliers"
          />
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
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load purchase orders.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">PO No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-44 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Store</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Order Date</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Exp. Delivery</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No purchase orders match the current filters.' : (
                          <><Link to="/inventory/purchase-orders/create" className="font-medium text-indigo-600 hover:underline">Create the first PO.</Link></>
                        )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((po, i) => (
                      <tr key={po.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/purchase-orders/${po.id}`} className="hover:underline">{po.po_no}</Link>
                          {po.purchase_request?.pr_no && (
                            <div className="text-[9px] font-normal text-slate-400">PR: {po.purchase_request.pr_no}</div>
                          )}
                        </td>
                        <td className="px-3 py-2 font-medium text-slate-700">{po.supplier?.name || <span className="italic text-slate-300">—</span>}</td>
                        <td
                          className="max-w-44 truncate px-3 py-2 text-slate-600"
                          title={po.store?.name || po.purchase_request?.source_store?.name || ''}
                        >
                          {po.store?.name || po.purchase_request?.source_store?.name || <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{po.order_date}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{po.expected_delivery_date || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700"><Money value={po.grand_total} /></td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[po.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {po.status_label}
                          </span>
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/purchase-orders/${po.id}`} />
                            <PrintBtn
                              onClick={() => handlePrintPdf(po.id)}
                              disabled={pdfBusy?.id === po.id}
                            />
                            <PdfBtn
                              onClick={() => handleDownloadPdf(po.id, po.po_no)}
                              disabled={pdfBusy?.id === po.id}
                            />
                            {(po.status === 'draft' || po.status === 'sent') && (
                              <EditBtn to={`/inventory/purchase-orders/${po.id}/edit`} />
                            )}
                            {po.status === 'draft' && (
                              <DeleteBtn onClick={() => handleDelete(po.id, po.po_no)} disabled={deleteMutation.isPending} />
                            )}
                          </div>
                        </td>
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
