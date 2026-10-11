import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, BookOpen, Check, Plus, Save, Trash2 } from 'lucide-react'
import {
  createJournalEntry, getJournalOptions, getNextJournalNo,
} from '../../api/journalEntries'
import { getAllLedgerAccounts } from '../../api/ledgerAccounts'
import { getAllLocations } from '../../api/locations'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import {
  INPUT_CLS, INPUT_ERR_CLS, LABEL_CLS, SELECT_CLS, TEXTAREA_CLS,
} from '../../utils/fieldStyles'

const CRUMBS = [{ label: 'Finance' }, { label: 'Journal Voucher' }]

const today = () => new Date().toISOString().slice(0, 10)

const emptyRow = () => ({ ledger_account_id: '', description: '', debit: '', credit: '' })

/**
 * The accountant's escape hatch: depreciation, accruals, prepayments,
 * corrections, year-end. Everything no document produces.
 *
 * This is the one screen where the user picks EVERY account. Automated
 * postings resolve their accounts from the mapping, because there is one
 * correct answer and nobody should be guessing at it. Here there is no
 * document to derive anything from, so the choice is the whole point.
 *
 * It opens with exactly two rows, so the default state reads as a simple
 * From -> To transfer. Extra rows appear only when they are needed, which
 * keeps one form serving both a bank transfer and a multi-line accrual.
 */
export default function JournalVoucherFormPage() {
  const navigate = useNavigate()
  const qc       = useQueryClient()
  const { can }  = usePermissions()

  const [form, setForm] = useState({
    journal_date:  today(),
    document_date: '',
    journal_type:  'general',
    location_id:   '',
    narration:     '',
  })

  // Two rows from the start: a journal needs at least two lines, so an empty
  // grid would be a dead end rather than a starting point.
  const [lines, setLines]   = useState([emptyRow(), emptyRow()])
  const [errors, setErrors] = useState({})

  const { data: options = {} } = useQuery({
    queryKey: ['journal-options'],
    queryFn:  getJournalOptions,
    staleTime: 5 * 60 * 1000,
  })

  /*
   * Includes the per-party accounts, unlike every other picker.
   *
   * This form is the accountant's escape hatch, and the jobs that bring
   * someone here are exactly the ones that need a party account by name:
   * moving a balance out of the shared control account after switching to
   * per-party, or entering opening balances customer by customer. Excluding
   * them would make those impossible to express.
   *
   * They are grouped separately below so a long list stays readable, and
   * FilterSearchSelect searches the group name as well as the label.
   */
  const { data: accounts = [] } = useQuery({
    queryKey: ['ledger-accounts', 'all', 'with-parties'],
    queryFn:  () => getAllLedgerAccounts({ include_party_accounts: 1 }),
    staleTime: 5 * 60 * 1000,
  })

  const { data: locations = [] } = useQuery({
    queryKey: ['locations', 'all'],
    queryFn:  getAllLocations,
    staleTime: 5 * 60 * 1000,
  })

  const { data: previewNo = '' } = useQuery({
    queryKey: ['journal-next-no', form.journal_date],
    queryFn:  () => getNextJournalNo(form.journal_date),
    enabled:  Boolean(form.journal_date),
  })

  /*
   * Account options, grouped by category so the pad reads as a tree.
   *
   * Party accounts get their own two groups rather than being mixed into
   * Current Assets and Current Liabilities with everything else -- there can
   * be hundreds of them, and someone picking an account by hand is almost
   * always after an ordinary one.
   */
  const accountOptions = useMemo(
    () => {
      const ordinary = []
      const parties  = []

      for (const a of accounts) {
        const option = {
          value: String(a.id),
          label: `${a.code} · ${a.ledger_account_name}`,
          group: a.party_type
            ? (a.party_type === 'supplier' ? 'Suppliers' : 'Customers')
            : (a.account_category_name ?? undefined),
        }

        ;(a.party_type ? parties : ordinary).push(option)
      }

      // Ordinary accounts first: FilterSearchSelect preserves first-seen group
      // order, so the party groups settle at the bottom of the list.
      return [...ordinary, ...parties]
    },
    [accounts],
  )

  /* ── Totals. Recomputed on every keystroke: the difference is the one
        number that decides whether this voucher may be posted at all. ── */
  const totals = useMemo(() => {
    let debit = 0
    let credit = 0
    let filled = 0

    for (const l of lines) {
      const d = Number(l.debit)  || 0
      const c = Number(l.credit) || 0
      if (d > 0 || c > 0) filled += 1
      debit  += d
      credit += c
    }

    const round2 = (n) => Math.round(n * 100) / 100

    return {
      debit:      round2(debit),
      credit:     round2(credit),
      difference: round2(debit - credit),
      filled,
    }
  }, [lines])

  const isBalanced = Math.abs(totals.difference) < 0.005 && totals.debit > 0
  const canPost    = isBalanced && totals.filled >= 2

  /* ── Line editing ───────────────────────────────────────────────────── */
  const setLine = (i, patch) =>
    setLines((prev) => prev.map((row, idx) => (idx === i ? { ...row, ...patch } : row)))

  // A line carries a debit OR a credit, never both — so typing in one column
  // clears the other rather than letting an impossible row be built.
  const setAmount = (i, field, value) =>
    setLine(i, { [field]: value, [field === 'debit' ? 'credit' : 'debit']: '' })

  const addRows = useCallback((n = 1) => {
    setLines((prev) => [...prev, ...Array.from({ length: n }, emptyRow)])
  }, [])

  const removeRow = (i) =>
    setLines((prev) => (prev.length <= 2 ? prev : prev.filter((_, idx) => idx !== i)))

  useEffect(() => {
    const onKey = (e) => {
      if (e.altKey && (e.key === 'n' || e.key === 'N')) {
        e.preventDefault()
        addRows(1)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [addRows])

  /* ── Submit ─────────────────────────────────────────────────────────── */
  const mutation = useMutation({
    mutationFn: createJournalEntry,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['journal-entries'] })
      qc.invalidateQueries({ queryKey: ['trial-balance'] })
      showSuccess(`${res?.data?.journal_no ?? 'Journal'} posted.`)
      navigate('/finance/journal-entries')
    },
    onError: (err) => {
      // The server repeats every rule the form checks, so a 422 here means
      // either a race (an account deactivated meanwhile) or a rule the form
      // cannot see. Map it back onto the fields either way.
      const bag = err?.response?.data?.errors ?? {}
      setErrors(bag)
      showError(
        bag.lines?.[0]
        ?? err?.response?.data?.message
        ?? 'The journal could not be posted.',
      )
    },
  })

  const submit = (e) => {
    e.preventDefault()
    setErrors({})

    const payload = {
      journal_date:  form.journal_date,
      document_date: form.document_date || null,
      journal_type:  form.journal_type,
      location_id:   form.location_id || null,
      narration:     form.narration || null,
      lines: lines
        .filter((l) => Number(l.debit) > 0 || Number(l.credit) > 0)
        .map((l) => ({
          ledger_account_id: Number(l.ledger_account_id),
          debit:             Number(l.debit)  || 0,
          credit:            Number(l.credit) || 0,
          description:       l.description || null,
        })),
    }

    const missingAccount = payload.lines.some((l) => !l.ledger_account_id)

    if (missingAccount) {
      showError('Every line with an amount needs a ledger account.')
      return
    }

    mutation.mutate(payload)
  }

  if (!can('create_journal_entries')) {
    return (
      <div className="p-3">
        <Breadcrumb crumbs={CRUMBS} />
        <p className="mt-3 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500">
          You do not have permission to create journal vouchers.
        </p>
      </div>
    )
  }

  const money = (n) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

  return (
    <div className="p-3">
      <Breadcrumb crumbs={CRUMBS} />

      <form onSubmit={submit} className="mt-2 space-y-2">
        {/* ── Header ──────────────────────────────────────────────────── */}
        <div className="rounded-lg border border-slate-200 bg-white p-3">
          <div className="mb-2 flex items-center gap-2">
            <BookOpen size={15} className="text-indigo-500" />
            <h2 className="text-sm font-bold text-slate-800">Journal Voucher</h2>
            {previewNo && (
              <span className="ml-auto rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-500">
                {previewNo}
              </span>
            )}
          </div>

          <div className="grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-4">
            <div>
              <label className={LABEL_CLS}>Journal Date *</label>
              <input
                type="date"
                value={form.journal_date}
                onChange={(e) => setForm({ ...form, journal_date: e.target.value })}
                className={errors.journal_date ? INPUT_ERR_CLS : INPUT_CLS}
                required
              />
              <p className="mt-0.5 text-[10px] text-slate-400">Which period this hits</p>
            </div>

            <div>
              <label className={LABEL_CLS}>Document Date</label>
              <input
                type="date"
                value={form.document_date}
                onChange={(e) => setForm({ ...form, document_date: e.target.value })}
                className={INPUT_CLS}
              />
              <p className="mt-0.5 text-[10px] text-slate-400">When it was written, if different</p>
            </div>

            <div>
              <label className={LABEL_CLS}>Type *</label>
              <select
                value={form.journal_type}
                onChange={(e) => setForm({ ...form, journal_type: e.target.value })}
                className={SELECT_CLS}
              >
                {(options.journal_types ?? []).map((t) => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </select>
            </div>

            <div>
              <label className={LABEL_CLS}>Location</label>
              <FilterSearchSelect
                size="form"
                placeholder="None"
                value={form.location_id}
                onChange={(v) => setForm({ ...form, location_id: v })}
                options={locations.map((l) => ({ value: String(l.id), label: l.location_name ?? l.name }))}
              />
            </div>
          </div>

          <div className="mt-2">
            <label className={LABEL_CLS}>Narration</label>
            <textarea
              rows={2}
              value={form.narration}
              onChange={(e) => setForm({ ...form, narration: e.target.value })}
              placeholder="Why this entry exists — e.g. Monthly depreciation, October"
              className={TEXTAREA_CLS}
            />
          </div>
        </div>

        {/* ── Lines ───────────────────────────────────────────────────── */}
        <div className="rounded-lg border border-slate-200 bg-white">
          <div className="flex items-center gap-2 border-b border-slate-100 px-3 py-2">
            <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500">Lines</h3>
            <button
              type="button"
              onClick={() => addRows(1)}
              title="Add a line (Alt+N)"
              className="ml-auto inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-600 transition-colors hover:bg-indigo-100"
            >
              <Plus size={12} /> Add Row
              <span className="ml-0.5 rounded bg-indigo-100 px-1 py-px font-mono text-[9px] text-indigo-500">Alt+N</span>
            </button>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
                <tr>
                  <th className="w-8 px-2 py-1.5 text-left">#</th>
                  <th className="px-2 py-1.5 text-left">Ledger Account *</th>
                  <th className="px-2 py-1.5 text-left">Description</th>
                  <th className="w-36 px-2 py-1.5 text-right">Debit</th>
                  <th className="w-36 px-2 py-1.5 text-right">Credit</th>
                  <th className="w-10 px-2 py-1.5" />
                </tr>
              </thead>
              <tbody>
                {lines.map((line, i) => {
                  const hasAmount = Number(line.debit) > 0 || Number(line.credit) > 0
                  const needsAcct = hasAmount && !line.ledger_account_id

                  return (
                    <tr key={i} className="border-t border-slate-100">
                      <td className="px-2 py-1 text-xs text-slate-400">{i + 1}</td>

                      <td className="px-2 py-1 min-w-64">
                        <FilterSearchSelect
                          size="form"
                          wide
                          clearable={false}
                          invalid={needsAcct}
                          placeholder="Select account…"
                          value={line.ledger_account_id}
                          onChange={(v) => setLine(i, { ledger_account_id: v })}
                          options={accountOptions}
                        />
                      </td>

                      <td className="px-2 py-1">
                        <input
                          type="text"
                          value={line.description}
                          onChange={(e) => setLine(i, { description: e.target.value })}
                          className={INPUT_CLS}
                        />
                      </td>

                      <td className="px-2 py-1">
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          value={line.debit}
                          onChange={(e) => setAmount(i, 'debit', e.target.value)}
                          className={`${INPUT_CLS} text-right`}
                        />
                      </td>

                      <td className="px-2 py-1">
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          value={line.credit}
                          onChange={(e) => setAmount(i, 'credit', e.target.value)}
                          className={`${INPUT_CLS} text-right`}
                        />
                      </td>

                      <td className="px-2 py-1 text-center">
                        <button
                          type="button"
                          onClick={() => removeRow(i)}
                          disabled={lines.length <= 2}
                          title={lines.length <= 2 ? 'A journal needs at least two lines' : 'Remove line'}
                          className="rounded p-1 text-red-500 transition-colors hover:bg-red-50 disabled:cursor-not-allowed disabled:text-slate-200 disabled:hover:bg-transparent"
                        >
                          <Trash2 size={14} />
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>

              {/* ── Running totals. The Difference is the gate: it stays red
                    until the two sides agree, and Post is disabled until
                    then. Enforced again on the server, because the form is a
                    convenience and the boundary is the rule. ── */}
              <tfoot className="sticky bottom-0 bg-slate-50 text-sm font-semibold">
                <tr className="border-t-2 border-slate-200">
                  <td colSpan={3} className="px-2 py-1.5 text-right text-xs uppercase tracking-wider text-slate-500">
                    Total
                  </td>
                  <td className="px-2 py-1.5 text-right tabular-nums text-slate-800">{money(totals.debit)}</td>
                  <td className="px-2 py-1.5 text-right tabular-nums text-slate-800">{money(totals.credit)}</td>
                  <td />
                </tr>
                <tr className="border-t border-slate-200">
                  <td colSpan={3} className="px-2 py-1.5 text-right text-xs uppercase tracking-wider text-slate-500">
                    Difference
                  </td>
                  <td
                    colSpan={2}
                    className={`px-2 py-1.5 text-right tabular-nums ${
                      isBalanced ? 'text-green-600' : 'text-red-600'
                    }`}
                  >
                    {money(totals.difference)}
                    {isBalanced
                      ? <Check size={13} className="ml-1 inline" />
                      : <AlertTriangle size={13} className="ml-1 inline" />}
                  </td>
                  <td />
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        {/* ── Actions ─────────────────────────────────────────────────── */}
        <div className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
          {!canPost && (
            <p className="flex items-center gap-1.5 text-xs text-amber-600">
              <AlertTriangle size={13} />
              {totals.filled < 2
                ? 'Fill at least two lines — one debit and one credit.'
                : 'Debits and credits must be equal before this can be posted.'}
            </p>
          )}

          <div className="ml-auto flex gap-2">
            <button
              type="button"
              onClick={() => navigate('/finance/journal-entries')}
              className="rounded-lg border-2 border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={!canPost || mutation.isPending}
              className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
              <Save size={14} />
              {mutation.isPending ? 'Posting…' : 'Post Journal'}
            </button>
          </div>
        </div>
      </form>
    </div>
  )
}
