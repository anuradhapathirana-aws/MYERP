import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Lock, ShieldCheck } from 'lucide-react'
import { updateLockDate, updateMakerChecker } from '../../api/financeSettings'
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
export default function FinanceControlsCard({ lockDate, makerChecker, canManage, onChanged }) {
  // The parent keys this card by lockDate, so a saved change remounts it
  // with the new value -- no effect needed to sync the input.
  const [date, setDate] = useState(lockDate ?? '')

  const today = new Date().toLocaleDateString('en-CA')

  const lockMutation = useMutation({
    mutationFn: updateLockDate,
    onSuccess: (d) => { onChanged?.(); showSuccess(d.lock_date ? `Period locked up to ${d.lock_date}.` : 'Period lock removed.') },
    onError: (e) => showError(firstError(e, 'Could not change the lock date.')),
  })

  const makerMutation = useMutation({
    mutationFn: updateMakerChecker,
    onSuccess: (d) => { onChanged?.(); showSuccess(d.maker_checker ? 'Maker-checker switched on.' : 'Maker-checker switched off.') },
    onError: (e) => showError(firstError(e, 'Could not change maker-checker.')),
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
              disabled={!canManage || lockMutation.isPending}
            />
            {canManage && (
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

        <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 px-2.5 py-2">
          <input
            type="checkbox"
            className="mt-0.5 h-3.5 w-3.5 rounded border-slate-300 text-indigo-600"
            checked={Boolean(makerChecker)}
            disabled={!canManage || makerMutation.isPending}
            onChange={(e) => makerMutation.mutate(e.target.checked)}
          />
          <span>
            <span className="flex items-center gap-1 text-xs font-bold text-slate-800"><ShieldCheck size={12} /> Maker-checker</span>
            <span className="mt-0.5 block text-[10px] leading-snug text-slate-500">
              The person who enters a bill cannot also confirm it. Needs at least two Finance users.
            </span>
          </span>
        </label>
      </div>
    </div>
  )
}
