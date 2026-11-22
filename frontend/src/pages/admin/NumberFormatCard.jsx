import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Hash, Loader2, Lock, Save } from 'lucide-react'
import { getAmountPrecision, updateAmountPrecision } from '../../api/system'
import { SELECT_CLS, LABEL_CLS } from '../../utils/fieldStyles'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'

const sample = (d) => (1234.5678).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d })

/**
 * System Settings > Number Format (super admin): how many decimals money
 * AMOUNTS carry everywhere (totals, tax, receipts, payments, journals).
 * Unit prices, costs and quantities keep their own precision.
 *
 * Chosen at setup; it locks as soon as any document exists, because old
 * documents would otherwise disagree with new ones. Existing records are never
 * rewritten.
 */
export default function NumberFormatCard() {
  const { data, isLoading } = useQuery({ queryKey: ['amount-precision'], queryFn: getAmountPrecision })

  return <NumberFormatForm key={data?.decimals ?? 'loading'} status={data} isLoading={isLoading} />
}

function NumberFormatForm({ status, isLoading }) {
  const queryClient = useQueryClient()
  const [decimals, setDecimals] = useState(status?.decimals ?? 2)

  const mutation = useMutation({
    mutationFn: updateAmountPrecision,
    onSuccess: (d) => {
      queryClient.invalidateQueries({ queryKey: ['amount-precision'] })
      showSuccess(`Amounts now use ${d.decimals} decimal place${d.decimals === 1 ? '' : 's'}. Users see it after signing in again.`)
    },
    onError: (err) => {
      showError(err.response?.data?.errors?.decimals?.[0] ?? err.response?.data?.message ?? 'Could not save the number format.')
    },
  })

  const locked  = Boolean(status?.locked)
  const options = status ? Array.from({ length: status.max - status.min + 1 }, (_, i) => status.min + i) : [2]
  const changed = status && decimals !== status.decimals

  const save = async (e) => {
    e.preventDefault()
    const ok = await confirmAction({
      title: 'Change amount decimals?',
      message: `Every amount in the system will round to ${decimals} decimal place${decimals === 1 ? '' : 's'}. `
        + 'This locks as soon as the first document is saved and cannot be changed after that.',
      confirmText: 'Yes, Change',
    })
    if (ok) mutation.mutate(decimals)
  }

  return (
    <form onSubmit={save} className="mt-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
      <div className="flex items-center gap-2">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
          <Hash size={16} />
        </span>
        <div>
          <h2 className="text-sm font-semibold text-slate-800">Number Format</h2>
          <p className="text-xs text-slate-500">
            Decimal places for every money amount: totals, tax, receipts, payments and journals.
            Unit prices, costs and quantities keep their own precision.
          </p>
        </div>
      </div>

      {isLoading ? (
        <div className="mt-3 flex items-center gap-2 text-sm text-slate-400">
          <Loader2 size={14} className="animate-spin" /> Loading…
        </div>
      ) : (
        <div className="mt-3 flex flex-wrap items-end gap-3">
          <div className="w-40">
            <label className={LABEL_CLS} htmlFor="nf-decimals">Amount Decimals</label>
            <select
              id="nf-decimals"
              value={decimals}
              disabled={locked || mutation.isPending}
              onChange={(e) => setDecimals(Number(e.target.value))}
              className={`${SELECT_CLS} disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500`}
            >
              {options.map((d) => <option key={d} value={d}>{d}</option>)}
            </select>
          </div>
          <p className="pb-1.5 text-xs text-slate-500">
            Example: <b className="font-mono text-slate-700">{sample(decimals)}</b>
          </p>

          {locked ? (
            <p className="flex items-start gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] text-amber-800">
              <Lock size={12} className="mt-px shrink-0" />
              <span>Locked. {status.lock_reason} Precision cannot change once documents exist.</span>
            </p>
          ) : (
            <button
              type="submit"
              disabled={!changed || mutation.isPending}
              className="ml-auto flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
            >
              {mutation.isPending ? <Loader2 size={13} className="animate-spin" /> : <Save size={13} />}
              Save Number Format
            </button>
          )}
        </div>
      )}
    </form>
  )
}
