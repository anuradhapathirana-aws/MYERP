import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { BookOpen, Columns2, Flag, List, Lock, ShieldCheck } from 'lucide-react'
import { updateGoLiveDate, updateLedgerView, updateLockDate, updateMakerChecker } from '../../api/financeSettings'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'
import { FILTER_INPUT_CLS } from '../../utils/fieldStyles'

const firstError = (e, fallback) => {
  const errors = e?.response?.data?.errors
  return (errors && Object.values(errors)[0]?.[0]) ?? e?.response?.data?.message ?? fallback
}

/**
 * Period lock + maker-checker, on Finance > Account Mapping.
 *
 * Lock date: the last closed day. Once a month is reported (VAT return, P&L),
 * lock it so nothing can be posted, voided or back-dated into it again.
 * Moving it back reopens a period -- allowed, but recorded in the audit trail.
 */
// canLock: manage_finance_period_lock (client accountant, month-end close).
// canManageSetup: super admin only -- switching maker-checker off removes a
// fraud control, so it is changed by the IT company on the client's request.
// ledgerView: how a Trial Balance account opens by default ('list' | 't_account').
// goLiveDate: first day the ledger is complete (cut-over); statements start here.
export default function FinanceControlsCard({ lockDate, goLiveDate, makerChecker, ledgerView = 'list', canLock, canManageSetup, onChanged }) {
  // The parent keys this card by lockDate + goLiveDate, so a saved change
  // remounts it with the new value -- no effect needed to sync the inputs.
  const [date, setDate] = useState(lockDate ?? '')
  const [goLive, setGoLive] = useState(goLiveDate ?? '')

  const today = new Date().toLocaleDateString('en-CA')

  const lockMutation = useMutation({
    mutationFn: updateLockDate,
    onSuccess: (d) => { onChanged?.(); showSuccess(d.lock_date ? `Period locked up to ${d.lock_date}.` : 'Period lock removed.') },
    onError: (e) => showError(firstError(e, 'Could not change the lock date.')),
  })

  const goLiveMutation = useMutation({
    mutationFn: updateGoLiveDate,
    onSuccess: (d) => { onChanged?.(); showSuccess(d.go_live_date ? `Finance go-live date set to ${d.go_live_date}.` : 'Finance go-live date cleared.') },
    onError: (e) => showError(firstError(e, 'Could not change the go-live date.')),
  })

  const saveGoLive = async () => {
    const next = goLive || null
    if (next === (goLiveDate ?? null)) return
    const ok = await confirmAction({
      title: next ? 'Set the Finance go-live date?' : 'Clear the go-live date?',
      message: next
        ? `Financial statements (Profit &amp; Loss) will start from <strong>${next}</strong>. Post the opening balances on this date.`
        : 'Financial statements will no longer be limited to a start date.',
      confirmText: 'Yes, Apply',
      confirmColor: '#4f46e5',
    })
    if (ok) goLiveMutation.mutate(next)
  }

  const makerMutation = useMutation({
    mutationFn: updateMakerChecker,
    onSuccess: (d) => { onChanged?.(); showSuccess(d.maker_checker ? 'Maker-checker switched on.' : 'Maker-checker switched off.') },
    onError: (e) => showError(firstError(e, 'Could not change maker-checker.')),
  })

  const viewMutation = useMutation({
    mutationFn: updateLedgerView,
    onSuccess: (d) => { onChanged?.(); showSuccess(d.ledger_view === 't_account' ? 'Accounts now open as T-accounts by default.' : 'Accounts now open as a list by default.') },
    onError: (e) => showError(firstError(e, 'Could not change the default ledger view.')),
  })

  const saveLock = async () => {
    const next = date || null
    if (next === (lockDate ?? null)) return
    if (next && next > today) { showError('The lock date cannot be in the future.'); return }
    const reopening = lockDate && (!next || next < lockDate)
    const ok = await confirmAction({
      title: reopening ? 'Reopen a closed period?' : 'Lock the period?',
      message: reopening
        ? `Postings dated after <strong>${next ?? 'any date'}</strong> will be allowed again. This change is recorded in the audit trail.`
        : `Nothing dated on or before <strong>${next}</strong> can be posted, voided or back-dated — in Finance or Inventory.`,
      confirmText: reopening ? 'Yes, Reopen' : 'Yes, Lock',
      confirmColor: reopening ? '#d97706' : '#16a34a',
    })
    if (ok) lockMutation.mutate(next)
  }

  return (
    <div className="rounded-lg border border-slate-200 bg-white">
      <div className="flex items-center gap-1.5 border-b border-slate-200 bg-slate-50 px-3 py-2">
        <Lock size={14} className="text-slate-500" strokeWidth={2} />
        <h2 className="text-sm font-bold text-slate-800">Period Lock &amp; Controls</h2>
      </div>

      <div className="space-y-2 p-2">
        <div>
          <label className="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Locked up to</label>
          <div className="flex items-center gap-1.5">
            <input
              type="date"
              className={FILTER_INPUT_CLS}
              value={date}
              max={today}
              onChange={(e) => setDate(e.target.value)}
              disabled={!canLock || lockMutation.isPending}
            />
            {canLock && (
              <button
                type="button"
                onClick={saveLock}
                disabled={lockMutation.isPending || (date || null) === (lockDate ?? null)}
                className="shrink-0 rounded-md bg-slate-900 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-slate-800 disabled:opacity-40"
              >
                Apply
              </button>
            )}
          </div>
          <p className="mt-1 text-[10px] leading-snug text-slate-500">
            {lockDate ? <>Closed up to <strong className="text-amber-700">{lockDate}</strong>. </> : 'No period is locked. '}
            Lock each month once it is reported. Clear the date to remove the lock.
          </p>
        </div>

        <div>
          <label className="mb-1 flex items-center gap-1 text-[11px] font-bold uppercase tracking-wider text-slate-500">
            <Flag size={11} /> Finance go-live date
          </label>
          <div className="flex items-center gap-1.5">
            <input
              type="date"
              className={FILTER_INPUT_CLS}
              value={goLive}
              onChange={(e) => setGoLive(e.target.value)}
              disabled={!canManageSetup || goLiveMutation.isPending}
            />
            {canManageSetup && (
              <button
                type="button"
                onClick={saveGoLive}
                disabled={goLiveMutation.isPending || (goLive || null) === (goLiveDate ?? null)}
                className="shrink-0 rounded-md bg-slate-900 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-slate-800 disabled:opacity-40"
              >
                Apply
              </button>
            )}
          </div>
          <p className="mt-1 text-[10px] leading-snug text-slate-500">
            {goLiveDate ? <>Ledger complete from <strong className="text-indigo-700">{goLiveDate}</strong>. </> : 'Not set. '}
            Documents before this date never reached the ledger, so the Profit &amp; Loss starts here.
            {!canManageSetup && ' Changed by your IT support (super admin).'}
          </p>
        </div>

        <label className="flex items-start gap-2 rounded-lg border border-slate-200 px-2.5 py-2">
          <input
            type="checkbox"
            className="mt-0.5 h-3.5 w-3.5 rounded border-slate-300 text-indigo-600"
            checked={Boolean(makerChecker)}
            disabled={!canManageSetup || makerMutation.isPending}
            onChange={(e) => makerMutation.mutate(e.target.checked)}
          />
          <span>
            <span className="flex items-center gap-1 text-xs font-bold text-slate-800"><ShieldCheck size={12} /> Maker-checker</span>
            <span className="mt-0.5 block text-[10px] leading-snug text-slate-500">
              The person who enters a bill cannot also confirm it. Needs at least two Finance users.
              {!canManageSetup && ' Changed by your IT support (super admin).'}
            </span>
          </span>
        </label>

        <div className="rounded-lg border border-slate-200 px-2.5 py-2">
          <span className="flex items-center gap-1 text-xs font-bold text-slate-800"><BookOpen size={12} /> Default ledger view</span>
          <div className="mt-1 inline-flex rounded-md border-2 border-slate-200 bg-slate-50 p-0.5 text-[11px] font-semibold">
            {[
              ['list', 'List', List],
              ['t_account', 'T-Account', Columns2],
            ].map(([v, label, Icon]) => (
              <button
                key={v}
                type="button"
                disabled={!canManageSetup || viewMutation.isPending}
                onClick={() => v !== ledgerView && viewMutation.mutate(v)}
                className={`inline-flex items-center gap-1 rounded px-2 py-0.5 transition-colors disabled:cursor-not-allowed ${
                  ledgerView === v ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-white disabled:hover:bg-transparent'
                }`}
              >
                <Icon size={12} /> {label}
              </button>
            ))}
          </div>
          <span className="mt-1 block text-[10px] leading-snug text-slate-500">
            How an account opens when clicked on the Trial Balance. Each user can still switch, and their own choice is remembered.
            {!canManageSetup && ' Changed by your IT support (super admin).'}
          </span>
        </div>
      </div>
    </div>
  )
}
