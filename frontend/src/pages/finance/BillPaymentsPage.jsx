import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, CheckCircle, Plus } from 'lucide-react'
import {
  confirmBillPayment, deleteBillPayment, getBillPaymentOptions, getBillPayments, voidBillPayment,
} from '../../api/billPayments'
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

const CRUMBS = [{ label: 'Finance' }, { label: 'Payables' }, { label: 'Bill Payments' }]

const INITIAL_FILTERS = {
  search: '', status: '', payment_mode_id: '', supplier_id: '', company_id: '', date_from: '', date_to: '',
}

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  confirmed: 'bg-green-100 text-green-700',
  voided:    'bg-slate-200 text-slate-500 line-through',
}

const errorMessage = (err, fallback) => {
  const errors = err?.response?.data?.errors
  const first = errors ? Object.values(errors)[0]?.[0] : null
  return first ?? err?.response?.data?.message ?? fallback
}

/**
 * Payments made against supplier bills.
 *
 * Draft: view, edit, confirm (pay), delete. Confirmed: view, void (reason +
 * reversal date; the bills reopen). Voided: view only.
 */
export default function BillPaymentsPage() {
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
    queryKey: ['bill-payment-options'],
    queryFn: getBillPaymentOptions,
    staleTime: 5 * 60 * 1000,
  })

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['bill-payments', page, applied],
    queryFn: () => getBillPayments(page, applied),
    placeholderData: (prev) => prev,
    enabled: can('view_bill_payments'),
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['bill-payments'] })
    qc.invalidateQueries({ queryKey: ['bills'] })
    qc.invalidateQueries({ queryKey: ['open-bills'] })
    qc.invalidateQueries({ queryKey: ['journal-entries'] })
  }

  const confirmMutation = useMutation({
    mutationFn: confirmBillPayment,
    onSuccess: (p) => { refresh(); showSuccess(`${p?.payment_no} paid and posted.`) },
    onError: (e) => showError(errorMessage(e, 'Could not confirm this payment.')),
  })

  const voidMutation = useMutation({
    mutationFn: ({ id, ...body }) => voidBillPayment(id, body),
    onSuccess: (p) => { refresh(); showSuccess(`${p?.payment_no} voided. Journal reversed and bills reopened.`) },
    onError: (e) => showError(errorMessage(e, 'Could not void this payment.')),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteBillPayment,
    onSuccess: () => { refresh(); showSuccess('Draft payment deleted.') },
    onError: (e) => showError(errorMessage(e, 'Only draft payments can be deleted.')),
  })

  const handleConfirm = async (p) => {
    const ok = await confirmAction({
      title: `Confirm ${p.payment_no}?`,
      message: `This pays <strong>${Number(p.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</strong> to <strong>${p.supplier_name ?? 'the supplier'}</strong> from <strong>${p.pay_from_account_name}</strong> and posts it to the ledger.`,
      confirmText: 'Yes, Pay',
    })
    if (ok) confirmMutation.mutate(p.id)
  }

  const handleVoid = async (p) => {
    const result = await confirmWithReasonAndDate({
      title: `Void ${p.payment_no}?`,
      documentDate: p.payment_date,
      lockDate: options.lock_date,
      confirmText: 'Yes, Void Payment',
    })
    if (result) voidMutation.mutate({ id: p.id, ...result })
  }

  const handleDelete = async (p) => {
    if (await confirmDelete(p.payment_no)) deleteMutation.mutate(p.id)
  }

  const rows = data?.data ?? []
  const meta = data?.meta
  const busy = confirmMutation.isPending || voidMutation.isPending || deleteMutation.isPending
  const set = (key) => (e) => setDraft((d) => ({ ...d, [key]: e.target.value }))

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Bill Payments</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_bill_payments') && (
          <Link
            to="/finance/bill-payments/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            Create Payment
          </Link>
        )}
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Payment No, Ref, Cheque, Vendor…" value={draft.search} onChange={set('search')} maxLength={100} />
        </FilterField>
        <FilterField label="Vendor">
          <FilterSearchSelect value={draft.supplier_id} onChange={(v) => setDraft((d) => ({ ...d, supplier_id: v }))} options={supplierOptions} placeholder="All vendors" />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={set('status')}>
            <option value="">All statuses</option>
            {(options.statuses ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </FilterField>
        <FilterField label="Mode">
          <select className={FILTER_SELECT_CLS} value={draft.payment_mode_id} onChange={set('payment_mode_id')}>
            <option value="">All</option>
            {(options.payment_modes ?? []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
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
      </TableFilter>

      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && (
          <div className="flex items-center justify-center py-14 text-sm text-red-500">
            {error?.response?.status === 403
              ? (error.response.data?.message || 'You do not have access to Bill Payments, or the Finance module is not enabled.')
              : 'Failed to load bill payments.'}
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="max-h-[calc(100vh-220px)] overflow-auto">
              <table className="w-full text-xs">
                <thead className="sticky top-0 z-10">
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Payment No</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Vendor</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Paid From</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Method</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Reference</th>
                    <th className="w-14 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Bills</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="sticky right-0 w-32 bg-slate-50 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={11} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No payments match the current filters.' : 'No bill payments yet.'}
                      </td>
                    </tr>
                  ) : rows.map((p, i) => (
                    <tr key={p.id} className="group transition-colors hover:bg-slate-50">
                      <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                      <td className="whitespace-nowrap px-3 py-2 font-mono font-medium text-indigo-600">
                        <Link to={`/finance/bill-payments/${p.id}`} className="hover:underline">{p.payment_no}</Link>
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-slate-500">{p.payment_date}</td>
                      <td className="max-w-[180px] truncate px-3 py-2 font-medium text-slate-700" title={p.supplier_name}>{p.supplier_name}</td>
                      <td className="max-w-[180px] truncate px-3 py-2 text-slate-600" title={p.pay_from_account_name}>{p.pay_from_account_name}</td>
                      <td className="whitespace-nowrap px-3 py-2 text-slate-600">
                        {p.payment_mode_name}
                        {p.cheque_no && <span className="ml-1 font-mono text-slate-400">#{p.cheque_no}</span>}
                      </td>
                      <td className="max-w-[120px] truncate px-3 py-2 font-mono text-slate-500" title={p.reference_no ?? ''}>{p.reference_no}</td>
                      <td className="px-3 py-2 text-right text-slate-500">{p.bill_count}</td>
                      <td className="px-3 py-2 text-right font-medium text-slate-700"><Money value={p.total_amount} /></td>
                      <td className="px-3 py-2">
                        <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[p.status] ?? ''}`}>{p.status_label}</span>
                      </td>
                      <td className="sticky right-0 bg-white px-3 py-2 group-hover:bg-slate-50">
                        <div className="flex items-center justify-end gap-1">
                          <ViewBtn to={`/finance/bill-payments/${p.id}`} />
                          {p.status === 'draft' && (
                            <>
                              {can('edit_bill_payments') && <EditBtn to={`/finance/bill-payments/${p.id}/edit`} />}
                              {can('confirm_bill_payments') && (
                                <button type="button" title="Confirm & Pay" onClick={() => handleConfirm(p)} disabled={busy} className="inline-flex items-center justify-center rounded-lg bg-green-50 p-1.5 text-green-600 transition-colors hover:bg-green-100 disabled:opacity-40">
                                  <CheckCircle size={14} strokeWidth={2} />
                                </button>
                              )}
                              {can('delete_bill_payments') && <DeleteBtn onClick={() => handleDelete(p)} disabled={busy} />}
                            </>
                          )}
                          {p.status === 'confirmed' && can('void_bill_payments') && (
                            <button type="button" title="Void Payment" onClick={() => handleVoid(p)} disabled={busy} className="inline-flex items-center justify-center rounded-lg bg-red-50 p-1.5 text-red-600 transition-colors hover:bg-red-100 disabled:opacity-40">
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
