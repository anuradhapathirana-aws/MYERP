import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, ArrowDownCircle, ArrowUpCircle, CheckCircle, Download, Edit2, History, Landmark, Paperclip, Plus, Printer,
  RefreshCw, RotateCcw, Search, Trash2, Undo2, Upload, X,
} from 'lucide-react'
import {
  createBankReconciliation, deleteBankReconciliation, downloadBankReconciliationPdf, getBankReconciliation,
  getBankReconciliationHistory, getBankReconciliationOptions, getNextBankReconciliationNo,
  getReconciliationWorkspace, postBankEntry, postBankReconciliation, searchReconciliationEntries,
  updateBankReconciliation, voidBankReconciliation,
} from '../../api/bankReconciliations'
import { deleteAttachment, downloadAttachment, getAttachments, uploadAttachment } from '../../api/financeAttachments'
import Breadcrumb from '../../components/Breadcrumb'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { usePermissions } from '../../hooks/usePermissions'
import { confirmAction, confirmDelete, confirmWithReason, escapeHtml, showError, showSuccess, showWarning } from '../../utils/alerts'
import { INPUT_DISABLED_CLS, LABEL_CLS, inputCls, selectCls, textareaCls } from '../../utils/fieldStyles'
import { printPdfBlob } from '../../utils/pdf'

const today = () => new Date().toLocaleDateString('en-CA')

/** randomUUID needs a secure context; the VPS may serve over http. */
const newRequestId = () => {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()
  const b = window.crypto.getRandomValues(new Uint8Array(16))
  b[6] = (b[6] & 0x0f) | 0x40
  b[8] = (b[8] & 0x3f) | 0x80
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

// Money is worked in integer cents, never floats. A typed amount is digits with an
// optional minus and at most 2 decimals (thousands commas allowed); anything else is
// refused on blur and before saving -- never quietly turned into 0.00.
const MONEY_RE = /^-?\d+(\.\d{1,2})?$/
const cleanMoney = (v) => String(v ?? '').replace(/,/g, '').trim()
const isMoney = (v) => MONEY_RE.test(cleanMoney(v))
const toCents = (v) => (isMoney(v) ? Math.round(Number(cleanMoney(v)) * 100) : 0)
const moneyError = (v, label) => (cleanMoney(v) === '' ? null : isMoney(v) ? null : `${label}: enter a number with at most 2 decimals, e.g. 125000.50 or -3500.`)
const fmt = (c) => (c / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const firstError = (err, fallback) => {
  const errors = err?.response?.data?.errors
  return (errors && Object.values(errors)[0]?.[0]) ?? err?.response?.data?.message ?? fallback
}

/** "BR-… posted." plus what the returned cheques did. A bounce entry's own bank line carries its journal no as return_journal_no. */
const postedMessage = (r, fallbackNo) => {
  const bounced = (r?.lines ?? []).filter((l) => l.return_journal_no && l.return_journal_no !== l.journal_no).length
  return `${r?.recon_no ?? fallbackNo} posted.`
    + (bounced ? ` ${bounced} returned cheque(s) got a bounce entry; what they paid is owed again.` : '')
}

const EVENT_LABELS = {
  created: 'Created', updated: 'Edited', posted: 'Posted', voided: 'Undone', deleted: 'Deleted',
  attachment_added: 'Statement attached', attachment_removed: 'Statement removed',
}

// Bank statement files: the shared Finance attachment rules (checked again on the server).
const MAX_FILE_BYTES = 5 * 1024 * 1024
const MAX_FILES = 10
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const fmtSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

const SEARCH_BY = [
  { value: 'cheque', label: 'Cheque / Ref No' },
  { value: 'amount', label: 'Amount' },
  { value: 'date', label: 'Date (YYYY-MM-DD)' },
  { value: 'description', label: 'Description / Journal No' },
  { value: 'returned', label: 'Bounced after clearing (cheque no / amount)' },
]

const STATUS_COLS = [
  { value: 'realized', label: 'Realize', accent: 'accent-green-600' },
  { value: 'returned', label: 'Return', accent: 'accent-red-600' },
  { value: 'not_presented', label: 'Not Presented', accent: 'accent-slate-500' },
]

function Req() {
  return <span className="text-red-500">*</span>
}

function FieldError({ msg }) {
  return msg ? <p className="mt-0.5 text-[10px] text-red-500">{msg}</p> : null
}

function Step({ n, label, state }) {
  const cls = state === 'done'
    ? 'border-green-600 bg-green-600 text-white'
    : state === 'active' ? 'border-indigo-600 text-indigo-700' : 'border-slate-300 text-slate-400'
  return (
    <div className="flex items-center gap-1.5">
      <span className={`flex h-5 w-5 items-center justify-center rounded-full border-2 text-[10px] font-bold ${cls}`}>{state === 'done' ? '✓' : n}</span>
      <span className={`text-xs font-semibold ${state === 'pending' ? 'text-slate-400' : 'text-slate-700'}`}>{label}</span>
    </div>
  )
}

function StatCard({ label, children, tone = 'slate', hint }) {
  const ring = tone === 'green' ? 'border-green-300 bg-green-50' : tone === 'red' ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white'
  return (
    <div className={`rounded-lg border px-3 py-2 shadow-sm ${ring}`}>
      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{label}</div>
      {children}
      {hint && <div className="mt-0.5 text-[10px] text-slate-500">{hint}</div>}
    </div>
  )
}

/**
 * One side of the statement: Deposits (money in) or Withdrawals (money out).
 * Each row is one bank line of the ledger with three choices; an unmarked row
 * is treated as Not Presented when the reconciliation is posted.
 */
function EntriesTable({
  title, subtitle, icon: Icon, rows, statuses, onStatus, onRealizeAll, readOnly, dateFrom, statementDate, extraIds, onRemoveExtra,
  returnDates, onReturnDate,
}) {
  const [term, setTerm] = useState('')
  const q = term.trim().toLowerCase()
  const shown = q === '' ? rows : rows.filter((r) => [r.journal_no, r.instrument_no, r.description, r.amount]
    .some((v) => String(v ?? '').toLowerCase().includes(q)))

  const realized = rows.filter((r) => statuses[r.journal_line_id] === 'realized')
  const realizedCents = realized.reduce((s, r) => s + toCents(r.amount), 0)
  const returned = rows.filter((r) => statuses[r.journal_line_id] === 'returned').length

  return (
    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-wrap items-center gap-2 border-b border-indigo-100 bg-indigo-50 px-3 py-1.5">
        <div className="flex items-center gap-1.5 text-xs font-bold text-indigo-700">
          <Icon size={13} /> {title} <span className="font-normal text-indigo-400">{subtitle}</span>
        </div>
        {!readOnly && rows.length > 0 && (
          <button type="button" onClick={() => onRealizeAll(shown)} className="rounded border border-green-300 bg-white px-2 py-0.5 text-[11px] font-bold text-green-700 hover:bg-green-50">
            Realize all{q ? ' shown' : ''}
          </button>
        )}
        {rows.length > 0 && (
          <div className="relative ml-auto">
            <Search size={11} className="pointer-events-none absolute left-1.5 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              className="h-6 w-48 rounded border border-slate-300 bg-white pl-5 pr-1.5 text-[11px] focus:border-indigo-400 focus:outline-none"
              value={term}
              placeholder="Cheque, journal, text, amount…"
              onChange={(e) => setTerm(e.target.value)}
            />
          </div>
        )}
      </div>

      <div className="max-h-[38vh] overflow-auto">
        <table className="w-full text-xs">
          <thead className="sticky top-0 z-10 bg-slate-50">
            <tr className="border-b border-slate-200 text-left">
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Journal No</th>
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Date</th>
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Cheque / Ref</th>
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Description</th>
              <th className="px-2 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
              {STATUS_COLS.map((c) => (
                <th key={c.value} className="w-20 px-2 py-1.5 text-center font-semibold uppercase tracking-wider text-slate-500">{c.label}</th>
              ))}
              {!readOnly && <th className="w-6" />}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {shown.length === 0 && (
              <tr>
                <td colSpan={9} className="px-3 py-5 text-center text-slate-400">
                  {q ? 'No entries match the search.' : 'No uncleared entries.'}
                </td>
              </tr>
            )}
            {shown.map((r) => {
              const status = statuses[r.journal_line_id]
              const broughtForward = dateFrom && r.journal_date < dateFrom
              const late = statementDate && r.journal_date > statementDate
              const rowTone = status === 'realized' ? 'bg-green-50/60' : status === 'returned' ? 'bg-red-50/60' : ''
              return (
                <tr key={r.journal_line_id} className={`hover:bg-slate-50 ${rowTone}`}>
                  <td className="whitespace-nowrap px-2 py-1 font-mono text-slate-600">{r.journal_no}</td>
                  <td className="whitespace-nowrap px-2 py-1 text-slate-500">
                    {r.journal_date}
                    {broughtForward && <span title="Uncleared from an earlier period" className="ml-1 rounded bg-amber-100 px-1 text-[9px] font-bold text-amber-700">B/F</span>}
                    {late && <span title="Booked after the statement date" className="ml-1 rounded bg-sky-100 px-1 text-[9px] font-bold text-sky-700">LATE</span>}
                  </td>
                  <td className="whitespace-nowrap px-2 py-1 font-mono font-medium text-slate-700">
                    {r.instrument_no ?? <span className="text-slate-300">—</span>}
                    {r.instrument_date && r.instrument_type === 'cheque' && r.instrument_date !== r.journal_date && (
                      <span className="ml-1 text-[10px] font-normal text-slate-400">({r.instrument_date})</span>
                    )}
                  </td>
                  <td className="max-w-[260px] truncate px-2 py-1 text-slate-600" title={r.description ?? ''}>
                    {status === 'returned' && !readOnly && (
                      <input
                        type="date"
                        title="Date the bank returned it (the bounce entry is dated on it)"
                        className="mr-1 h-5 w-[108px] rounded border border-red-300 bg-red-50 px-1 text-[10px] text-red-700 focus:border-red-500 focus:outline-none"
                        value={returnDates[r.journal_line_id] ?? statementDate ?? ''}
                        min={r.journal_date}
                        max={statementDate || undefined}
                        onChange={(e) => onReturnDate(r.journal_line_id, e.target.value)}
                      />
                    )}
                    {status === 'returned' && readOnly && r.cleared_date && (
                      <span title="Date the bank returned it" className="mr-1 rounded bg-red-100 px-1 text-[9px] font-bold text-red-700">RET {r.cleared_date}</span>
                    )}
                    {r.is_reversal && <span className="mr-1 rounded bg-slate-200 px-1 text-[9px] font-bold text-slate-600">REV</span>}
                    {r.return_journal_no && (r.return_journal_no === r.journal_no
                      ? <span title="Bounce entry of a returned cheque" className="mr-1 rounded bg-red-100 px-1 text-[9px] font-bold text-red-700">BOUNCE</span>
                      : <span title="Bounce entry posted for this cheque" className="mr-1 rounded bg-red-100 px-1 font-mono text-[9px] font-bold text-red-700">→ {r.return_journal_no}</span>)}
                    {r.description}
                  </td>
                  <td className="whitespace-nowrap px-2 py-1 text-right font-semibold text-slate-800">{fmt(toCents(r.amount))}</td>
                  {STATUS_COLS.map((c) => {
                    const blocked = c.value === 'returned' && r.return_blocked
                    return (
                      <td key={c.value} className="px-2 py-1 text-center">
                        <input
                          type="radio"
                          name={`st-${r.journal_line_id}`}
                          className={`h-3.5 w-3.5 cursor-pointer disabled:cursor-not-allowed disabled:opacity-30 ${c.accent}`}
                          checked={status === c.value}
                          disabled={readOnly || Boolean(blocked)}
                          title={blocked ? r.return_blocked : c.label}
                          onChange={() => onStatus(r.journal_line_id, c.value)}
                        />
                      </td>
                    )
                  })}
                  {!readOnly && (
                    <td className="px-1 py-1 text-center">
                      {extraIds.includes(r.journal_line_id) && (
                        <button type="button" title="Remove from this list" onClick={() => onRemoveExtra(r.journal_line_id)} className="rounded p-0.5 text-red-500 hover:bg-red-50">
                          <X size={12} />
                        </button>
                      )}
                    </td>
                  )}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center justify-end gap-x-4 border-t border-slate-200 bg-slate-50 px-3 py-1.5 text-[11px] text-slate-600">
        <span>Showing {shown.length} of {rows.length}</span>
        <span>Realized: <strong className="text-slate-800">{realized.length} of {rows.length}</strong></span>
        {returned > 0 && <span className="text-red-600">Returned: <strong>{returned}</strong></span>}
        <span>Realized total: <strong className="text-slate-800">{fmt(realizedCents)}</strong></span>
      </div>
    </div>
  )
}

/**
 * Cheques an EARLIER reconciliation realized that the bank returned on this
 * statement. The money going back moves this statement's balance: a deposited
 * cheque coming back is money out, an issued one coming back is money in.
 */
function LateReturnsTable({ rows, returnDates, onReturnDate, onRemove, readOnly, statementDate, lastStatementDate }) {
  if (rows.length === 0) return null
  return (
    <div className="overflow-hidden rounded-lg border border-red-200 bg-white shadow-sm">
      <div className="flex items-center gap-1.5 border-b border-red-100 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700">
        <RotateCcw size={13} /> Cheques Returned after Clearing
        <span className="font-normal text-red-400">realized on an earlier statement, debited / credited back on this one</span>
      </div>
      <div className="max-h-[30vh] overflow-auto">
        <table className="w-full text-xs">
          <thead className="sticky top-0 z-10 bg-slate-50">
            <tr className="border-b border-slate-200 text-left">
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Journal No</th>
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Booked</th>
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Cheque</th>
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Description</th>
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Cleared In</th>
              <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Returned On</th>
              <th className="px-2 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Effect</th>
              {!readOnly && <th className="w-6" />}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((r) => {
              const out = r.direction === 'deposit' // a deposited cheque coming back takes money out
              return (
                <tr key={r.journal_line_id} className="bg-red-50/40 hover:bg-red-50">
                  <td className="whitespace-nowrap px-2 py-1 font-mono text-slate-600">{r.journal_no}</td>
                  <td className="whitespace-nowrap px-2 py-1 text-slate-500">{r.journal_date}</td>
                  <td className="whitespace-nowrap px-2 py-1 font-mono font-medium text-slate-700">{r.instrument_no}</td>
                  <td className="max-w-[260px] truncate px-2 py-1 text-slate-600" title={r.description ?? ''}>{r.description}</td>
                  <td className="whitespace-nowrap px-2 py-1 text-slate-500">{r.cleared_recon_no ?? '—'}{r.cleared_statement_date ? ` (${r.cleared_statement_date})` : ''}</td>
                  <td className="whitespace-nowrap px-2 py-1">
                    {readOnly ? (r.cleared_date ?? '—') : (
                      <input
                        type="date"
                        className="h-6 w-[118px] rounded border border-red-300 bg-white px-1 text-[11px] focus:border-red-500 focus:outline-none"
                        value={returnDates[r.journal_line_id] ?? statementDate ?? ''}
                        min={lastStatementDate || r.journal_date}
                        max={statementDate || undefined}
                        onChange={(e) => onReturnDate(r.journal_line_id, e.target.value)}
                      />
                    )}
                  </td>
                  <td className={`whitespace-nowrap px-2 py-1 text-right font-semibold ${out ? 'text-red-600' : 'text-green-700'}`}>
                    {out ? '−' : '+'}{fmt(toCents(r.amount))}
                  </td>
                  {!readOnly && (
                    <td className="px-1 py-1 text-center">
                      <button type="button" title="Remove from this statement" onClick={() => onRemove(r.journal_line_id)} className="rounded p-0.5 text-red-500 hover:bg-red-100">
                        <X size={12} />
                      </button>
                    </td>
                  )}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <div className="border-t border-red-100 bg-red-50/50 px-3 py-1 text-[10px] text-red-700">
        Posting the reconciliation posts each bounce on its return date and makes what the cheque paid owed again.
      </div>
    </div>
  )
}

/**
 * Bank Reconciliation: match a bank account's ledger lines to its statement.
 *
 * 1. Choose the bank account and statement date, then Refresh: every uncleared
 *    bank line up to that date loads (earlier unpresented items marked B/F).
 * 2. Mark each one Realize (on the statement), Return (bounced) or Not
 *    Presented, and type the statement's closing balance.
 * 3. Post when the variance is 0.00. Missing bank-only items (charges,
 *    interest) are posted from Add Transaction and come in already realized.
 */
export default function BankReconciliationFormPage() {
  const { id } = useParams()
  const { pathname } = useLocation()
  const mode = !id ? 'create' : pathname.endsWith('/edit') ? 'edit' : 'view'
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { can } = usePermissions()

  const isCreate = mode === 'create'
  const isView = mode === 'view'

  const [form, setForm] = useState({
    company_id: '', bank_account_id: '', date_from: '', statement_date: today(),
    opening_balance: '', statement_balance: '', notes: '',
  })
  const [applied, setApplied] = useState(null) // { bank_account_id, statement_date, date_from } the lines were loaded for
  const [statuses, setStatuses] = useState({}) // journal_line_id -> status
  const [returnDates, setReturnDates] = useState({}) // journal_line_id -> date the bank returned it
  const [extraRows, setExtraRows] = useState([]) // added from search or a new bank entry
  const [errors, setErrors] = useState({})
  const [saving, setSaving] = useState(false)
  const [pdfBusy, setPdfBusy] = useState(false)
  const [searchBy, setSearchBy] = useState('cheque')
  const [searchTerm, setSearchTerm] = useState('')
  const [searchResults, setSearchResults] = useState(null)
  const [searching, setSearching] = useState(false)
  const [entryOpen, setEntryOpen] = useState(false)
  const [entry, setEntry] = useState({ type: 'bank_charges', entry_date: '', amount: '', description: '', reference: '' })
  const [entryBusy, setEntryBusy] = useState(false)
  const entryRequestIdRef = useRef(null) // one per opened entry: a double click posts once
  const [pendingFiles, setPendingFiles] = useState([]) // statement files picked before the first save
  const requestIdRef = useRef(newRequestId())
  const firstFieldRef = useRef(null)
  const fileInputRef = useRef(null)

  // ── Lookups ────────────────────────────────────────────────────────────────
  const { data: options = {} } = useQuery({
    queryKey: ['bank-reconciliation-options'],
    queryFn: getBankReconciliationOptions,
    staleTime: 5 * 60 * 1000,
  })

  const { data: recon, isLoading: loadingRecon, isError: reconError } = useQuery({
    queryKey: ['bank-reconciliation', id],
    queryFn: () => getBankReconciliation(id),
    enabled: !isCreate && Boolean(id),
  })

  const { data: workspace, isFetching: loadingWorkspace, error: workspaceError } = useQuery({
    queryKey: ['bank-reconciliation-workspace', applied?.bank_account_id, applied?.statement_date, applied?.date_from ?? '', id ?? null],
    queryFn: () => getReconciliationWorkspace(applied.bank_account_id, applied.statement_date, id, applied.date_from),
    enabled: !isView && Boolean(applied),
    refetchOnWindowFocus: false, // up to 3,000 lines; Refresh reloads on purpose
  })

  const { data: history = [] } = useQuery({
    queryKey: ['bank-reconciliation-history', id],
    queryFn: () => getBankReconciliationHistory(id),
    enabled: isView && Boolean(id),
  })

  const { data: attachments = [] } = useQuery({
    queryKey: ['bank-reconciliation-attachments', id],
    queryFn: () => getAttachments('bank_reconciliation', id),
    enabled: !isCreate && Boolean(id),
  })

  const { data: nextNo = '' } = useQuery({
    queryKey: ['bank-reconciliation-next-no', form.statement_date],
    queryFn: () => getNextBankReconciliationNo(form.statement_date),
    enabled: isCreate && Boolean(form.statement_date),
  })

  const companies = useMemo(() => options.companies ?? [], [options.companies])

  if (isCreate && !form.company_id && companies.length === 1) {
    setForm((f) => ({ ...f, company_id: String(companies[0].id) }))
  }

  // Load the reconciliation for edit / view, once per saved version.
  const [loadedVersion, setLoadedVersion] = useState(null)
  if (recon && loadedVersion !== recon.updated_at) {
    setLoadedVersion(recon.updated_at)
    setForm({
      company_id: String(recon.company_id ?? ''),
      bank_account_id: String(recon.bank_account_id ?? ''),
      date_from: recon.date_from ?? '',
      statement_date: recon.statement_date ?? '',
      opening_balance: recon.opening_balance ?? '',
      statement_balance: recon.statement_balance ?? '',
      notes: recon.notes ?? '',
    })
    setStatuses(Object.fromEntries((recon.lines ?? []).map((l) => [l.journal_line_id, l.status])))
    setReturnDates(Object.fromEntries((recon.lines ?? [])
      .filter((l) => (l.status === 'returned' || l.status === 'returned_later') && l.cleared_date)
      .map((l) => [l.journal_line_id, l.cleared_date])))
    setExtraRows([])
    if (!isView) setApplied({ bank_account_id: String(recon.bank_account_id), statement_date: recon.statement_date, date_from: recon.date_from ?? '' })
  }

  useEffect(() => {
    if (mode === 'edit' && recon && recon.status !== 'draft') navigate(`/finance/bank-reconciliations/${recon.id}`, { replace: true })
  }, [mode, recon, navigate])

  useEffect(() => { firstFieldRef.current?.focus() }, [])

  // The suggested From date: the day after the account was last reconciled.
  // Offered once per account, so a From the user clears stays cleared.
  const [suggestedFor, setSuggestedFor] = useState(null)
  if (isCreate && workspace && suggestedFor !== workspace.bank_account_id) {
    setSuggestedFor(workspace.bank_account_id)
    if (workspace.suggested_date_from && !form.date_from) {
      setForm((f) => ({ ...f, date_from: workspace.suggested_date_from }))
    }
  }

  // ── Accounts ───────────────────────────────────────────────────────────────
  const bankAccounts = (options.bank_accounts ?? []).filter((a) => String(a.company_id) === String(form.company_id))
  const selectedBank = (options.bank_accounts ?? []).find((a) => String(a.id) === String(form.bank_account_id))

  if (!isView && !form.bank_account_id && bankAccounts.length === 1) {
    setForm((f) => ({ ...f, bank_account_id: String(bankAccounts[0].id) }))
  }

  // "New Bank Entry" posts only these, each to its mapped account.
  const entryTypes = options.bank_entry_types ?? []
  const entryType = entryTypes.find((t) => t.value === entry.type)

  // ── Rows and totals ────────────────────────────────────────────────────────
  const allRows = useMemo(() => {
    if (isView) return recon?.lines ?? []
    const base = workspace?.lines ?? []
    const seen = new Set(base.map((r) => r.journal_line_id))
    return [...base, ...extraRows.filter((r) => !seen.has(r.journal_line_id))]
  }, [isView, recon, workspace, extraRows])

  // A cheque an earlier statement realized and this one returned has its own table.
  const rows = allRows.filter((r) => !r.cleared_before)
  const lateRows = allRows.filter((r) => r.cleared_before)

  const extraIds = extraRows.map((r) => r.journal_line_id)
  const deposits = rows.filter((r) => r.direction === 'deposit')
  const withdrawals = rows.filter((r) => r.direction === 'withdrawal')

  const isFirst = !isView && Boolean(workspace?.is_first)
  const canPost = can('post_bank_reconciliations')
  const openingCents = isView
    ? toCents(recon?.opening_balance)
    : workspace?.is_first ? toCents(form.opening_balance) : toCents(workspace?.opening_balance)
  const sumCents = (list) => list.reduce((s, r) => s + toCents(r.amount), 0)
  // A returned-after-clearing deposit is money out; a returned issued cheque is money in.
  const lateOut = sumCents(lateRows.filter((r) => r.direction === 'deposit'))
  const lateIn = sumCents(lateRows.filter((r) => r.direction === 'withdrawal'))
  const realizedIn = sumCents(deposits.filter((r) => statuses[r.journal_line_id] === 'realized')) + lateIn
  const realizedOut = sumCents(withdrawals.filter((r) => statuses[r.journal_line_id] === 'realized')) + lateOut
  const closingCents = openingCents + realizedIn - realizedOut
  const statementValid = isMoney(form.statement_balance)
  const statementCents = toCents(form.statement_balance)
  const varianceCents = statementCents - closingCents
  const balanced = varianceCents === 0 && statementValid && (!isFirst || isMoney(form.opening_balance))
  const rowIds = new Set(allRows.map((r) => String(r.journal_line_id)))
  const marked = Object.keys(statuses).filter((k) => rowIds.has(k)).length

  const loaded = isView ? Boolean(recon) : Boolean(workspace)
  const stepState = (n) => {
    if (n === 1) return loaded ? 'done' : 'active'
    if (n === 2) return !loaded ? 'pending' : balanced ? 'done' : 'active'
    return balanced ? 'active' : 'pending'
  }

  // ── Editing ────────────────────────────────────────────────────────────────
  const setValue = (field, value) => {
    setForm((f) => ({ ...f, [field]: value }))
    if (errors[field]) setErrors((e) => ({ ...e, [field]: undefined }))
  }

  const setStatus = (lineId, status) => setStatuses((s) => ({ ...s, [lineId]: status }))
  const setReturnDate = (lineId, date) => setReturnDates((d) => ({ ...d, [lineId]: date }))
  const realizeAll = (list) => setStatuses((s) => ({ ...s, ...Object.fromEntries(list.map((r) => [r.journal_line_id, 'realized'])) }))
  const removeExtra = (lineId) => {
    setExtraRows((x) => x.filter((r) => r.journal_line_id !== lineId))
    setStatuses((s) => { const n = { ...s }; delete n[lineId]; return n })
    setReturnDates((d) => { const n = { ...d }; delete n[lineId]; return n })
  }

  /** Blur check for a typed amount: refuse anything that is not a 2-decimal number. */
  const checkMoney = (field, label) => {
    const msg = moneyError(form[field], label)
    setErrors((e) => ({ ...e, [field]: msg ?? undefined }))
  }

  const validateCriteria = () => {
    const e = {}
    if (!form.company_id) e.company_id = 'Select the company.'
    if (!form.bank_account_id) e.bank_account_id = 'Select the bank account.'
    if (!form.statement_date) e.statement_date = 'Statement date is required.'
    else if (form.statement_date > today()) e.statement_date = 'The statement date cannot be in the future.'
    if (form.date_from && form.statement_date && form.date_from > form.statement_date) e.date_from = 'From must be on or before the statement date.'
    return e
  }

  const refresh = () => {
    if (isView) return
    const e = validateCriteria()
    if (Object.keys(e).length) { setErrors(e); showError(Object.values(e)[0]); return }
    setErrors({})
    const next = { bank_account_id: String(form.bank_account_id), statement_date: form.statement_date, date_from: form.date_from }
    if (applied && applied.bank_account_id !== next.bank_account_id) { setStatuses({}); setExtraRows([]); setReturnDates({}) }
    setApplied(next)
    qc.invalidateQueries({ queryKey: ['bank-reconciliation-workspace'] })
  }

  const clearMarks = async () => {
    if (isView || marked === 0) return
    const ok = await confirmAction({ title: 'Clear all marks?', message: 'Every Realize / Return / Not Presented choice on this screen is removed.', confirmText: 'Clear', confirmColor: '#ef4444' })
    if (ok) setStatuses({})
  }

  // ── Add Transaction ────────────────────────────────────────────────────────
  const runSearch = async () => {
    if (!applied) { showError('Choose the bank account and Refresh first.'); return }
    if (!searchTerm.trim()) { showError('Type something to search for.'); return }
    setSearching(true)
    try {
      setSearchResults(await searchReconciliationEntries(applied.bank_account_id, searchBy, searchTerm.trim(), allRows.map((r) => r.journal_line_id)))
    } catch (err) {
      showError(firstError(err, 'Search failed.'))
    } finally {
      setSearching(false)
    }
  }

  const addFound = (row) => {
    setExtraRows((x) => [...x, row])
    if (row.cleared_before) {
      // Bounced after an earlier statement cleared it: the bank returned it on this one.
      setStatus(row.journal_line_id, 'returned_later')
      setReturnDate(row.journal_line_id, applied?.statement_date ?? form.statement_date)
    } else {
      setStatus(row.journal_line_id, 'realized')
    }
    setSearchResults((r) => (r ?? []).filter((x) => x.journal_line_id !== row.journal_line_id))
  }

  const ENTRY_TEXT = { bank_charges: 'Bank charges', interest: 'Interest received' }

  const openEntry = () => {
    if (!applied) { showError('Choose the bank account and Refresh first.'); return }
    entryRequestIdRef.current = newRequestId()
    setEntry({ type: 'bank_charges', entry_date: applied.statement_date, amount: '', description: ENTRY_TEXT.bank_charges, reference: '' })
    setEntryOpen(true)
  }

  const submitEntry = async () => {
    if (entryBusy) return
    if (!entryType) { showError('Choose Bank Charges or Interest Income.'); return }
    if (!entryType.mapped) { showError(`No ${entryType.label} account is set up. Map one under Finance > Account Mapping first.`); return }
    if (!entry.entry_date) { showError('Enter the date shown on the statement.'); return }
    if (!isMoney(entry.amount) || toCents(entry.amount) <= 0) { showError('Enter an amount above zero, with at most 2 decimals.'); return }
    if ((entry.description ?? '').trim().length < 3) { showError('Enter a description.'); return }
    const into = entryType.direction === 'deposit'
    const ok = await confirmAction({
      title: 'Post this bank entry?',
      message: `${escapeHtml(entryType.label)}: ${into ? 'money into' : 'money out of'} <strong>${escapeHtml(selectedBank ? `${selectedBank.code} - ${selectedBank.name}` : 'the bank')}</strong>, <strong>${fmt(toCents(entry.amount))}</strong> on ${escapeHtml(entry.entry_date)}. This posts a journal to the ledger now.`,
      confirmText: 'Yes, Post',
    })
    if (!ok) return
    setEntryBusy(true)
    try {
      const row = await postBankEntry({
        request_id: entryRequestIdRef.current,
        bank_account_id: Number(applied.bank_account_id),
        entry_date: entry.entry_date,
        type: entry.type,
        amount: (toCents(entry.amount) / 100).toFixed(2),
        description: entry.description.trim(),
        reference: entry.reference.trim() || null,
      })
      setExtraRows((x) => [...x, row])
      setStatus(row.journal_line_id, 'realized')
      setEntryOpen(false)
      qc.invalidateQueries({ queryKey: ['journal-entries'] })
      showSuccess(`${row.journal_no} posted and realized.`)
    } catch (err) {
      showError(firstError(err, 'Could not post the bank entry.'))
    } finally {
      setEntryBusy(false)
    }
  }

  // ── Saving ─────────────────────────────────────────────────────────────────
  const payload = () => ({
    request_id: isCreate ? requestIdRef.current : undefined,
    company_id: Number(form.company_id),
    bank_account_id: Number(applied?.bank_account_id ?? form.bank_account_id),
    date_from: form.date_from || null,
    statement_date: applied?.statement_date ?? form.statement_date,
    // Sent as typed (commas removed): the server checks the format, nothing becomes 0.00 on the way.
    opening_balance: isFirst ? cleanMoney(form.opening_balance) : undefined,
    statement_balance: cleanMoney(form.statement_balance),
    notes: form.notes.trim() || null,
    lines: allRows
      .filter((r) => statuses[r.journal_line_id])
      .map((r) => {
        const status = r.cleared_before ? 'returned_later' : statuses[r.journal_line_id]
        const bounce = status === 'returned' || status === 'returned_later'
        return {
          journal_line_id: r.journal_line_id,
          status,
          return_date: bounce ? (returnDates[r.journal_line_id] || null) : undefined,
        }
      }),
    updated_at: isCreate ? undefined : recon?.updated_at,
  })

  /** andPost: save the draft, then post it. */
  const save = async (andPost) => {
    if (saving || isView) return
    if (andPost && !canPost) return
    const e = validateCriteria()
    if (isFirst && !form.date_from) e.date_from = 'First reconciliation of this account: enter the start date. Entries before it count as already cleared.'
    if (isFirst && cleanMoney(form.opening_balance) === '') e.opening_balance = 'First reconciliation of this account: enter the opening balance shown on the bank statement (0.00 if it was empty).'
    else if (isFirst) { const m = moneyError(form.opening_balance, 'Opening balance'); if (m) e.opening_balance = m }
    if (cleanMoney(form.statement_balance) === '') e.statement_balance = 'Enter the closing balance on the bank statement.'
    else { const m = moneyError(form.statement_balance, 'Statement balance'); if (m) e.statement_balance = m }
    if (Object.keys(e).length) { setErrors(e); showError(Object.values(e)[0]); return }
    if (!applied || applied.bank_account_id !== String(form.bank_account_id) || applied.statement_date !== form.statement_date
      || (isFirst && (applied.date_from ?? '') !== form.date_from)) {
      showError('The bank account, statement date or start date changed. Press Refresh to load its entries first.')
      return
    }

    if (andPost) {
      if (!balanced) { showError(`The variance is ${fmt(varianceCents)}. It must be 0.00 to post.`); return }
      const returned = Object.values(statuses).filter((s) => s === 'returned').length + lateRows.length
      const ok = await confirmAction({
        title: 'Post this reconciliation?',
        message: `Statement balance <strong>${fmt(statementCents)}</strong> on ${escapeHtml(form.statement_date)}. Realized entries are cleared for good${returned ? `, and <strong>${returned}</strong> returned cheque(s) get a bounce entry on their return date` : ''}. Only the latest reconciliation can be undone.`,
        confirmText: 'Yes, Post',
      })
      if (!ok) return
    }

    setSaving(true)
    try {
      const saved = isCreate ? await createBankReconciliation(payload()) : await updateBankReconciliation(id, payload())

      for (const file of pendingFiles) {
        try { await uploadAttachment('bank_reconciliation', saved.id, file) } catch (upErr) {
          showWarning(`${file.name}: ${firstError(upErr, 'could not be attached.')}`)
        }
      }
      if (pendingFiles.length) {
        setPendingFiles([])
        qc.invalidateQueries({ queryKey: ['bank-reconciliation-attachments', String(saved.id)] })
      }

      let posted = null
      if (andPost) {
        try {
          posted = await postBankReconciliation(saved.id) ?? saved
        } catch (pErr) {
          showWarning(`${saved.recon_no} saved as draft but not posted: ${firstError(pErr, 'posting failed.')}`)
        }
      }

      qc.invalidateQueries({ queryKey: ['bank-reconciliations'] })
      qc.invalidateQueries({ queryKey: ['bank-reconciliation', String(saved.id)] })
      qc.invalidateQueries({ queryKey: ['bank-reconciliation-workspace'] })

      if (posted) {
        showSuccess(postedMessage(posted, saved.recon_no))
        navigate(`/finance/bank-reconciliations/${saved.id}`)
      } else {
        if (!andPost) showSuccess(`${saved.recon_no} saved as draft.`)
        if (isCreate) navigate(`/finance/bank-reconciliations/${saved.id}/edit`, { replace: true })
      }
    } catch (sErr) {
      if (sErr?.response?.status === 409) {
        showError('This reconciliation was changed by someone else. Reload it and try again.')
      } else {
        const serverErrors = sErr?.response?.data?.errors ?? {}
        setErrors(Object.fromEntries(Object.entries(serverErrors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)])))
        qc.invalidateQueries({ queryKey: ['bank-reconciliation-workspace'] })
        showError(firstError(sErr, 'Could not save the reconciliation.'))
      }
    } finally {
      setSaving(false)
    }
  }

  // ── Bank statement files ───────────────────────────────────────────────────
  // Before the first save they wait in pendingFiles; once saved they upload at
  // once. Added to drafts and posted ones, removed only from a draft.
  const uploadMutation = useMutation({
    mutationFn: (file) => uploadAttachment('bank_reconciliation', id, file),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bank-reconciliation-attachments', id] })
      qc.invalidateQueries({ queryKey: ['bank-reconciliation-history', id] })
      showSuccess('Statement attached.')
    },
    onError: (e) => showError(firstError(e, 'Could not attach the file.')),
  })
  const removeMutation = useMutation({
    mutationFn: deleteAttachment,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bank-reconciliation-attachments', id] })
      qc.invalidateQueries({ queryKey: ['bank-reconciliation-history', id] })
      showSuccess('Statement removed.')
    },
    onError: (e) => showError(firstError(e, 'Could not remove the file.')),
  })

  const pickFiles = (e) => {
    const files = [...(e.target.files ?? [])]
    e.target.value = ''
    const room = MAX_FILES - attachments.length - pendingFiles.length
    if (files.length > room) showError(`At most ${MAX_FILES} files per reconciliation.`)
    const ok = files.slice(0, Math.max(0, room)).filter((f) => {
      if (!ALLOWED_TYPES.includes(f.type)) { showError(`${f.name}: only PDF, JPG or PNG.`); return false }
      if (f.size === 0 || f.size > MAX_FILE_BYTES) { showError(`${f.name}: must be smaller than 5 MB.`); return false }
      return true
    })
    if (isCreate) setPendingFiles((p) => [...p, ...ok])
    else ok.forEach((f) => uploadMutation.mutate(f))
  }

  const removeExisting = async (a) => {
    if (await confirmDelete(a.original_name)) removeMutation.mutate(a.id)
  }

  const canAttach = recon?.status !== 'voided' && (isCreate ? can('create_bank_reconciliations') : can('edit_bank_reconciliations'))
  const canRemoveFile = recon?.status === 'draft' && can('edit_bank_reconciliations')

  // ── Actions on a saved reconciliation (view mode) ──────────────────────────
  const refreshRecon = () => {
    qc.invalidateQueries({ queryKey: ['bank-reconciliation', id] })
    qc.invalidateQueries({ queryKey: ['bank-reconciliation-history', id] })
    qc.invalidateQueries({ queryKey: ['bank-reconciliations'] })
    qc.invalidateQueries({ queryKey: ['bank-reconciliation-workspace'] })
  }

  const postMutation = useMutation({
    mutationFn: () => postBankReconciliation(id),
    onSuccess: (r) => { refreshRecon(); showSuccess(postedMessage(r)) },
    onError: (e) => showError(firstError(e, 'Could not post this reconciliation.')),
  })
  const voidMutation = useMutation({
    mutationFn: (reason) => voidBankReconciliation(id, reason),
    onSuccess: (r) => { refreshRecon(); showSuccess(`${r?.recon_no} undone. Its entries are uncleared again.`) },
    onError: (e) => showError(firstError(e, 'Could not undo this reconciliation.')),
  })
  const deleteMutation = useMutation({
    mutationFn: () => deleteBankReconciliation(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bank-reconciliations'] })
      showSuccess('Draft reconciliation deleted.')
      navigate('/finance/bank-reconciliations')
    },
    onError: (e) => showError(firstError(e, 'Only draft reconciliations can be deleted.')),
  })

  const handlePost = async () => {
    const ok = await confirmAction({
      title: `Post ${recon.recon_no}?`,
      message: `Statement balance <strong>${fmt(toCents(recon.statement_balance))}</strong>. Realized entries are cleared for good.`,
      confirmText: 'Yes, Post',
    })
    if (ok) postMutation.mutate()
  }
  const handleVoid = async () => {
    const reason = await confirmWithReason({
      title: `Undo ${recon.recon_no}?`, inputLabel: 'Reason', inputPlaceholder: 'Why is this reconciliation being undone?', confirmText: 'Yes, Undo',
    })
    if (reason) voidMutation.mutate(reason)
  }
  const handleDelete = async () => {
    if (await confirmDelete(recon.recon_no)) deleteMutation.mutate()
  }

  const handlePdf = async (print) => {
    setPdfBusy(true)
    try {
      const blob = await downloadBankReconciliationPdf(id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `BR_${recon.recon_no}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError('Failed to generate the reconciliation statement.')
    } finally {
      setPdfBusy(false)
    }
  }

  // ── Keyboard ───────────────────────────────────────────────────────────────
  const saveRef = useRef(save)
  const refreshRef = useRef(refresh)
  const clearRef = useRef(clearMarks)
  useEffect(() => { saveRef.current = save; refreshRef.current = refresh; clearRef.current = clearMarks })
  useEffect(() => {
    const handler = (e) => {
      const popup = document.querySelector('.swal2-popup')
      if (popup && !popup.classList.contains('swal2-toast')) return
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault()
        saveRef.current(false)
      } else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault()
        saveRef.current(true)
      } else if (e.altKey && e.key.toLowerCase() === 'r') {
        e.preventDefault()
        refreshRef.current()
      } else if (e.altKey && e.key.toLowerCase() === 'c') {
        e.preventDefault()
        clearRef.current()
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])

  // ── Render ─────────────────────────────────────────────────────────────────
  if (!isCreate && loadingRecon) {
    return <div className="flex items-center justify-center py-20 text-sm text-slate-400"><RefreshCw size={14} className="mr-2 animate-spin" /> Loading…</div>
  }
  if (!isCreate && reconError) {
    return <div className="py-20 text-center text-sm text-red-500">Reconciliation not found, or you do not have access to it.</div>
  }

  const readOnly = isView || saving
  const title = isCreate ? 'Bank Reconciliation' : `Bank Reconciliation ${recon?.recon_no ?? ''}`
  const crumbs = [
    { label: 'Finance' },
    { label: 'Bank Reconciliation', to: '/finance/bank-reconciliations' },
    { label: isCreate ? 'New' : isView ? recon?.recon_no : 'Edit' },
  ]
  const bankOptions = isView && recon
    ? [{ value: recon.bank_account_id, label: recon.bank_account_name }]
    : bankAccounts.map((a) => ({ value: a.id, label: `${a.code} - ${a.name}` }))
  const varianceTone = !loaded || cleanMoney(form.statement_balance) === '' ? 'slate' : balanced ? 'green' : 'red'

  return (
    <div className="w-full pb-16">
      <div className="mb-1.5 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{title}</h1>
          <Breadcrumb crumbs={crumbs} />
        </div>
        <div className="flex items-center gap-3">
          <Step n={1} label="Choose bank & period" state={stepState(1)} />
          <Step n={2} label="Match transactions" state={stepState(2)} />
          <Step n={3} label="Review & post" state={stepState(3)} />
          {recon && (
            <span className={`ml-2 rounded-full px-2.5 py-1 text-[11px] font-bold ${
              recon.status === 'posted' ? 'bg-green-100 text-green-700' : recon.status === 'voided' ? 'bg-slate-200 text-slate-500' : 'bg-amber-100 text-amber-700'
            }`}>{recon.status_label}</span>
          )}
        </div>
      </div>

      <div className="space-y-2">
        {/* ── Criteria ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 rounded-t-lg border-b border-indigo-100 bg-indigo-50 px-3 py-1.5 text-indigo-700">
            <Landmark size={13} />
            <h2 className="text-xs font-bold">Criteria</h2>
            {workspace?.last_recon_no && !isView && (
              <span className="ml-2 text-[10px] font-semibold text-indigo-500">
                Last reconciled: {workspace.last_recon_no} on {workspace.last_statement_date}
              </span>
            )}
            {workspace?.truncated && <span className="ml-2 text-[10px] font-semibold text-red-600">Too many entries: showing the oldest 3,000. Reconcile an earlier statement date first.</span>}
          </div>
          {/* items-start + a label over every control: inputs line up even when one field shows a hint or error below it */}
          <div className="grid grid-cols-1 items-start gap-x-3 gap-y-2 p-2.5 md:grid-cols-3 xl:grid-cols-6">
            {companies.length > 1 && (
              <div>
                <label className={LABEL_CLS}>Company <Req /></label>
                <select
                  className={`${selectCls(errors.company_id)} h-9`}
                  value={form.company_id}
                  onChange={(e) => setForm((f) => ({ ...f, company_id: e.target.value, bank_account_id: '' }))}
                  disabled={readOnly || !isCreate}
                >
                  <option value="">Select</option>
                  {(isView && recon ? [{ id: recon.company_id, name: recon.company_name }] : companies)
                    .map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <FieldError msg={errors.company_id} />
              </div>
            )}
            <div className="md:col-span-2">
              <label className={LABEL_CLS}>Bank Account <Req /></label>
              <FilterSearchSelect
                size="form"
                value={form.bank_account_id}
                onChange={(v) => setValue('bank_account_id', v)}
                options={bankOptions}
                placeholder={!form.company_id ? 'Select the company first' : (bankOptions.length ? 'Select bank account' : 'No bank accounts set up')}
                invalid={Boolean(errors.bank_account_id)}
                clearable={false}
                disabled={readOnly || !isCreate || !form.company_id}
              />
              {selectedBank && (selectedBank.bank_name || selectedBank.bank_account_no) && (
                <p className="mt-0.5 truncate text-[10px] text-slate-500">
                  {[selectedBank.bank_name, selectedBank.branch_name, selectedBank.bank_account_no && `A/C ${selectedBank.bank_account_no}`].filter(Boolean).join(' · ')}
                </p>
              )}
              <FieldError msg={errors.bank_account_id} />
            </div>
            <div>
              <label className={LABEL_CLS}>{isFirst ? <>Start Date <Req /></> : 'From'}</label>
              <input type="date" className={`${inputCls(errors.date_from)} h-9`} value={form.date_from} max={form.statement_date || undefined}
                onChange={(e) => setValue('date_from', e.target.value)} disabled={readOnly} />
              {isFirst && !errors.date_from && (
                <p className="mt-0.5 text-[10px] text-amber-600">First reconciliation: entries before this date count as already cleared. Press Refresh after changing it.</p>
              )}
              {!isFirst && workspace?.cutoff_date && !isView && (
                <p className="mt-0.5 text-[10px] text-slate-400">Account reconciled from {workspace.cutoff_date}</p>
              )}
              <FieldError msg={errors.date_from} />
            </div>
            <div>
              <label className={LABEL_CLS}>Statement Date (To) <Req /></label>
              <input ref={firstFieldRef} type="date" className={`${inputCls(errors.statement_date)} h-9`} value={form.statement_date} max={today()}
                onChange={(e) => setValue('statement_date', e.target.value)} disabled={readOnly} />
              <FieldError msg={errors.statement_date} />
            </div>
            {!isView && (
              <div>
                <span aria-hidden="true" className={`${LABEL_CLS} invisible`}>Load</span>
                <button type="button" onClick={refresh} disabled={saving || loadingWorkspace}
                  className="flex h-9 w-full items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-4 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50">
                  <RefreshCw size={12} className={loadingWorkspace ? 'animate-spin' : ''} /> Refresh
                </button>
              </div>
            )}
            {isView && (
              <div>
                <label className={LABEL_CLS}>Reconciliation No</label>
                <input readOnly tabIndex={-1} className={`${INPUT_DISABLED_CLS} h-9`} value={recon?.recon_no ?? ''} />
              </div>
            )}
            {isCreate && (
              <div className="hidden xl:block">
                <label className={LABEL_CLS}>Document No <span className="ml-1 text-[10px] font-medium normal-case text-indigo-400">auto</span></label>
                <input readOnly tabIndex={-1} className={`${INPUT_DISABLED_CLS} h-9`} value={nextNo} />
              </div>
            )}
          </div>
          {workspaceError && (
            <p className="px-3 pb-2 text-[11px] text-red-600">{firstError(workspaceError, 'Could not load the entries for this account.')}</p>
          )}
        </div>

        {/* ── Balances ── */}
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <StatCard label={isFirst ? <>Opening Balance <Req /></> : 'Opening Balance'} hint={isFirst ? 'First reconciliation: the statement balance on the day before the start date' : (workspace?.last_recon_no ? `From ${workspace.last_recon_no}` : null)}>
            {isFirst ? (
              <input inputMode="decimal" className={`${inputCls(errors.opening_balance)} mt-0.5 text-right font-bold`} value={form.opening_balance}
                placeholder="0.00" onChange={(e) => setValue('opening_balance', e.target.value.replace(/[^\d.,-]/g, ''))}
                onBlur={() => checkMoney('opening_balance', 'Opening balance')} disabled={readOnly} />
            ) : (
              <div className="text-lg font-bold text-slate-800">{fmt(openingCents)}</div>
            )}
            {isFirst && <FieldError msg={errors.opening_balance} />}
          </StatCard>
          <StatCard label={<>Statement Balance <Req /></>} hint="Closing balance printed on the bank statement">
            {isView ? (
              <div className="text-lg font-bold text-slate-800">{fmt(statementCents)}</div>
            ) : (
              <input inputMode="decimal" className={`${inputCls(errors.statement_balance)} mt-0.5 text-right font-bold`} value={form.statement_balance}
                placeholder="0.00" onChange={(e) => setValue('statement_balance', e.target.value.replace(/[^\d.,-]/g, ''))}
                onBlur={() => checkMoney('statement_balance', 'Statement balance')} disabled={readOnly} />
            )}
            <FieldError msg={errors.statement_balance} />
          </StatCard>
          <StatCard label="Closing Balance" hint={`Opening + ${fmt(realizedIn)} in − ${fmt(realizedOut)} out${lateRows.length ? ' (incl. returned after clearing)' : ''}`}>
            <div className="text-lg font-bold text-slate-800">{fmt(closingCents)}</div>
          </StatCard>
          <StatCard label="Variance" tone={varianceTone} hint={isView ? null : 'Post is enabled when the variance is 0.00'}>
            <div className={`text-lg font-bold ${varianceTone === 'green' ? 'text-green-700' : varianceTone === 'red' ? 'text-red-600' : 'text-slate-800'}`}>{fmt(varianceCents)}</div>
          </StatCard>
        </div>

        {!loaded && !isView && (
          <div className="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-xs text-slate-500">
            {loadingWorkspace ? 'Loading entries…' : 'Choose the bank account and statement date, then press Refresh (Alt+R) to load the uncleared entries.'}
          </div>
        )}

        {loaded && (
          <>
            <div className="grid grid-cols-1 gap-2 2xl:grid-cols-2">
              <EntriesTable
                title="Deposits" subtitle="money received into the bank" icon={ArrowDownCircle}
                rows={deposits} statuses={statuses} onStatus={setStatus} onRealizeAll={realizeAll} readOnly={readOnly}
                dateFrom={form.date_from} statementDate={form.statement_date} extraIds={extraIds} onRemoveExtra={removeExtra}
                returnDates={returnDates} onReturnDate={setReturnDate}
              />
              <EntriesTable
                title="Withdrawals, Bank Charges and Issued Cheques" subtitle="money paid out of the bank" icon={ArrowUpCircle}
                rows={withdrawals} statuses={statuses} onStatus={setStatus} onRealizeAll={realizeAll} readOnly={readOnly}
                dateFrom={form.date_from} statementDate={form.statement_date} extraIds={extraIds} onRemoveExtra={removeExtra}
                returnDates={returnDates} onReturnDate={setReturnDate}
              />
            </div>

            <LateReturnsTable
              rows={lateRows} returnDates={returnDates} onReturnDate={setReturnDate} onRemove={removeExtra}
              readOnly={readOnly} statementDate={form.statement_date} lastStatementDate={workspace?.last_statement_date}
            />

            {/* ── Add Transaction ── */}
            {!isView && (
              <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
                <div className="flex items-center gap-1.5 rounded-t-lg border-b border-slate-100 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">
                  <Plus size={12} /> Add Transaction <span className="font-normal text-slate-400">missing a bank entry? Find it in the books, or post a new one</span>
                </div>
                <div className="grid grid-cols-1 items-end gap-x-3 gap-y-2 p-2.5 md:grid-cols-6">
                  <div>
                    <label className={LABEL_CLS}>Search by</label>
                    <select className={selectCls(false)} value={searchBy} onChange={(e) => setSearchBy(e.target.value)} disabled={readOnly}>
                      {SEARCH_BY.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                  </div>
                  <div className="md:col-span-3">
                    <label className={LABEL_CLS}>Search</label>
                    <input className={inputCls(false)} value={searchTerm}
                      placeholder={searchBy === 'returned' ? 'Cheque no or amount of a cheque an earlier statement cleared' : 'Type to search uncleared entries of any date'}
                      onChange={(e) => setSearchTerm(e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); runSearch() } }} disabled={readOnly} />
                  </div>
                  <button type="button" onClick={runSearch} disabled={readOnly || searching}
                    className="flex h-[30px] items-center justify-center gap-1 rounded-md border border-indigo-300 bg-white px-3 text-xs font-bold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">
                    <Search size={12} /> {searching ? 'Searching…' : 'Find'}
                  </button>
                  {canPost && (
                    <button type="button" onClick={openEntry} disabled={readOnly}
                      className="flex h-[30px] items-center justify-center gap-1 rounded-md bg-amber-500 px-3 text-xs font-bold text-white hover:bg-amber-600 disabled:opacity-50">
                      <Plus size={12} /> New Bank Entry
                    </button>
                  )}
                </div>

                {searchResults && (
                  <div className="border-t border-slate-100">
                    <table className="w-full text-xs">
                      <tbody className="divide-y divide-slate-100">
                        {searchResults.length === 0 && (
                          <tr><td className="px-3 py-2 text-slate-400">{searchBy === 'returned' ? 'No realized cheque matches.' : 'Nothing uncleared matches.'}</td></tr>
                        )}
                        {searchResults.map((r) => {
                          const blocked = r.cleared_before && r.return_blocked
                          return (
                            <tr key={r.journal_line_id} className="hover:bg-slate-50">
                              <td className="px-2 py-1 font-mono text-slate-600">{r.journal_no}</td>
                              <td className="px-2 py-1 text-slate-500">
                                {r.journal_date}
                                {form.date_from && isFirst && r.journal_date < form.date_from && (
                                  <span title="Before the start date: counted as cleared unless you add it" className="ml-1 rounded bg-amber-100 px-1 text-[9px] font-bold text-amber-700">PRE-START</span>
                                )}
                              </td>
                              <td className="px-2 py-1 font-mono">{r.instrument_no}</td>
                              <td className="max-w-[320px] truncate px-2 py-1 text-slate-600" title={r.description ?? ''}>
                                {r.cleared_before && <span className="mr-1 rounded bg-green-100 px-1 text-[9px] font-bold text-green-700">CLEARED {r.cleared_recon_no}</span>}
                                {r.description}
                              </td>
                              <td className={`px-2 py-1 text-right font-semibold ${r.direction === 'deposit' ? 'text-green-700' : 'text-red-600'}`}>
                                {r.direction === 'deposit' ? '+' : '−'}{fmt(toCents(r.amount))}
                              </td>
                              <td className="px-2 py-1 text-right">
                                <button type="button" onClick={() => addFound(r)} disabled={Boolean(blocked)} title={blocked || (r.cleared_before ? 'Add as returned on this statement' : 'Add and realize')}
                                  className={`rounded px-2 py-0.5 text-[11px] font-bold text-white disabled:opacity-40 ${r.cleared_before ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700'}`}>
                                  {r.cleared_before ? 'Return' : 'Add'}
                                </button>
                              </td>
                            </tr>
                          )
                        })}
                      </tbody>
                    </table>
                  </div>
                )}

                {entryOpen && (
                  <div className="border-t border-amber-200 bg-amber-50/50 p-2.5">
                    <div className="grid grid-cols-1 items-end gap-x-3 gap-y-2 md:grid-cols-4 xl:grid-cols-8">
                      <div className="md:col-span-2">
                        <label className={LABEL_CLS}>Type <Req /></label>
                        <select className={selectCls(false)} value={entry.type}
                          onChange={(e) => setEntry((x) => ({ ...x, type: e.target.value, description: ENTRY_TEXT[e.target.value] ?? x.description }))}>
                          {entryTypes.map((t) => (
                            <option key={t.value} value={t.value}>
                              {t.label} ({t.direction === 'deposit' ? 'money in' : 'money out'}){t.mapped ? '' : ' - not mapped'}
                            </option>
                          ))}
                        </select>
                        {entryType && !entryType.mapped && (
                          <p className="mt-0.5 flex items-center gap-1 text-[10px] text-red-600">
                            <AlertTriangle size={10} /> No {entryType.label} account. Map it under Finance &gt; Account Mapping.
                          </p>
                        )}
                      </div>
                      <div>
                        <label className={LABEL_CLS}>Date <Req /></label>
                        <input type="date" className={inputCls(false)} value={entry.entry_date} max={today()}
                          min={workspace?.last_statement_date || undefined}
                          onChange={(e) => setEntry((x) => ({ ...x, entry_date: e.target.value }))} />
                      </div>
                      <div>
                        <label className={LABEL_CLS}>Amount <Req /></label>
                        <input inputMode="decimal" className={`${inputCls(entry.amount !== '' && !isMoney(entry.amount))} text-right font-bold`} value={entry.amount} placeholder="0.00"
                          onChange={(e) => setEntry((x) => ({ ...x, amount: e.target.value.replace(/[^\d.,]/g, '') }))} />
                      </div>
                      <div className="md:col-span-2">
                        <label className={LABEL_CLS}>Description <Req /></label>
                        <input className={inputCls(false)} value={entry.description} maxLength={200} onChange={(e) => setEntry((x) => ({ ...x, description: e.target.value }))} />
                      </div>
                      <div>
                        <label className={LABEL_CLS}>Reference</label>
                        <input className={inputCls(false)} value={entry.reference} maxLength={30} placeholder="Stmt ref" onChange={(e) => setEntry((x) => ({ ...x, reference: e.target.value }))} />
                      </div>
                    </div>
                    <div className="mt-2 flex justify-end gap-2">
                      <button type="button" onClick={() => setEntryOpen(false)} className="rounded-md bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600 hover:bg-slate-200">Cancel</button>
                      <button type="button" onClick={submitEntry} disabled={entryBusy || !entryType?.mapped}
                        className="flex items-center gap-1 rounded-md bg-amber-600 px-3 py-1 text-xs font-bold text-white hover:bg-amber-700 disabled:opacity-50">
                        {entryBusy && <RefreshCw size={11} className="animate-spin" />} Post Entry &amp; Realize
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )}
          </>
        )}

        {/* ── Notes + footer ── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="p-2.5">
            <label className={LABEL_CLS}>Notes</label>
            <textarea rows={2} className={textareaCls(errors.notes)} value={form.notes} maxLength={1000} placeholder="Type"
              onChange={(e) => setValue('notes', e.target.value)} disabled={readOnly} />
          </div>

          {!isView && (
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-2">
              <span className="text-[11px] text-slate-500">
                {marked} marked · Variance <strong className={balanced ? 'text-green-700' : 'text-red-600'}>{fmt(varianceCents)}</strong>
              </span>
              <div className="flex items-center gap-2">
                <button type="button" onClick={() => navigate('/finance/bank-reconciliations')} disabled={saving}
                  className="rounded-md bg-slate-100 px-4 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200 disabled:opacity-40">Cancel</button>
                <button type="button" onClick={clearMarks} disabled={saving || marked === 0}
                  className="rounded-md bg-red-600 px-4 py-1.5 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-40">Clear</button>
                <button type="button" onClick={() => save(false)} disabled={saving || !loaded}
                  className="flex items-center gap-1.5 rounded-md bg-sky-500 px-4 py-1.5 text-xs font-bold text-white hover:bg-sky-600 disabled:opacity-50">
                  {saving && <RefreshCw size={12} className="animate-spin" />} Save Draft
                </button>
                {canPost && (
                  <button type="button" onClick={() => save(true)} disabled={saving || !loaded || !balanced}
                    title={balanced ? 'Post this reconciliation' : 'The variance must be 0.00 to post'}
                    className="flex items-center gap-1.5 rounded-md bg-green-600 px-5 py-1.5 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-40">
                    <CheckCircle size={12} /> Post
                  </button>
                )}
              </div>
            </div>
          )}

          {isView && recon && (
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 text-[11px] text-slate-500">
              <div className="flex flex-wrap gap-x-4 gap-y-1">
                {recon.book_balance !== null && <span>Book balance: <strong className="text-slate-800">{fmt(toCents(recon.book_balance))}</strong></span>}
                {recon.posted_by_name && <span>Posted by {recon.posted_by_name}</span>}
                {recon.created_by_name && <span>Prepared by {recon.created_by_name}</span>}
                {recon.void_reason && <span className="text-red-600">Undo reason: {recon.void_reason}</span>}
              </div>
              <div className="flex items-center gap-1.5">
                {recon.status !== 'voided' && (
                  <>
                    <button type="button" onClick={() => handlePdf(true)} disabled={pdfBusy} className="flex items-center gap-1 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200 disabled:opacity-40">
                      <Printer size={12} /> Print
                    </button>
                    <button type="button" onClick={() => handlePdf(false)} disabled={pdfBusy} className="flex items-center gap-1 rounded-md bg-sky-50 px-3 py-1.5 text-xs font-bold text-sky-700 hover:bg-sky-100 disabled:opacity-40">
                      <Download size={12} /> PDF
                    </button>
                  </>
                )}
                {recon.status === 'draft' && can('edit_bank_reconciliations') && (
                  <Link to={`/finance/bank-reconciliations/${recon.id}/edit`} className="flex items-center gap-1 rounded-md bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700 hover:bg-amber-100">
                    <Edit2 size={12} /> Edit
                  </Link>
                )}
                {recon.status === 'draft' && canPost && (
                  <button type="button" onClick={handlePost} disabled={postMutation.isPending || toCents(recon.difference) !== 0}
                    title={toCents(recon.difference) !== 0 ? 'The variance must be 0.00 to post' : 'Post'}
                    className="flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-40">
                    <CheckCircle size={12} /> Post
                  </button>
                )}
                {recon.status === 'draft' && can('delete_bank_reconciliations') && (
                  <button type="button" onClick={handleDelete} disabled={deleteMutation.isPending} className="flex items-center gap-1 rounded-md bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100 disabled:opacity-40">
                    <Trash2 size={12} /> Delete
                  </button>
                )}
                {recon.status === 'posted' && can('void_bank_reconciliations') && (
                  <button type="button" onClick={handleVoid} disabled={voidMutation.isPending} className="flex items-center gap-1 rounded-md bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-40">
                    <Undo2 size={12} /> Undo
                  </button>
                )}
              </div>
            </div>
          )}
        </div>

        {/* ── Bank statement files + History side by side ── */}
        <div className={`grid grid-cols-1 gap-2 ${isView ? 'lg:grid-cols-2' : ''}`}>
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-3 py-1.5">
              <div className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
                <Paperclip size={12} /> Bank Statement <span className="font-normal text-slate-400">PDF, JPG, PNG · max 5 MB · up to {MAX_FILES}</span>
              </div>
              {canAttach && (
                <>
                  <input ref={fileInputRef} type="file" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" className="hidden" onChange={pickFiles} />
                  <button type="button" onClick={() => fileInputRef.current?.click()} disabled={saving || uploadMutation.isPending}
                    className="flex items-center gap-1 rounded-md border border-indigo-200 bg-white px-2 py-0.5 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50 disabled:opacity-40">
                    <Upload size={11} /> {uploadMutation.isPending ? 'Uploading…' : 'Upload'}
                  </button>
                </>
              )}
            </div>
            <ul className="max-h-40 divide-y divide-slate-100 overflow-auto text-xs">
              {attachments.map((a) => (
                <li key={a.id} className="flex items-center justify-between px-3 py-1.5">
                  <span className="truncate text-slate-700" title={a.original_name}>{a.original_name} <span className="text-slate-400">· {fmtSize(a.size)}</span></span>
                  <span className="flex shrink-0 items-center gap-1">
                    <button type="button" title="Download" onClick={() => downloadAttachment(a).catch(() => showError('Could not download the file.'))} className="rounded p-1 text-sky-600 hover:bg-sky-50"><Download size={13} /></button>
                    {canRemoveFile && (
                      <button type="button" title="Remove" onClick={() => removeExisting(a)} disabled={removeMutation.isPending} className="rounded p-1 text-red-600 hover:bg-red-50 disabled:opacity-40"><Trash2 size={13} /></button>
                    )}
                  </span>
                </li>
              ))}
              {pendingFiles.map((f, i) => (
                <li key={`p-${i}`} className="flex items-center justify-between px-3 py-1.5 text-slate-600">
                  <span className="truncate" title={f.name}>{f.name} <span className="text-amber-600">· uploads on save</span></span>
                  <button type="button" title="Remove" onClick={() => setPendingFiles((p) => p.filter((_, j) => j !== i))} className="rounded p-1 text-red-600 hover:bg-red-50"><X size={13} /></button>
                </li>
              ))}
              {attachments.length === 0 && pendingFiles.length === 0 && (
                <li className="px-3 py-2 text-slate-400">No bank statement attached.</li>
              )}
            </ul>
          </div>

        {isView && (
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">
              <History size={12} /> History
            </div>
            <ul className="max-h-40 divide-y divide-slate-100 overflow-auto text-xs">
              {history.map((h) => (
                <li key={h.id} className="flex items-start justify-between gap-2 px-3 py-1.5">
                  <span>
                    <span className="font-semibold text-slate-700">{EVENT_LABELS[h.event] ?? h.event}</span>
                    {h.new_values?.void_reason && <span className="text-slate-500"> — {h.new_values.void_reason}</span>}
                    {h.event === 'updated' && h.new_values && <span className="text-slate-500"> — {Object.keys(h.new_values).join(', ')}</span>}
                  </span>
                  <span className="shrink-0 text-right text-slate-400">{h.user_name ?? 'System'} · {h.created_at ? new Date(h.created_at).toLocaleString() : ''}</span>
                </li>
              ))}
              {history.length === 0 && <li className="px-3 py-2 text-slate-400">No history.</li>}
            </ul>
          </div>
        )}
        </div>
      </div>

      {!isView && (
        <KeyboardShortcuts shortcuts={[
          { keys: ['Alt', 'R'], desc: 'Refresh: load the uncleared entries' },
          { keys: ['Ctrl', 'S'], desc: 'Save Draft' },
          { keys: ['Ctrl', 'Enter'], desc: 'Post (when the variance is 0.00)' },
          { keys: ['Alt', 'C'], desc: 'Clear all marks' },
          { keys: ['Enter'], desc: 'Find (in the Add Transaction search box)' },
          { keys: ['Space'], desc: 'Choose Realize / Return / Not Presented (on its radio)' },
        ]} />
      )}
    </div>
  )
}
