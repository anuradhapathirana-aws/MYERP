import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle, Plus } from 'lucide-react'
import {
  confirmGoodsReceivedNote,
  deleteGoodsReceivedNote,
  downloadGrnPdf,
  getGoodsReceivedNotes,
} from '../../api/goodsReceivedNotes'
import { getAllSuppliers } from '../../api/suppliers'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, confirmAction, showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'
import { ViewBtn, EditBtn, DeleteBtn, PrintBtn, PdfBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Purchasing' },
  { label: 'Goods Received Notes' },
]

const INITIAL_FILTERS = { search: '', status: '', supplier_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  confirmed: 'bg-green-100 text-green-700',
}

export default function GoodsReceivedNotesPage() {
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
    queryKey: ['grns', page, applied],
    queryFn:  () => getGoodsReceivedNotes(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteGoodsReceivedNote,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['grns'] }); showSuccess('GRN deleted.') },
    onError:    () => showError('Cannot delete — only draft GRNs can be removed.'),
  })

  const confirmMutation = useMutation({
    mutationFn: confirmGoodsReceivedNote,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['grns'] }); queryClient.invalidateQueries({ queryKey: ['purchase-orders'] }); showSuccess('GRN confirmed. Stock updated successfully.') },
    onError:    (err) => showError(err.response?.data?.message ?? 'Confirmation failed.'),
  })

  const handleDelete = async (id, grnNo) => {
    if (await confirmDelete(grnNo)) deleteMutation.mutate(id)
  }

  const handleConfirm = async (id, grnNo) => {
    const ok = await confirmAction({
      title: `Confirm ${grnNo}?`,
      message: 'This will <strong>post stock</strong> into the inventory for all received items. This action cannot be undone.',
      confirmText: 'Yes, Confirm & Post Stock',
    })
    if (ok) confirmMutation.mutate(id)
  }

  const handleDownloadPdf = async (id, grnNo) => {
    setPdfBusy({ id, action: 'download' })
    try {
      const blob = await downloadGrnPdf(id)
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      a.download = `GRN_${grnNo}.pdf`
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
      const blob = await downloadGrnPdf(id)
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Goods Received Notes</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/goods-received-notes/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New GRN
        </Link>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="GRN No or PO No…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="confirmed">Confirmed</option>
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

      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load GRNs.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">GRN No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">GRN Date</th>
                    <th className="w-44 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Store</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No GRNs match the current filters.' : 'No goods received notes yet.'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((grn, i) => (
                      <tr key={grn.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/goods-received-notes/${grn.id}`} className="hover:underline">{grn.grn_no}</Link>
                        </td>
                        <td className="px-3 py-2 font-medium text-slate-700">{grn.supplier?.name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{grn.grn_date}</td>
                        <td className="max-w-44 truncate px-3 py-2 text-slate-500" title={grn.store?.name || ''}>
                          {grn.store?.name || <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700">
                          <Money value={grn.total_amount} />
                        </td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[grn.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {grn.status_label}
                          </span>
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/goods-received-notes/${grn.id}`} />
                            <PrintBtn
                              onClick={() => handlePrintPdf(grn.id)}
                              disabled={pdfBusy?.id === grn.id}
                            />
                            <PdfBtn
                              onClick={() => handleDownloadPdf(grn.id, grn.grn_no)}
                              disabled={pdfBusy?.id === grn.id}
                            />
                            {grn.status === 'draft' && (
                              <>
                                <EditBtn to={`/inventory/goods-received-notes/${grn.id}/edit`} />
                                <button type="button" title="Confirm & Post Stock" onClick={() => handleConfirm(grn.id, grn.grn_no)} disabled={confirmMutation.isPending} className="inline-flex items-center justify-center rounded-lg p-1.5 bg-green-50 text-green-600 hover:bg-green-100 transition-colors disabled:opacity-40"><CheckCircle size={14} strokeWidth={2} /></button>
                                <DeleteBtn onClick={() => handleDelete(grn.id, grn.grn_no)} disabled={deleteMutation.isPending} />
                              </>
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
