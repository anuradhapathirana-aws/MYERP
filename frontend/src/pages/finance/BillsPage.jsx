import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, CheckCircle, Plus, Wallet } from 'lucide-react'
import { confirmBill, deleteBill, getBillOptions, getBills, voidBill } from '../../api/bills'
import { getAllSuppliers } from '../../api/suppliers'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { usePermissions } from '../../hooks/usePermissions'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { confirmAction, confirmDelete, confirmWithReasonAndDate, showError, showSuccess } from '../../utils/alerts'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [{ label: 'Finance' }, { label: 'Payables' }, { label: 'Bills' }]

const INITIAL_FILTERS = {
  search: '', status: '', payment_status: '', supplier_id: '', company_id: '', date_from: '', date_to: '', overdue: '',
}

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  confirmed: 'bg-green-100 text-green-700',
  voided:    'bg-slate-200 text-slate-500 line-through',
}

const PAYMENT_STYLES = {
  unpaid:      'bg-slate-100 text-slate-600',
  partly_paid: 'bg-amber-100 text-amber-700',
  paid:        'bg-green-100 text-green-700',
}

const errorMessage = (err, fallback) => {
  const errors = err?.response?.data?.errors
  const first = errors ? Object.values(errors)[0]?.[0] : null
  return first ?? err?.response?.data?.message ?? fallback
}

/**
 * Supplier bills for expenses and services.
 *
 * Draft: view, edit, confirm, delete. Confirmed: view, void (reason + reversal
 * date). Voided: view only. There is no edit of a confirmed bill -- it is
 * voided and entered again, so the books keep both.
 */
export default function BillsPage() {
  const { can } = usePermissions()
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } = useTableFilter(INITIAL_FILTERS)

  const { data: suppliers = [] } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn: getAllSuppliers,
    staleTime: 5 * 60 * 1000,
  })
  const supplierOptions = suppliers.map((s) => ({ value: s.id, label: s.name }))

  const { data: options = {} } = useQuery({
    queryKey: ['bill-options'],
    queryFn: getBillOptions,
    staleTime: 5 * 60 * 1000,
  })

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['bills', page, applied],
    queryFn: () => getBills(page, applied),
    placeholderData: (prev) => prev,
    enabled: can('view_bills'),
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['bills'] })
    qc.invalidateQueries({ queryKey: ['journal-entries'] })
  }

  const confirmMutation = useMutation({
    mutationFn: confirmBill,
    onSuccess: (bill) => { refresh(); showSuccess(`${bill?.bill_no} confirmed and posted.`) },
    onError: (e) => showError(errorMessage(e, 'Could not confirm this bill.')),
  })

  const voidMutation = useMutation({
    mutationFn: ({ id, ...body }) => voidBill(id, body),
    onSuccess: (bill) => { refresh(); showSuccess(`${bill?.bill_no} voided. Journal reversed.`) },
    onError: (e) => showError(errorMessage(e, 'Could not void this bill.')),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteBill,
    onSuccess: () => { refresh(); showSuccess('Draft bill deleted.') },
    onError: (e) => showError(errorMessage(e, 'Only draft bills can be deleted.')),
  })

  const handleConfirm = async (b) => {
    const ok = await confirmAction({
      title: `Confirm ${b.bill_no}?`,
      message: `This posts <strong>${Number(b.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</strong> to the ledger against <strong>${b.supplier_name ?? 'the supplier'}</strong>. Once confirmed it can only be reversed by a Void.`,
      confirmText: 'Yes, Confirm',
    })
    if (ok) confirmMutation.mutate(b.id)
  }

  const handleVoid = async (b) => {
    const result = await confirmWithReasonAndDate({
      title: `Void ${b.bill_no}?`,
      documentDate: b.transaction_date,
      lockDate: options.lock_date,
      confirmText: 'Yes, Void Bill',
    })
    if (result) voidMutation.mutate({ id: b.id, ...result })
  }

  const handleDelete = async (b) => {
    if (await confirmDelete(b.bill_no)) deleteMutation.mutate(b.id)
  }

  const rows = data?.data ?? []
  const meta = data?.meta
  const busy = confirmMutation.isPending || voidMutation.isPending || deleteMutation.isPending
  const set = (key) => (e) => setDraft((d) => ({ ...d, [key]: e.target.value }))

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Bills</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_bills') && (
          <Link
            to="/finance/bills/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            Create Bill
          </Link>
        )}
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Bill No, Document No, Supplier…" value={draft.search} onChange={set('search')} maxLength={100} />
        </FilterField>
        <FilterField label="Supplier">
          <FilterSearchSelect value={draft.supplier_id} onChange={(v) => setDraft((d) => ({ ...d, supplier_id: v }))} options={supplierOptions} placeholder="All suppliers" />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={set('status')}>
            <option value="">All statuses</option>
            {(options.statuses ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </FilterField>
        <FilterField label="Payment">
          <select className={FILTER_SELECT_CLS} value={draft.payment_status} onChange={set('payment_status')}>
            <option value="">All</option>
            {(options.payment_statuses ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </FilterField>
        {(options.companies ?? []).length > 1 && (
          <FilterField label="Company">
            <select className={FILTER_SELECT_CLS} value={draft.company_id} onChange={set('company_id')}>
              <option value="">All companies</option>
              {options.companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FilterField>
        )}
        <FilterField label="Date From">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_from} onChange={set('date_from')} />
        </FilterField>
        <FilterField label="Date To">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_to} onChange={set('date_to')} />
        </FilterField>
        <FilterField label="Overdue">
          <select className={FILTER_SELECT_CLS} value={draft.overdue} onChange={set('overdue')}>
            <option value="">All</option>
            <option value="1">Overdue only</option>
          </select>
        </FilterField>
      </TableFilter>

      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && (
          <div className="flex items-center justify-center py-14 text-sm text-red-500">
            {error?.response?.status === 403
              ? (error.response.data?.message || 'You do not have access to Bills, or the Finance module is not enabled.')
              : 'Failed to load bills.'}
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="max-h-[calc(100vh-220px)] overflow-auto">
              <table className="w-full text-xs">
                <thead className="sticky top-0 z-10">
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Bill No</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Document No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Account</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Due Date</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Payment</th>
                    <th className="sticky right-0 w-32 bg-slate-50 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={11} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No bills match the current filters.' : 'No bills yet.'}
                      </td>
                    </tr>
                  ) : rows.map((b, i) => (
                    <tr key={b.id} className="group transition-colors hover:bg-slate-50">
                      <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                      <td className="whitespace-nowrap px-3 py-2 font-mono font-medium text-indigo-600">
                        <Link to={`/finance/bills/${b.id}`} className="hover:underline">{b.bill_no}</Link>
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-slate-500">{b.transaction_date}</td>
                      <td className="max-w-[180px] truncate px-3 py-2 font-medium text-slate-700" title={b.supplier_name}>{b.supplier_name}</td>
                      <td className="max-w-[120px] truncate px-3 py-2 font-mono text-slate-600" title={b.document_no}>{b.document_no}</td>
                      <td className="max-w-[200px] truncate px-3 py-2 text-slate-600" title={b.ledger_account_name}>{b.ledger_account_name}</td>
                      <td className="px-3 py-2 text-right font-medium text-slate-700"><Money value={b.total_amount} /></td>
                      <td className={`whitespace-nowrap px-3 py-2 ${b.is_overdue ? 'font-semibold text-red-600' : 'text-slate-500'}`}>
                        {b.due_date}
                      </td>
                      <td className="px-3 py-2">
                        <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[b.status] ?? ''}`}>{b.status_label}</span>
                      </td>
                      <td className="px-3 py-2">
                        {b.status === 'confirmed' && (
                          <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${b.is_overdue ? 'bg-red-100 text-red-700' : PAYMENT_STYLES[b.payment_status] ?? ''}`}>
                            {b.is_overdue ? 'Overdue' : b.payment_status_label}
                          </span>
                        )}
                      </td>
                      <td className="sticky right-0 bg-white px-3 py-2 group-hover:bg-slate-50">
                        <div className="flex items-center justify-end gap-1">
                          <ViewBtn to={`/finance/bills/${b.id}`} />
                          {b.status === 'draft' && (
                            <>
                              {can('edit_bills') && <EditBtn to={`/finance/bills/${b.id}/edit`} />}
                              {can('confirm_bills') && (
                                <button type="button" title="Confirm Bill" onClick={() => handleConfirm(b)} disabled={busy} className="inline-flex items-center justify-center rounded-lg bg-green-50 p-1.5 text-green-600 transition-colors hover:bg-green-100 disabled:opacity-40">
                                  <CheckCircle size={14} strokeWidth={2} />
                                </button>
                              )}
                              {can('delete_bills') && <DeleteBtn onClick={() => handleDelete(b)} disabled={busy} />}
                            </>
                          )}
                          {b.status === 'confirmed' && b.payment_status !== 'paid' && can('create_bill_payments') && (
                            <Link
                              to={`/finance/bill-payments/create?supplier_id=${b.supplier_id}&company_id=${b.company_id}&bill_id=${b.id}`}
                              title="Pay Bill"
                              className="inline-flex items-center justify-center rounded-lg bg-indigo-50 p-1.5 text-indigo-600 transition-colors hover:bg-indigo-100"
                            >
                              <Wallet size={14} strokeWidth={2} />
                            </Link>
                          )}
                          {b.status === 'confirmed' && can('void_bills') && (
                            <button type="button" title="Void Bill" onClick={() => handleVoid(b)} disabled={busy} className="inline-flex items-center justify-center rounded-lg bg-red-50 p-1.5 text-red-600 transition-colors hover:bg-red-100 disabled:opacity-40">
                              <Ban size={14} strokeWidth={2} />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
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
