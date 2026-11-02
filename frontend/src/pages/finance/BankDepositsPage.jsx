import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, CheckCircle, Plus } from 'lucide-react'
import {
  confirmBankDeposit, deleteBankDeposit, getBankDepositOptions, getBankDeposits, voidBankDeposit,
} from '../../api/bankDeposits'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import { useTableFilter } from '../../hooks/useTableFilter'
import { usePermissions } from '../../hooks/usePermissions'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { confirmAction, confirmDelete, confirmWithReasonAndDate, showError, showSuccess } from '../../utils/alerts'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [{ label: 'Finance' }, { label: 'Banking' }, { label: 'Bank Deposits' }]

const INITIAL_FILTERS = {
  search: '', status: '', company_id: '', bank_account_id: '', date_from: '', date_to: '',
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
 * Cash and received cheques paid into the bank.
 *
 * Draft: view, edit, confirm (bank it), delete. Confirmed: view, void (reason +
 * reversal date; the cheques go back in hand). Voided: view only.
 */
export default function BankDepositsPage() {
  const { can } = usePermissions()
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } = useTableFilter(INITIAL_FILTERS)

  const { data: options = {} } = useQuery({
    queryKey: ['bank-deposit-options'],
    queryFn: getBankDepositOptions,
    staleTime: 5 * 60 * 1000,
  })

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['bank-deposits', page, applied],
    queryFn: () => getBankDeposits(page, applied),
    placeholderData: (prev) => prev,
    enabled: can('view_bank_deposits'),
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['bank-deposits'] })
    qc.invalidateQueries({ queryKey: ['bank-deposit-in-hand'] })
    qc.invalidateQueries({ queryKey: ['journal-entries'] })
  }

  const confirmMutation = useMutation({
    mutationFn: confirmBankDeposit,
    onSuccess: (d) => { refresh(); showSuccess(`${d?.deposit_no} banked and posted.`) },
    onError: (e) => showError(errorMessage(e, 'Could not confirm this deposit.')),
  })

  const voidMutation = useMutation({
    mutationFn: ({ id, ...body }) => voidBankDeposit(id, body),
    onSuccess: (d) => { refresh(); showSuccess(`${d?.deposit_no} voided. Journal reversed and cheques back in hand.`) },
    onError: (e) => showError(errorMessage(e, 'Could not void this deposit.')),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteBankDeposit,
    onSuccess: () => { refresh(); showSuccess('Draft deposit deleted.') },
    onError: (e) => showError(errorMessage(e, 'Only draft deposits can be deleted.')),
  })

  const handleConfirm = async (d) => {
    const ok = await confirmAction({
      title: `Confirm ${d.deposit_no}?`,
      message: `This deposits <strong>${Number(d.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</strong> into <strong>${d.bank_account_name}</strong> and posts it to the ledger.`,
      confirmText: 'Yes, Deposit',
    })
    if (ok) confirmMutation.mutate(d.id)
  }

  const handleVoid = async (d) => {
    const result = await confirmWithReasonAndDate({
      title: `Void ${d.deposit_no}?`,
      documentDate: d.deposit_date,
      lockDate: options.lock_date,
      confirmText: 'Yes, Void Deposit',
    })
    if (result) voidMutation.mutate({ id: d.id, ...result })
  }

  const handleDelete = async (d) => {
    if (await confirmDelete(d.deposit_no)) deleteMutation.mutate(d.id)
  }

  const rows = data?.data ?? []
  const meta = data?.meta
  const busy = confirmMutation.isPending || voidMutation.isPending || deleteMutation.isPending
  const set = (key) => (e) => setDraft((d) => ({ ...d, [key]: e.target.value }))

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Bank Deposits</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_bank_deposits') && (
          <Link
            to="/finance/bank-deposits/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            Create Deposit
          </Link>
        )}
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Deposit No, Slip, Cheque, Receipt, Customer…" value={draft.search} onChange={set('search')} maxLength={100} />
        </FilterField>
        <FilterField label="Bank Account">
          <select className={FILTER_SELECT_CLS} value={draft.bank_account_id} onChange={set('bank_account_id')}>
            <option value="">All accounts</option>
            {(options.bank_accounts ?? []).map((a) => <option key={a.id} value={a.id}>{a.code} - {a.name}</option>)}
          </select>
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={set('status')}>
            <option value="">All statuses</option>
            {(options.statuses ?? []).map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
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
              ? (error.response.data?.message || 'You do not have access to Bank Deposits, or the Finance module is not enabled.')
              : 'Failed to load bank deposits.'}
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="max-h-[calc(100vh-220px)] overflow-auto">
              <table className="w-full text-xs">
                <thead className="sticky top-0 z-10">
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Deposit No</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Bank Account</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Slip No</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Cash</th>
                    <th className="w-14 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Chqs</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Cheques</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="sticky right-0 w-32 bg-slate-50 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={11} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No deposits match the current filters.' : 'No bank deposits yet.'}
                      </td>
                    </tr>
                  ) : rows.map((d, i) => (
                    <tr key={d.id} className="group transition-colors hover:bg-slate-50">
                      <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                      <td className="whitespace-nowrap px-3 py-2 font-mono font-medium text-indigo-600">
                        <Link to={`/finance/bank-deposits/${d.id}`} className="hover:underline">{d.deposit_no}</Link>
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-slate-500">{d.deposit_date}</td>
                      <td className="max-w-[220px] truncate px-3 py-2 font-medium text-slate-700" title={d.bank_account_name}>{d.bank_account_name}</td>
                      <td className="max-w-[100px] truncate px-3 py-2 font-mono text-slate-500" title={d.slip_no ?? ''}>{d.slip_no}</td>
                      <td className="px-3 py-2 text-right text-slate-600"><Money value={d.cash_total} /></td>
                      <td className="px-3 py-2 text-right text-slate-500">{d.cheque_count}</td>
                      <td className="px-3 py-2 text-right text-slate-600"><Money value={d.cheque_total} /></td>
                      <td className="px-3 py-2 text-right font-medium text-slate-700"><Money value={d.total_amount} /></td>
                      <td className="px-3 py-2">
                        <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[d.status] ?? ''}`}>{d.status_label}</span>
                      </td>
                      <td className="sticky right-0 bg-white px-3 py-2 group-hover:bg-slate-50">
                        <div className="flex items-center justify-end gap-1">
                          <ViewBtn to={`/finance/bank-deposits/${d.id}`} />
                          {d.status === 'draft' && (
                            <>
                              {can('edit_bank_deposits') && <EditBtn to={`/finance/bank-deposits/${d.id}/edit`} />}
                              {can('confirm_bank_deposits') && (
                                <button type="button" title="Confirm & Deposit" onClick={() => handleConfirm(d)} disabled={busy} className="inline-flex items-center justify-center rounded-lg bg-green-50 p-1.5 text-green-600 transition-colors hover:bg-green-100 disabled:opacity-40">
                                  <CheckCircle size={14} strokeWidth={2} />
                                </button>
                              )}
                              {can('delete_bank_deposits') && <DeleteBtn onClick={() => handleDelete(d)} disabled={busy} />}
                            </>
                          )}
                          {d.status === 'confirmed' && can('void_bank_deposits') && (
                            <button type="button" title="Void Deposit" onClick={() => handleVoid(d)} disabled={busy} className="inline-flex items-center justify-center rounded-lg bg-red-50 p-1.5 text-red-600 transition-colors hover:bg-red-100 disabled:opacity-40">
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
