import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { deleteInvoice, downloadInvoicePdf, getInvoices } from '../../api/invoices'
import { getAllCustomers } from '../../api/customers'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'
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
  { label: 'Sales' },
  { label: 'Invoices' },
]

const INITIAL_FILTERS = { search: '', status: '', customer_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  issued:    'bg-blue-100 text-blue-700',
  paid:      'bg-green-100 text-green-700',
  cancelled: 'bg-red-100 text-red-500',
}

const STATUS_OPTIONS = [
  { value: 'draft',     label: 'Draft' },
  { value: 'issued',    label: 'Issued' },
  { value: 'paid',      label: 'Paid' },
  { value: 'cancelled', label: 'Cancelled' },
]

export default function InvoicesPage() {
  const [page, setPage] = useState(1)
  const [pdfBusy, setPdfBusy] = useState(null)
  const navigate        = useNavigate()
  const queryClient     = useQueryClient()
  const resetPage       = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const { data: customers = [] } = useQuery({
    queryKey: ['customers-all'],
    queryFn:  getAllCustomers,
    staleTime: Infinity,
  })
  const customerOptions = customers.map((c) => ({ value: c.id, label: c.name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['invoices', page, applied],
    queryFn:  () => getInvoices(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteInvoice,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['invoices'] }); showSuccess('Invoice deleted.') },
    onError:    (e) => showError(e.response?.data?.message ?? 'Cannot delete — only draft invoices can be removed.'),
  })

  const handleDelete = async (id, no) => {
    if (await confirmDelete(no)) deleteMutation.mutate(id)
  }

  const handlePdf = async (id, no, print) => {
    setPdfBusy(id)
    try {
      const blob = await downloadInvoicePdf(id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href    = url
        a.download = `INV_${no}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError('Failed to generate PDF.')
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Invoices</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/invoices/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New Invoice
        </Link>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Invoice No, SO No or customer…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            {STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </FilterField>
        <FilterField label="Customer">
          <FilterSearchSelect
            value={draft.customer_id}
            onChange={(val) => setDraft((d) => ({ ...d, customer_id: val }))}
            options={customerOptions}
            placeholder="All customers"
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
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load invoices.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Invoice No</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">SO / DO</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0
                          ? 'No invoices match the current filters.'
                          : 'No invoices yet — click New Invoice and select a confirmed Delivery Order to bill.'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((row, i) => (
                      <tr
                        key={row.id}
                        onClick={() => navigate(`/inventory/invoices/${row.id}`)}
                        className="cursor-pointer transition-colors hover:bg-slate-50"
                      >
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/invoices/${row.id}`} className="hover:underline">{row.invoice_no}</Link>
                        </td>
                        <td className="px-3 py-2 font-mono text-[10px] text-slate-500">
                          <div>{row.sales_order?.so_no}</div>
                          <div className="text-slate-400">{row.delivery_order?.do_no ?? 'Direct'}</div>
                        </td>
                        <td className="px-3 py-2 font-medium text-slate-700">{row.customer?.name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.invoice_date}</td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700"><Money value={row.grand_total} /></td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[row.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {row.status_label}
                          </span>
                        </td>
                        {/* stopPropagation so action buttons don't also fire the row's navigate */}
                        <td className="px-3 py-2" onClick={(e) => e.stopPropagation()}>
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/invoices/${row.id}`} />
                            <PrintBtn onClick={() => handlePdf(row.id, row.invoice_no, true)} disabled={pdfBusy === row.id} />
                            <PdfBtn onClick={() => handlePdf(row.id, row.invoice_no, false)} disabled={pdfBusy === row.id} />
                            {row.status === 'draft' && (
                              <>
                                <EditBtn to={`/inventory/invoices/${row.id}/edit`} />
                                <DeleteBtn onClick={() => handleDelete(row.id, row.invoice_no)} disabled={deleteMutation.isPending} />
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
