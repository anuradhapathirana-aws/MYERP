import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Ban, CheckCircle, ChevronDown, Download, Edit2, History, Landmark, ListChecks, Paperclip, RefreshCw,
  Trash2, Upload, Wallet, X,
} from 'lucide-react'
import {
  confirmBillPayment, createBillPayment, deleteBillPayment, getBillPayment, getBillPaymentHistory,
  getBillPaymentOptions, getNextBillPaymentNo, getOpenBills, updateBillPayment, voidBillPayment,
} from '../../api/billPayments'
import { deleteAttachment, downloadAttachment, getAttachments, uploadAttachment } from '../../api/financeAttachments'
import { getAllSuppliers } from '../../api/suppliers'
import Breadcrumb from '../../components/Breadcrumb'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Money from '../../components/ui/Money'
import { usePermissions } from '../../hooks/usePermissions'
import {
  confirmAction, confirmDelete, confirmWithReasonAndDate, showError, showSuccess, showWarning,
} from '../../utils/alerts'
import { INPUT_DISABLED_CLS, LABEL_CLS, inputCls, selectCls, textareaCls } from '../../utils/fieldStyles'

const today = () => new Date().toLocaleDateString('en-CA')

const MAX_FILE_BYTES = 5 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']

/** randomUUID needs a secure context; the VPS may serve over http. */
const newRequestId = () => {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID()
  const b = window.crypto.getRandomValues(new Uint8Array(16))
  b[6] = (b[6] & 0x0f) | 0x40
  b[8] = (b[8] & 0x3f) | 0x80
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

// Money is worked in integer cents, never floats.
const toCents = (v) => Math.round((Number(v) || 0) * 100)
const fromCents = (c) => (c / 100).toFixed(2)
const fmt = (c) => (c / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const emptyForm = (companyId = '') => ({
  payment_date: today(),
  reference_no: '',
  supplier_id: '',
  company_id: companyId,
  payment_mode_id: '', // shared Master Data -> Payment Modes; its type decides the rest
  pay_from_account_id: '',
  cheque_no: '',
  cheque_date: '',
  description: '',
})

const firstError = (err, fallback) => {
  const errors = err?.response?.data?.errors
  return (errors && Object.values(errors)[0]?.[0]) ?? err?.response?.data?.message ?? fallback
}

const EVENT_LABELS = {
  created: 'Created', updated: 'Edited', confirmed: 'Confirmed', voided: 'Voided', deleted: 'Deleted',
  attachment_added: 'File attached', attachment_removed: 'File removed',
}

function Req() {
  return <span className="text-red-500">*</span>
}

function FieldError({ msg }) {
  return msg ? <p className="mt-0.5 text-[10px] text-red-500">{msg}</p> : null
}

/** Row status: what is left after this payment, and how late the bill is. */
function RowStatus({ dueCents, payCents, days }) {
  if (payCents > 0 && payCents >= dueCents) return <span className="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-700">Settled</span>
  if (payCents > 0) return <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Part paid</span>
  if (days > 0) return <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">Overdue</span>
  return <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">Open</span>
}

const fmtSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

/**
 * Create / edit / view a Bill Payment.
 *
 * Follows the agreed mockup: Payment Date, Document No (auto), Reference No,
 * Vendor, the vendor's open bills with Due / Payment / Remaining per row,
 * Payment Summary (Partial / Full), Pay From (Cash Book: Cash or Cheque ·
 * Bank: transfer), Description.
 *
 * Bills load automatically once Vendor and Company are chosen, oldest due
 * first. Typing a total in Payment Amount spreads it over them in that order;
 * Full Payment pays every row; the % box pays the same share of each.
 *
 * "Create" means save AND confirm (pay out and post). Save as Draft keeps it
 * editable and reserves nothing; the server re-checks every bill's balance at
 * confirm time.
 */
export default function BillPaymentFormPage() {
  const { id } = useParams()
  const { pathname } = useLocation()
  const [searchParams] = useSearchParams()
  const mode = !id ? 'create' : pathname.endsWith('/edit') ? 'edit' : 'view'
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { can } = usePermissions()

  const isCreate = mode === 'create'
  const isView = mode === 'view'

  const [form, setForm] = useState(() => ({
    ...emptyForm(),
    supplier_id: searchParams.get('supplier_id') ?? '',
    company_id: searchParams.get('company_id') ?? '',
  }))
  // bill_id -> amount typed (string). Rows without an entry pay nothing.
  const [amounts, setAmounts] = useState({})
  const [fullPayment, setFullPayment] = useState(false)
  const [percentOn, setPercentOn] = useState(false)
  const [percent, setPercent] = useState('')
  const [totalInput, setTotalInput] = useState(null) // null = show the sum of rows
  const [errors, setErrors] = useState({})
  const [rowErrors, setRowErrors] = useState({})
  const [touched, setTouched] = useState({})
  const [pendingFiles, setPendingFiles] = useState([])
  const [menuOpen, setMenuOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const requestIdRef = useRef(newRequestId())
  const fileInputRef = useRef(null)
  const firstFieldRef = useRef(null)
  const rowInputs = useRef([])
  // Arrived from a bill's "Pay" button (bill_id) or "Pay Balance" (bill_ids):
  // those bills are paid in full once they load.
  const [prefillBills, setPrefillBills] = useState(
    () => (searchParams.get('bill_ids') ?? searchParams.get('bill_id') ?? '').split(',').filter(Boolean),
  )
  // Paying a balance: the mode just used is not offered as the default again.
  const [avoidModeId, setAvoidModeId] = useState(() => searchParams.get('after_mode') ?? '')

  // ── Lookups ────────────────────────────────────────────────────────────────
  const { data: options = {} } = useQuery({
    queryKey: ['bill-payment-options'],
    queryFn: getBillPaymentOptions,
    staleTime: 5 * 60 * 1000,
  })
  const { data: suppliers = [] } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn: getAllSuppliers,
    staleTime: 5 * 60 * 1000,
  })

  const { data: payment, isLoading: loadingPayment, isError: paymentError } = useQuery({
    queryKey: ['bill-payment', id],
    queryFn: () => getBillPayment(id),
    enabled: !isCreate && Boolean(id),
  })

  const { data: openBills = [], isFetching: loadingBills } = useQuery({
    queryKey: ['open-bills', form.supplier_id, form.company_id],
    queryFn: () => getOpenBills(form.supplier_id, form.company_id),
    enabled: !isView && Boolean(form.supplier_id) && Boolean(form.company_id),
  })

  const { data: attachments = [] } = useQuery({
    queryKey: ['bill-payment-attachments', id],
    queryFn: () => getAttachments('bill_payment', id),
    enabled: !isCreate && Boolean(id),
  })

  const { data: history = [] } = useQuery({
    queryKey: ['bill-payment-history', id],
    queryFn: () => getBillPaymentHistory(id),
    enabled: isView && Boolean(id),
  })

  const { data: nextNo = '' } = useQuery({
    queryKey: ['bill-payment-next-no', form.payment_date],
    queryFn: () => getNextBillPaymentNo(form.payment_date),
    enabled: isCreate && Boolean(form.payment_date),
  })

  const companies = useMemo(() => options.companies ?? [], [options.companies])
  const lockDate = options.lock_date ?? null

  if (isCreate && !form.company_id && companies.length === 1) {
    setForm((f) => ({ ...f, company_id: String(companies[0].id) }))
  }

  // Load the payment into the form for edit / view, once per saved version.
  const [loadedVersion, setLoadedVersion] = useState(null)
  if (payment && loadedVersion !== payment.updated_at) {
    setLoadedVersion(payment.updated_at)
    setForm({
      payment_date: payment.payment_date ?? '',
      reference_no: payment.reference_no ?? '',
      supplier_id: String(payment.supplier_id ?? ''),
      company_id: String(payment.company_id ?? ''),
      payment_mode_id: String(payment.payment_mode_id ?? ''),
      pay_from_account_id: String(payment.pay_from_account_id ?? ''),
      cheque_no: payment.cheque_no ?? '',
      cheque_date: payment.cheque_date ?? '',
      description: payment.description ?? '',
    })
    setAmounts(Object.fromEntries((payment.allocations ?? []).map((a) => [a.bill_id, a.amount])))
    setTotalInput(null)
  }

  useEffect(() => {
    if (mode === 'edit' && payment && payment.status !== 'draft') navigate(`/finance/bill-payments/${payment.id}`, { replace: true })
  }, [mode, payment, navigate])

  useEffect(() => { firstFieldRef.current?.focus() }, [])

  // ── Rows ───────────────────────────────────────────────────────────────────
  // View: what was paid. Create / edit: the vendor's open bills, plus any bill
  // already on this draft that has since dropped off the open list.
  const rows = useMemo(() => {
    if (isView) {
      return (payment?.allocations ?? []).map((a) => ({
        bill_id: a.bill_id, bill_no: a.bill_no, document_no: a.document_no,
        transaction_date: a.transaction_date, due_date: a.due_date, account: null, days_overdue: 0,
        balance: a.balance_before ?? a.amount,
      }))
    }
    const list = [...openBills]
    const known = new Set(list.map((b) => b.bill_id))
    ;(payment?.allocations ?? []).forEach((a) => {
      if (!known.has(a.bill_id) && String(payment.supplier_id) === String(form.supplier_id)) {
        list.push({ ...a, account: null, days_overdue: 0, balance: a.balance ?? '0' })
      }
    })
    return list
  }, [isView, payment, openBills, form.supplier_id])

  // Waits for a fresh load, so a balance is never prefilled from stale rows.
  if (prefillBills.length && !isView && !loadingBills && rows.length > 0) {
    const wanted = new Set(prefillBills.map(String))
    setPrefillBills([])
    setAmounts(Object.fromEntries(
      rows.filter((r) => wanted.has(String(r.bill_id)) && toCents(r.balance) > 0).map((r) => [r.bill_id, r.balance]),
    ))
  }

  // View: bills this payment touched that still owe something now.
  const balanceBillIds = isView
    ? (payment?.allocations ?? []).filter((a) => toCents(a.balance) > 0).map((a) => a.bill_id)
    : []

  const dueTotal = rows.reduce((s, r) => s + toCents(r.balance), 0)
  const payTotal = rows.reduce((s, r) => s + toCents(amounts[r.bill_id]), 0)

  // Full Payment: every row pays its full balance. Kept derived, not copied,
  // so a reload of the open bills can never leave the two out of step.
  const effectiveAmount = (r) => (fullPayment ? r.balance : amounts[r.bill_id] ?? '')

  const shownPayTotal = fullPayment ? dueTotal : payTotal

  // ── Tick to pay ────────────────────────────────────────────────────────────
  // A ticked bill is simply one with an amount: ticking fills its full balance
  // (still editable down for a part payment), unticking clears it. No separate
  // "selected" state, so ticks and typed amounts can never disagree.
  const selectedCount = rows.filter((r) => toCents(effectiveAmount(r)) > 0).length
  const allSelected = rows.length > 0 && selectedCount === rows.length

  const toggleRow = (r, on) => {
    setPercentOn(false)
    setPercent('')
    if (fullPayment) {
      // Leaving Full Payment: every other bill keeps its full balance.
      setFullPayment(false)
      setTotalInput(null)
      setRowErrors({})
      setAmounts(Object.fromEntries(rows.filter((x) => x.bill_id !== r.bill_id).map((x) => [x.bill_id, x.balance])))
      return
    }
    setRowAmount(r.bill_id, on ? r.balance : '')
  }

  const toggleAll = (on) => {
    setFullPayment(false)
    setPercentOn(false)
    setPercent('')
    setTotalInput(null)
    setRowErrors({})
    setAmounts(on ? Object.fromEntries(rows.map((r) => [r.bill_id, r.balance])) : {})
  }

  /** Spread a total over the rows, oldest due first. */
  const spread = (cents) => {
    let left = Math.max(0, Math.min(cents, dueTotal))
    const next = {}
    rows.forEach((r) => {
      const due = toCents(r.balance)
      const take = Math.min(due, left)
      if (take > 0) next[r.bill_id] = fromCents(take)
      left -= take
    })
    setAmounts(next)
  }

  const applyPercent = (pct) => {
    const p = Math.min(100, Math.max(0, Number(pct) || 0))
    const next = {}
    rows.forEach((r) => {
      const c = Math.round(toCents(r.balance) * p / 100)
      if (c > 0) next[r.bill_id] = fromCents(c)
    })
    setAmounts(next)
  }

  const setRowAmount = (billId, value) => {
    setFullPayment(false)
    setTotalInput(null)
    setAmounts((a) => ({ ...a, [billId]: value.replace(/[^\d.]/g, '') }))
    setRowErrors((e) => ({ ...e, [billId]: undefined }))
  }

  const resetRows = () => {
    setAmounts({})
    setFullPayment(false)
    setPercentOn(false)
    setPercent('')
    setTotalInput(null)
    setRowErrors({})
  }

  // ── Pay from ───────────────────────────────────────────────────────────────
  // The mode's TYPE (served as pays_from / is_cheque / needs_reference) decides
  // which accounts are offered and which extra fields are needed, so a mode
  // the client adds in Master Data ("CEFT") works with no code change.
  const paymentModes = useMemo(() => options.payment_modes ?? [], [options.payment_modes])
  const selectedMode = paymentModes.find((m) => String(m.id) === String(form.payment_mode_id))
    ?? (isView && payment ? { id: payment.payment_mode_id, name: payment.payment_mode_name, pays_from: payment.pay_from_type } : null)
  const isCheque = isView ? Boolean(payment?.cheque_no) : Boolean(selectedMode?.is_cheque)
  const needsReference = Boolean(selectedMode?.needs_reference)
  const paysFromBank = selectedMode?.pays_from === 'bank'

  const paysFrom = selectedMode?.pays_from ?? null
  const accountsForMode = useMemo(() => (options.pay_from_accounts ?? [])
    .filter((a) => paysFrom && a.cash_book_type === paysFrom)
    .filter((a) => !a.company_id || !form.company_id || String(a.company_id) === String(form.company_id)),
  [options.pay_from_accounts, paysFrom, form.company_id])

  const selectedAccount = (options.pay_from_accounts ?? []).find((a) => String(a.id) === String(form.pay_from_account_id))

  const pickAccount = (accountId) => {
    setForm((f) => ({ ...f, pay_from_account_id: accountId }))
    setErrors((e) => ({ ...e, pay_from_account_id: undefined }))
  }

  // A new mode keeps the account only if it suits (cash book vs bank).
  const pickMode = (modeId) => {
    const mode = paymentModes.find((m) => String(m.id) === String(modeId))
    setForm((f) => {
      const acc = (options.pay_from_accounts ?? []).find((a) => String(a.id) === String(f.pay_from_account_id))
      return {
        ...f,
        payment_mode_id: modeId,
        pay_from_account_id: acc && mode && acc.cash_book_type === mode.pays_from ? f.pay_from_account_id : '',
        cheque_no: mode?.is_cheque ? f.cheque_no : '',
        cheque_date: mode?.is_cheque ? f.cheque_date : '',
      }
    })
    setErrors((e) => ({ ...e, payment_mode_id: undefined, pay_from_account_id: undefined, reference_no: undefined }))
  }

  // Smart defaults: Cash first (unless Cash was just used for this balance),
  // then the only suitable account.
  if (isCreate && !form.payment_mode_id && paymentModes.length) {
    const others = paymentModes.filter((m) => String(m.id) !== String(avoidModeId))
    const pool = others.length ? others : paymentModes
    const mode = pool.find((m) => m.type === 'cash') ?? pool[0]
    setForm((f) => ({ ...f, payment_mode_id: String(mode.id) }))
  }
  if (!isView && !form.pay_from_account_id && accountsForMode.length === 1) {
    setForm((f) => ({ ...f, pay_from_account_id: String(accountsForMode[0].id) }))
  }

  // ── Validation (on blur, and again on save) ────────────────────────────────
  const validate = (f) => {
    const e = {}
    if (!f.payment_date) e.payment_date = 'Payment date is required.'
    else if (f.payment_date > today()) e.payment_date = 'The payment date cannot be in the future.'
    else if (lockDate && f.payment_date <= lockDate) e.payment_date = `Period closed up to ${lockDate}. Choose a later date.`
    if (!f.supplier_id) e.supplier_id = 'Select the vendor.'
    if (!f.company_id) e.company_id = 'Select the company.'
    const mode = paymentModes.find((m) => String(m.id) === String(f.payment_mode_id))
    if (!f.payment_mode_id) e.payment_mode_id = 'Select the payment mode.'
    if (!f.pay_from_account_id) e.pay_from_account_id = mode?.pays_from === 'bank' ? 'Select the bank account.' : 'Select the cash book.'
    if (mode?.is_cheque) {
      if (!f.cheque_no.trim()) e.cheque_no = 'Enter the cheque number.'
      else if (!/^\d{6}$/.test(f.cheque_no.trim())) e.cheque_no = 'The cheque number must be exactly 6 digits.'
      if (!f.cheque_date) e.cheque_date = 'Enter the cheque date.'
    }
    if (mode?.needs_reference && !f.reference_no.trim()) e.reference_no = `Enter the bank reference for ${mode.name}.`
    return e
  }

  const validateRows = () => {
    const e = {}
    rows.forEach((r) => {
      const raw = String(effectiveAmount(r) ?? '').trim()
      if (raw === '' || Number(raw) === 0) return
      if (!/^\d+(\.\d{1,2})?$/.test(raw)) e[r.bill_id] = 'Max 2 decimals.'
      else if (toCents(raw) > toCents(r.balance)) e[r.bill_id] = `Max ${fmt(toCents(r.balance))}.`
    })
    return e
  }

  const blur = (field) => () => {
    setTouched((t) => ({ ...t, [field]: true }))
    const e = validate(form)
    setErrors((prev) => ({ ...prev, [field]: e[field] }))
  }

  const err = (field) => (touched[field] || errors.__submitted) && errors[field]

  const setValue = (field, value) => {
    setForm((f) => ({ ...f, [field]: value }))
    if (errors[field]) setErrors((prev) => ({ ...prev, [field]: undefined }))
  }

  // ── Saving ─────────────────────────────────────────────────────────────────
  const sentRef = useRef([])

  const payload = () => {
    const allocations = rows
      .map((r) => ({ bill_id: r.bill_id, amount: String(effectiveAmount(r) ?? '').trim() }))
      .filter((a) => a.amount !== '' && toCents(a.amount) > 0)
      .map((a) => ({ ...a, amount: fromCents(toCents(a.amount)) }))
    sentRef.current = allocations
    return {
      request_id: isCreate ? requestIdRef.current : undefined,
      supplier_id: Number(form.supplier_id),
      company_id: Number(form.company_id),
      payment_date: form.payment_date,
      reference_no: form.reference_no.trim() || null,
      pay_from_account_id: Number(form.pay_from_account_id),
      payment_mode_id: Number(form.payment_mode_id),
      cheque_no: isCheque ? form.cheque_no.trim() : null,
      cheque_date: isCheque ? form.cheque_date : null,
      description: form.description.trim() || null,
      allocations,
      updated_at: isCreate ? undefined : payment?.updated_at,
    }
  }

  /** Server errors: allocations.N.* lands on the row it came from. */
  const applyServerErrors = (serverErrors = {}) => {
    const fieldErrs = { __submitted: true }
    const rowErrs = {}
    Object.entries(serverErrors).forEach(([key, msgs]) => {
      const msg = Array.isArray(msgs) ? msgs[0] : String(msgs)
      const m = key.match(/^allocations\.(\d+)\./)
      if (m && sentRef.current[Number(m[1])]) rowErrs[sentRef.current[Number(m[1])].bill_id] = msg
      else fieldErrs[key === 'allocations' ? 'allocations' : key] = msg
    })
    setErrors(fieldErrs)
    setRowErrors(rowErrs)
  }

  const resetForNew = () => {
    requestIdRef.current = newRequestId()
    setForm({
      ...emptyForm(companies.length === 1 ? String(companies[0].id) : form.company_id),
      payment_mode_id: form.payment_mode_id,
    })
    resetRows()
    setErrors({})
    setTouched({})
    setPendingFiles([])
    qc.invalidateQueries({ queryKey: ['bill-payment-next-no'] })
    firstFieldRef.current?.focus()
  }

  /**
   * One payment = one mode (one cash/bank movement). A split such as 30,000
   * cash + 70,000 cheque is two payments; this finds what the first one left
   * owing on the bills it touched, so the second can be offered at once.
   */
  const balanceLeft = (allocations) => allocations
    .map((a) => {
      const row = rows.find((r) => String(r.bill_id) === String(a.bill_id))
      return { bill_id: a.bill_id, left: row ? toCents(row.balance) - toCents(a.amount) : 0 }
    })
    .filter((b) => b.left > 0)

  /** Same vendor, company, date and bills; a fresh document for the next mode. */
  const startBalancePayment = (billIds) => {
    requestIdRef.current = newRequestId()
    setAvoidModeId(form.payment_mode_id)
    setForm({
      ...emptyForm(form.company_id),
      supplier_id: form.supplier_id,
      payment_date: form.payment_date,
    })
    resetRows()
    setErrors({})
    setTouched({})
    setPendingFiles([])
    setPrefillBills(billIds.map(String))
    qc.invalidateQueries({ queryKey: ['bill-payment-next-no'] })
  }

  /** action: 'close' | 'new' | 'draft' */
  const save = async (action) => {
    if (saving) return
    setMenuOpen(false)
    const e = validate(form)
    const re = validateRows()
    const body = payload()
    if (body.allocations.length === 0) e.allocations = 'Enter a payment amount against at least one bill.'
    if (Object.keys(e).length || Object.keys(re).length) {
      setErrors({ ...e, __submitted: true })
      setRowErrors(re)
      showError(e.allocations ?? 'Please fix the highlighted fields.')
      return
    }

    const willConfirm = action !== 'draft' && can('confirm_bill_payments')
    if (willConfirm) {
      const ok = await confirmAction({
        title: 'Pay now?',
        message: `Pay <strong>${fmt(toCents(body.allocations.reduce((s, a) => s + Number(a.amount), 0)))}</strong> by <strong>${selectedMode?.name ?? 'the selected mode'}</strong> from <strong>${selectedAccount ? `${selectedAccount.code} - ${selectedAccount.name}` : 'the selected account'}</strong> against ${body.allocations.length} bill(s)? This posts to the ledger.`,
        confirmText: 'Yes, Pay',
      })
      if (!ok) return
    }

    setSaving(true)
    try {
      const saved = isCreate ? await createBillPayment(body) : await updateBillPayment(id, body)

      for (const file of pendingFiles) {
        try { await uploadAttachment('bill_payment', saved.id, file) } catch (upErr) {
          showWarning(`${file.name}: ${firstError(upErr, 'could not be attached.')}`)
        }
      }

      let confirmed = false
      if (willConfirm) {
        try {
          await confirmBillPayment(saved.id)
          confirmed = true
        } catch (cErr) {
          showWarning(`${saved.payment_no} saved as draft but not paid: ${firstError(cErr, 'confirmation failed.')}`)
        }
      }

      const left = confirmed ? balanceLeft(body.allocations) : []
      if (confirmed && left.length === 0) showSuccess(`${saved.payment_no} paid and posted.`)
      else if (action === 'draft') showSuccess(`${saved.payment_no} saved as draft.`)

      qc.invalidateQueries({ queryKey: ['bill-payments'] })
      qc.invalidateQueries({ queryKey: ['bill-payment', String(saved.id)] })
      qc.invalidateQueries({ queryKey: ['bills'] })
      qc.invalidateQueries({ queryKey: ['bill'] })
      qc.invalidateQueries({ queryKey: ['journal-entries'] })
      // Awaited: a balance payment must prefill from the bills as they are now.
      await qc.invalidateQueries({ queryKey: ['open-bills'] })

      if (left.length > 0) {
        const payBalance = await confirmAction({
          title: `${saved.payment_no} paid and posted`,
          message: `<strong>${fmt(left.reduce((s, b) => s + b.left, 0))}</strong> is still due on ${left.length} of these bill(s). Pay the balance now with another payment mode?`,
          confirmText: 'Yes, Pay Balance',
          cancelText: 'Not Now',
          icon: 'success',
        })
        if (payBalance) {
          const ids = left.map((b) => b.bill_id)
          if (isCreate) startBalancePayment(ids)
          else navigate(`/finance/bill-payments/create?supplier_id=${form.supplier_id}&company_id=${form.company_id}&bill_ids=${ids.join(',')}&after_mode=${form.payment_mode_id}`)
          return
        }
      }

      if (action === 'new' && isCreate) resetForNew()
      else navigate('/finance/bill-payments')
    } catch (sErr) {
      if (sErr?.response?.status === 409) {
        showError('This payment was changed by someone else. Reload it and try again.')
      } else if (sErr?.response?.status === 422) {
        applyServerErrors(sErr.response.data?.errors)
        showError(firstError(sErr, 'Please fix the highlighted fields.'))
      } else {
        showError(firstError(sErr, 'Could not save the payment.'))
      }
    } finally {
      setSaving(false)
    }
  }

  const clearForm = async () => {
    if (isView) return
    if (isCreate) { resetForNew(); return }
    if (payment) {
      const ok = await confirmAction({ title: 'Discard changes?', message: 'Reload the saved payment and lose your edits.', confirmText: 'Discard', confirmColor: '#ef4444' })
      if (ok) { setLoadedVersion(null); qc.invalidateQueries({ queryKey: ['bill-payment', id] }) }
    }
  }

  // ── Actions on a saved payment (view mode) ─────────────────────────────────
  const refreshPayment = () => {
    qc.invalidateQueries({ queryKey: ['bill-payment', id] })
    qc.invalidateQueries({ queryKey: ['bill-payment-history', id] })
    qc.invalidateQueries({ queryKey: ['bill-payments'] })
    qc.invalidateQueries({ queryKey: ['bills'] })
    qc.invalidateQueries({ queryKey: ['bill'] })
    qc.invalidateQueries({ queryKey: ['open-bills'] })
    qc.invalidateQueries({ queryKey: ['journal-entries'] })
  }

  const confirmMutation = useMutation({
    mutationFn: () => confirmBillPayment(id),
    onSuccess: (p) => { refreshPayment(); showSuccess(`${p?.payment_no} paid and posted.`) },
    onError: (e) => showError(firstError(e, 'Could not confirm this payment.')),
  })
  const voidMutation = useMutation({
    mutationFn: (body) => voidBillPayment(id, body),
    onSuccess: (p) => { refreshPayment(); showSuccess(`${p?.payment_no} voided. Journal reversed and bills reopened.`) },
    onError: (e) => showError(firstError(e, 'Could not void this payment.')),
  })
  const deleteMutation = useMutation({
    mutationFn: () => deleteBillPayment(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['bill-payments'] }); showSuccess('Draft payment deleted.'); navigate('/finance/bill-payments') },
    onError: (e) => showError(firstError(e, 'Only draft payments can be deleted.')),
  })

  const handleConfirm = async () => {
    const ok = await confirmAction({
      title: `Confirm ${payment.payment_no}?`,
      message: `This pays <strong>${fmt(toCents(payment.total_amount))}</strong> from <strong>${payment.pay_from_account_name}</strong> and posts it to the ledger. Once confirmed it can only be reversed by a Void.`,
      confirmText: 'Yes, Pay',
    })
    if (ok) confirmMutation.mutate()
  }
  const handleVoid = async () => {
    const result = await confirmWithReasonAndDate({
      title: `Void ${payment.payment_no}?`, documentDate: payment.payment_date, lockDate, confirmText: 'Yes, Void Payment',
    })
    if (result) voidMutation.mutate(result)
  }
  const handleDelete = async () => {
    if (await confirmDelete(payment.payment_no)) deleteMutation.mutate()
  }

  // ── Attachments ────────────────────────────────────────────────────────────
  const uploadMutation = useMutation({
    mutationFn: (file) => uploadAttachment('bill_payment', id, file),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bill-payment-attachments', id] })
      qc.invalidateQueries({ queryKey: ['bill-payment-history', id] })
      showSuccess('File attached.')
    },
    onError: (e) => showError(firstError(e, 'Could not attach the file.')),
  })
  const removeMutation = useMutation({
    mutationFn: deleteAttachment,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bill-payment-attachments', id] })
      qc.invalidateQueries({ queryKey: ['bill-payment-history', id] })
      showSuccess('File removed.')
    },
    onError: (e) => showError(firstError(e, 'Could not remove the file.')),
  })

  const pickFiles = (e) => {
    const files = [...(e.target.files ?? [])]
    e.target.value = ''
    const ok = files.filter((f) => {
      if (!ALLOWED_TYPES.includes(f.type)) { showError(`${f.name}: only PDF, JPG or PNG.`); return false }
      if (f.size > MAX_FILE_BYTES) { showError(`${f.name}: must be smaller than 5 MB.`); return false }
      return true
    })
    if (isCreate || mode === 'edit') setPendingFiles((p) => [...p, ...ok].slice(0, 10))
    else ok.forEach((f) => uploadMutation.mutate(f))
  }

  const removeExisting = async (a) => {
    if (await confirmDelete(a.original_name)) removeMutation.mutate(a.id)
  }

  // ── Keyboard ───────────────────────────────────────────────────────────────
  const saveRef = useRef(save)
  const clearRef = useRef(clearForm)
  useEffect(() => { saveRef.current = save; clearRef.current = clearForm })
  useEffect(() => {
    const handler = (e) => {
      const popup = document.querySelector('.swal2-popup')
      if (popup && !popup.classList.contains('swal2-toast')) return
      if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 's') {
        e.preventDefault()
        if (!isView) saveRef.current(can('confirm_bill_payments') ? 'close' : 'draft')
      } else if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key.toLowerCase() === 's') {
        e.preventDefault()
        if (!isView) saveRef.current('draft')
      } else if (e.altKey && e.key.toLowerCase() === 'c') {
        e.preventDefault()
        clearRef.current()
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [isView, can])

  /** Enter in a row's amount moves to the next row. */
  const rowKeyDown = (i) => (e) => {
    if (e.key !== 'Enter') return
    e.preventDefault()
    rowInputs.current[i + 1]?.focus()
  }

  // ── Render ─────────────────────────────────────────────────────────────────
  if (!isCreate && loadingPayment) {
    return (
      <div className="flex items-center justify-center py-20 text-sm text-slate-400">
        <RefreshCw size={14} className="mr-2 animate-spin" /> Loading…
      </div>
    )
  }
  if (!isCreate && paymentError) {
    return <div className="py-20 text-center text-sm text-red-500">Payment not found, or you do not have access to it.</div>
  }

  const readOnly = isView || saving
  const title = isCreate ? 'Create Bill Payment' : isView ? `Bill Payment ${payment?.payment_no ?? ''}` : `Edit Bill Payment ${payment?.payment_no ?? ''}`
  const crumbs = [
    { label: 'Finance' },
    { label: 'Bill Payments', to: '/finance/bill-payments' },
    { label: isCreate ? 'Create Payment' : isView ? payment?.payment_no : 'Edit Payment' },
  ]
  const canConfirm = can('confirm_bill_payments')
  const primaryLabel = isCreate ? (canConfirm ? 'Create & Close' : 'Save as Draft') : (canConfirm ? 'Save & Pay' : 'Save Draft')
  const primaryAction = canConfirm ? 'close' : 'draft'
  const supplierOptions = suppliers.map((s) => ({ value: s.id, label: s.name }))
  const accountOptions = accountsForMode.map((a) => ({ value: a.id, label: `${a.code} - ${a.name}` }))
  const readyForBills = Boolean(form.supplier_id) && Boolean(form.company_id)

  return (
    <div className="w-full pb-16">
      <div className="mb-2 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{title}</h1>
          <Breadcrumb crumbs={crumbs} />
        </div>
        {payment && (
          <span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${
            payment.status === 'confirmed' ? 'bg-green-100 text-green-700' : payment.status === 'voided' ? 'bg-slate-200 text-slate-500' : 'bg-amber-100 text-amber-700'
          }`}>{payment.status_label}</span>
        )}
      </div>

      <div className="space-y-2">
        {/* ── Payment details ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 rounded-t-lg border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
            <Wallet size={13} />
            <h2 className="text-xs font-bold">Payment Details</h2>
            {lockDate && !isView && (
              <span className="ml-auto text-[10px] font-semibold text-amber-600">Period closed up to {lockDate}</span>
            )}
          </div>

          <div className="grid grid-cols-1 gap-x-3 gap-y-2 p-2.5 md:grid-cols-2 lg:grid-cols-4">
            <div>
              <label className={LABEL_CLS}>Payment Date <Req /></label>
              <input
                ref={firstFieldRef}
                type="date"
                className={inputCls(err('payment_date'))}
                value={form.payment_date}
                max={today()}
                onChange={(e) => setValue('payment_date', e.target.value)}
                onBlur={blur('payment_date')}
                disabled={readOnly}
              />
              <FieldError msg={err('payment_date') ? errors.payment_date : null} />
            </div>

            <div>
              <label className={LABEL_CLS}>Document No <span className="ml-1 text-[10px] font-medium normal-case text-indigo-400">auto</span></label>
              <input readOnly tabIndex={-1} className={INPUT_DISABLED_CLS} value={isCreate ? nextNo : (payment?.payment_no ?? '')} placeholder="Automatically filled" />
            </div>

            <div>
              <label className={LABEL_CLS}>Reference No {needsReference && <Req />} <span className="ml-1 text-[10px] font-medium normal-case text-slate-400">bank ref / receipt</span></label>
              <input
                className={inputCls(err('reference_no'))}
                value={form.reference_no}
                maxLength={100}
                placeholder="Type"
                onChange={(e) => setValue('reference_no', e.target.value)}
                onBlur={blur('reference_no')}
                disabled={readOnly}
              />
              <FieldError msg={err('reference_no') ? errors.reference_no : null} />
            </div>

            <div>
              <label className={LABEL_CLS}>Company <Req /></label>
              <select
                className={selectCls(err('company_id'))}
                value={form.company_id}
                onChange={(e) => { setValue('company_id', e.target.value); setValue('pay_from_account_id', ''); resetRows() }}
                onBlur={blur('company_id')}
                disabled={readOnly || (isCreate && companies.length === 1)}
              >
                <option value="">Select</option>
                {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
              <FieldError msg={err('company_id') ? errors.company_id : null} />
            </div>

            <div className="md:col-span-2">
              <label className={LABEL_CLS}>Vendor <Req /></label>
              <div tabIndex={-1} onBlur={blur('supplier_id')}>
                <FilterSearchSelect
                  size="form"
                  value={form.supplier_id}
                  onChange={(v) => { setValue('supplier_id', v); resetRows() }}
                  options={supplierOptions}
                  placeholder="Select vendor"
                  invalid={Boolean(err('supplier_id'))}
                  clearable={false}
                  disabled={readOnly}
                />
              </div>
              <FieldError msg={err('supplier_id') ? errors.supplier_id : null} />
            </div>
          </div>
        </div>

        {/* ── Bills being paid ── */}
        <div className={`overflow-hidden rounded-lg border bg-white shadow-sm ${errors.allocations ? 'border-red-300' : 'border-slate-200'}`}>
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-3 py-1.5">
            <div className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
              <ListChecks size={12} /> {isView ? 'Bills Paid' : 'Open Bills'}
              {!isView && <span className="font-normal text-slate-400">oldest due first</span>}
            </div>
            {loadingBills && <RefreshCw size={12} className="animate-spin text-slate-400" />}
            {!isView && rows.length > 0 && (
              <span className={`rounded px-1.5 py-0.5 text-[11px] font-semibold ${selectedCount ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500'}`}>
                Selected: {selectedCount} of {rows.length} bill(s) · {fmt(shownPayTotal)}
              </span>
            )}
            {errors.allocations &&<span className="text-[11px] font-semibold text-red-600">{errors.allocations}</span>}
          </div>

          <div className="max-h-[45vh] overflow-auto">
            <table className="w-full text-xs">
              <thead className="sticky top-0 z-10 bg-slate-50">
                <tr className="border-b border-slate-200 text-left">
                  {!isView && (
                    <th className="w-7 px-2 py-1.5">
                      <input
                        type="checkbox"
                        className="h-3.5 w-3.5 cursor-pointer accent-indigo-600 disabled:cursor-not-allowed"
                        checked={allSelected}
                        ref={(el) => { if (el) el.indeterminate = selectedCount > 0 && !allSelected }}
                        disabled={readOnly || rows.length === 0}
                        onChange={(e) => toggleAll(e.target.checked)}
                        title="Pay all bills in full / clear all"
                      />
                    </th>
                  )}
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Bill No</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Inv No</th>
                  {!isView && <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Ledger Account</th>}
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Due Date</th>
                  {!isView && <th className="px-2 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500" title="Days past due (negative = days left)">Days</th>}
                  <th className="px-2 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Due Amount</th>
                  <th className="w-36 px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">
                    <div className="flex items-center justify-end gap-1">
                      <span>Payment</span>
                      {!isView && (
                        <label className="flex items-center gap-0.5 rounded border border-slate-300 bg-white px-1 normal-case" title="Pay the same % of every bill">
                          <input
                            type="checkbox"
                            className="h-3 w-3 accent-indigo-600"
                            checked={percentOn}
                            disabled={readOnly || rows.length === 0}
                            onChange={(e) => { setPercentOn(e.target.checked); setFullPayment(false); setTotalInput(null); if (!e.target.checked) setPercent('') }}
                          />
                          <input
                            className="w-8 border-0 p-0 text-right text-[11px] focus:outline-none disabled:bg-white"
                            value={percent}
                            disabled={!percentOn || readOnly}
                            placeholder="%"
                            onChange={(e) => { const v = e.target.value.replace(/[^\d.]/g, ''); setPercent(v); applyPercent(v) }}
                          />
                        </label>
                      )}
                    </div>
                  </th>
                  <th className="px-2 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Remaining</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={11} className="px-3 py-6 text-center text-slate-400">
                      {isView ? 'No bills.' : !readyForBills ? 'Select the Vendor and Company to load their open bills.' : loadingBills ? 'Loading…' : 'This vendor has no open bills in this company.'}
                    </td>
                  </tr>
                )}
                {rows.map((r, i) => {
                  const dueC = toCents(r.balance)
                  const amount = isView ? (payment.allocations[i]?.amount ?? '') : effectiveAmount(r)
                  const payC = toCents(amount)
                  return (
                    <tr key={r.bill_id} className={`hover:bg-slate-50 ${payC > 0 ? 'bg-indigo-50/40' : ''}`}>
                      {!isView && (
                        <td className="px-2 py-1">
                          <input
                            type="checkbox"
                            className="h-3.5 w-3.5 cursor-pointer accent-indigo-600 disabled:cursor-not-allowed"
                            checked={payC > 0}
                            disabled={readOnly || dueC <= 0}
                            onChange={(e) => toggleRow(r, e.target.checked)}
                            title={payC > 0 ? 'Untick to not pay this bill' : 'Tick to pay this bill in full'}
                          />
                        </td>
                      )}
                      <td className="whitespace-nowrap px-2 py-1 font-mono font-medium text-indigo-600">
                        <Link to={`/finance/bills/${r.bill_id}`} className="hover:underline" tabIndex={-1}>{r.bill_no}</Link>
                      </td>
                      <td className="whitespace-nowrap px-2 py-1 text-slate-500">{r.transaction_date}</td>
                      <td className="max-w-[110px] truncate px-2 py-1 font-mono text-slate-600" title={r.document_no}>{r.document_no}</td>
                      {!isView && <td className="max-w-[180px] truncate px-2 py-1 text-slate-600" title={r.account ?? ''}>{r.account}</td>}
                      <td className="whitespace-nowrap px-2 py-1 text-slate-500">{r.due_date}</td>
                      {!isView && (
                        <td className={`px-2 py-1 text-right font-semibold ${r.days_overdue > 0 ? 'text-red-600' : 'text-slate-400'}`}>{r.days_overdue}</td>
                      )}
                      <td className="whitespace-nowrap px-2 py-1 text-right">
                        <span className="inline-block rounded bg-slate-100 px-2 py-0.5 font-medium text-slate-700">{fmt(dueC)}</span>
                      </td>
                      <td className="px-2 py-1">
                        {isView ? (
                          <div className="text-right font-semibold text-slate-800">{fmt(payC)}</div>
                        ) : (
                          <>
                            <input
                              ref={(el) => { rowInputs.current[i] = el }}
                              inputMode="decimal"
                              className={`${inputCls(rowErrors[r.bill_id])} text-right font-semibold`}
                              value={amount}
                              placeholder="0.00"
                              onChange={(e) => { setPercentOn(false); setPercent(''); setRowAmount(r.bill_id, e.target.value) }}
                              onDoubleClick={() => setRowAmount(r.bill_id, r.balance)}
                              onKeyDown={rowKeyDown(i)}
                              title="Double-click to pay this bill in full"
                              disabled={readOnly}
                            />
                            <FieldError msg={rowErrors[r.bill_id]} />
                          </>
                        )}
                      </td>
                      <td className={`whitespace-nowrap px-2 py-1 text-right font-medium ${dueC - payC === 0 ? 'text-green-600' : 'text-slate-700'}`}>
                        {fmt(Math.max(0, dueC - payC))}
                      </td>
                      <td className="px-2 py-1"><RowStatus dueCents={dueC} payCents={payC} days={r.days_overdue} /></td>
                    </tr>
                  )
                })}
              </tbody>
              {rows.length > 0 && (
                <tfoot className="sticky bottom-0 bg-slate-50 font-bold text-slate-700">
                  <tr className="border-t border-slate-200">
                    <td colSpan={isView ? 4 : 7} className="px-2 py-1.5 text-right uppercase tracking-wider text-slate-500">Total</td>
                    <td className="px-2 py-1.5 text-right">{fmt(isView ? rows.reduce((s, r) => s + toCents(r.balance), 0) : dueTotal)}</td>
                    <td className="px-2 py-1.5 text-right">{fmt(isView ? toCents(payment.total_amount) : shownPayTotal)}</td>
                    <td className="px-2 py-1.5 text-right">{fmt(Math.max(0, (isView ? rows.reduce((s, r) => s + toCents(r.balance), 0) - toCents(payment.total_amount) : dueTotal - shownPayTotal)))}</td>
                    <td />
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        </div>

        {/* ── Summary + Pay from, side by side ── */}
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700">
              <ListChecks size={13} /> Payment Summary
            </div>
            <div className="space-y-2 p-2.5">
              {!isView && (
                <div className="flex items-center gap-5 text-xs font-semibold text-slate-700">
                  <label className="flex cursor-pointer items-center gap-1.5">
                    <input type="radio" className="accent-indigo-600" checked={!fullPayment} disabled={readOnly} onChange={() => setFullPayment(false)} />
                    Partial Payment
                  </label>
                  <label className="flex cursor-pointer items-center gap-1.5">
                    <input
                      type="radio"
                      className="accent-indigo-600"
                      checked={fullPayment}
                      disabled={readOnly || rows.length === 0}
                      onChange={() => { setFullPayment(true); setPercentOn(false); setPercent(''); setTotalInput(null); setRowErrors({}) }}
                    />
                    Full Payment
                  </label>
                </div>
              )}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className={LABEL_CLS}>Payment Amount <Req /> {!isView && !fullPayment && <span className="ml-1 text-[10px] font-medium normal-case text-indigo-400">spreads oldest first</span>}</label>
                  <input
                    inputMode="decimal"
                    className={`${fullPayment || isView ? INPUT_DISABLED_CLS : inputCls(errors.allocations)} text-right font-bold`}
                    value={isView ? fmt(toCents(payment.total_amount)) : totalInput ?? (shownPayTotal ? fromCents(shownPayTotal) : '')}
                    placeholder="0.00"
                    readOnly={fullPayment || isView}
                    disabled={readOnly || rows.length === 0}
                    onChange={(e) => {
                      const v = e.target.value.replace(/[^\d.]/g, '')
                      setTotalInput(v)
                      setPercentOn(false)
                      setPercent('')
                      setRowErrors({})
                      spread(toCents(v))
                    }}
                    onBlur={() => setTotalInput(null)}
                  />
                  {!isView && toCents(totalInput ?? 0) > dueTotal && (
                    <FieldError msg={`Cannot pay more than the ${fmt(dueTotal)} owed. Advances are not supported yet.`} />
                  )}
                </div>
                <div>
                  <label className={LABEL_CLS}>Remaining Amount</label>
                  <input
                    readOnly
                    tabIndex={-1}
                    className={`${INPUT_DISABLED_CLS} text-right font-bold`}
                    value={isView ? '' : fmt(Math.max(0, dueTotal - shownPayTotal))}
                    placeholder={isView ? '—' : '0.00'}
                  />
                </div>
              </div>
            </div>
          </div>

          <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-1.5 rounded-t-lg border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700">
              <Landmark size={13} /> Pay From
            </div>
            <div className="space-y-2 p-2.5">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className={LABEL_CLS}>Payment Mode <Req /></label>
                  <select
                    className={selectCls(err('payment_mode_id'))}
                    value={form.payment_mode_id}
                    onChange={(e) => pickMode(e.target.value)}
                    onBlur={blur('payment_mode_id')}
                    disabled={readOnly}
                  >
                    <option value="">Select</option>
                    {(isView && payment ? [{ id: payment.payment_mode_id, name: payment.payment_mode_name }] : paymentModes)
                      .map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                  </select>
                  <FieldError msg={err('payment_mode_id') ? errors.payment_mode_id : null} />
                </div>

                <div>
                  <label className={LABEL_CLS}>{paysFromBank ? 'Bank Account' : 'Cash Book'} <Req /></label>
                  <div tabIndex={-1} onBlur={blur('pay_from_account_id')}>
                    <FilterSearchSelect
                      size="form"
                      value={form.pay_from_account_id}
                      onChange={pickAccount}
                      options={isView && payment ? [{ value: payment.pay_from_account_id, label: payment.pay_from_account_name }] : accountOptions}
                      placeholder={!selectedMode && !isView ? 'Select the mode first' : (accountOptions.length || isView ? 'Select' : `No ${paysFromBank ? 'bank accounts' : 'cash books'} set up`)}
                      invalid={Boolean(err('pay_from_account_id'))}
                      clearable={false}
                      disabled={readOnly || (!selectedMode && !isView)}
                    />
                  </div>
                  <FieldError msg={err('pay_from_account_id') ? errors.pay_from_account_id : null} />
                </div>

                {isCheque && (
                  <>
                    <div>
                      <label className={LABEL_CLS}>Cheque No <Req /></label>
                      <input
                        className={inputCls(err('cheque_no'))}
                        value={form.cheque_no}
                        maxLength={6}
                        inputMode="numeric"
                        placeholder="6 digits"
                        onChange={(e) => setValue('cheque_no', e.target.value.replace(/\D/g, ''))}
                        onBlur={blur('cheque_no')}
                        disabled={readOnly}
                      />
                      <FieldError msg={err('cheque_no') ? errors.cheque_no : null} />
                    </div>
                    <div>
                      <label className={LABEL_CLS}>Cheque Date <Req /> <span className="ml-1 text-[10px] font-medium normal-case text-slate-400">may be post-dated</span></label>
                      <input
                        type="date"
                        className={inputCls(err('cheque_date'))}
                        value={form.cheque_date}
                        onChange={(e) => setValue('cheque_date', e.target.value)}
                        onBlur={blur('cheque_date')}
                        disabled={readOnly}
                      />
                      <FieldError msg={err('cheque_date') ? errors.cheque_date : null} />
                    </div>
                  </>
                )}
              </div>
            </div>
          </div>
        </div>

        {/* ── Description + footer ── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="p-2.5">
            <label className={LABEL_CLS}>Description</label>
            <textarea
              rows={2}
              className={textareaCls(err('description'))}
              value={form.description}
              maxLength={1000}
              placeholder="Type"
              onChange={(e) => setValue('description', e.target.value)}
              onBlur={blur('description')}
              disabled={readOnly}
            />
            <FieldError msg={err('description') ? errors.description : null} />
          </div>

          {!isView && (
            <div className="flex items-center justify-between border-t border-slate-100 px-3 py-2">
              <span className="text-[11px] text-slate-500"><span className="text-red-500">*</span>Required</span>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={clearForm}
                  disabled={saving}
                  className="rounded-md bg-red-600 px-5 py-1.5 text-xs font-bold uppercase tracking-wide text-white shadow-sm hover:bg-red-700 disabled:opacity-40"
                >
                  Clear
                </button>
                <div className="relative flex">
                  <button
                    type="button"
                    onClick={() => save(primaryAction)}
                    disabled={saving}
                    className="flex items-center gap-1.5 rounded-l-md bg-sky-500 px-4 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-sky-600 disabled:opacity-50"
                  >
                    {saving && <RefreshCw size={12} className="animate-spin" />}
                    {primaryLabel}
                  </button>
                  <button
                    type="button"
                    onClick={() => setMenuOpen((o) => !o)}
                    disabled={saving}
                    aria-label="More save options"
                    className="rounded-r-md border-l border-sky-400 bg-sky-500 px-2 py-1.5 text-white shadow-sm hover:bg-sky-600 disabled:opacity-50"
                  >
                    <ChevronDown size={14} />
                  </button>
                  {menuOpen && (
                    <div className="absolute bottom-full right-0 z-20 mb-1 w-44 overflow-hidden rounded-md border border-slate-200 bg-white text-xs shadow-lg">
                      {isCreate && canConfirm && (
                        <button type="button" onClick={() => save('new')} className="block w-full px-3 py-2 text-left hover:bg-slate-50">Create &amp; New</button>
                      )}
                      <button type="button" onClick={() => save('draft')} className="block w-full px-3 py-2 text-left hover:bg-slate-50">Save as Draft</button>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}

          {isView && payment && (
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 text-[11px] text-slate-500">
              <div className="flex flex-wrap gap-x-4 gap-y-1">
                <span>Paid: <strong className="text-slate-800"><Money value={payment.total_amount} /></strong></span>
                <span>By: <strong className="text-slate-800">{payment.payment_mode_name}</strong>{payment.cheque_no && <> · Cheque <span className="font-mono">{payment.cheque_no}</span> dated {payment.cheque_date}</>}</span>
                {payment.journal_no && <span>Journal: <Link to="/finance/journal-entries" className="font-mono text-indigo-600 hover:underline">{payment.journal_no}</Link></span>}
                {payment.reversal_journal_no && <span>Reversal: <span className="font-mono">{payment.reversal_journal_no}</span> on {payment.reversal_date}</span>}
                {payment.void_reason && <span className="text-red-600">Void reason: {payment.void_reason}</span>}
                {payment.created_by_name && <span>Entered by {payment.created_by_name}</span>}
              </div>
              <div className="flex items-center gap-1.5">
                {payment.status === 'draft' && can('edit_bill_payments') && (
                  <Link to={`/finance/bill-payments/${payment.id}/edit`} className="flex items-center gap-1 rounded-md bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700 hover:bg-amber-100">
                    <Edit2 size={12} /> Edit
                  </Link>
                )}
                {payment.status === 'draft' && canConfirm && (
                  <button type="button" onClick={handleConfirm} disabled={confirmMutation.isPending} className="flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-40">
                    <CheckCircle size={12} /> Confirm &amp; Pay
                  </button>
                )}
                {payment.status === 'draft' && can('delete_bill_payments') && (
                  <button type="button" onClick={handleDelete} disabled={deleteMutation.isPending} className="flex items-center gap-1 rounded-md bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100 disabled:opacity-40">
                    <Trash2 size={12} /> Delete
                  </button>
                )}
                {payment.status === 'confirmed' && can('create_bill_payments') && balanceBillIds.length > 0 && (
                  <Link
                    to={`/finance/bill-payments/create?supplier_id=${payment.supplier_id}&company_id=${payment.company_id}&bill_ids=${balanceBillIds.join(',')}&after_mode=${payment.payment_mode_id}`}
                    title="Pay what these bills still owe, with another payment mode"
                    className="flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-700"
                  >
                    <Wallet size={12} /> Pay Balance
                  </Link>
                )}
                {payment.status === 'confirmed' && can('void_bill_payments') && (
                  <button type="button" onClick={handleVoid} disabled={voidMutation.isPending} className="flex items-center gap-1 rounded-md bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-40">
                    <Ban size={12} /> Void
                  </button>
                )}
              </div>
            </div>
          )}
        </div>

        {/* ── Attachments + History side by side ── */}
        <div className={`grid grid-cols-1 gap-2 ${isView ? 'lg:grid-cols-2' : ''}`}>
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-3 py-1.5">
              <div className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
                <Paperclip size={12} /> Attachments <span className="font-normal text-slate-400">PDF, JPG, PNG · max 5 MB · up to 10</span>
              </div>
              {payment?.status !== 'voided' && (isView ? can('edit_bill_payments') : true) && (
                <>
                  <input ref={fileInputRef} type="file" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" className="hidden" onChange={pickFiles} />
                  <button type="button" onClick={() => fileInputRef.current?.click()} disabled={saving || uploadMutation.isPending} className="flex items-center gap-1 rounded-md border border-indigo-200 bg-white px-2 py-0.5 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50 disabled:opacity-40">
                    <Upload size={11} /> Upload
                  </button>
                </>
              )}
            </div>
            <ul className="divide-y divide-slate-100 text-xs">
              {attachments.map((a) => (
                <li key={a.id} className="flex items-center justify-between px-3 py-1.5">
                  <span className="truncate text-slate-700" title={a.original_name}>{a.original_name} <span className="text-slate-400">· {fmtSize(a.size)}</span></span>
                  <span className="flex shrink-0 items-center gap-1">
                    <button type="button" title="Download" onClick={() => downloadAttachment(a).catch(() => showError('Could not download the file.'))} className="rounded p-1 text-sky-600 hover:bg-sky-50"><Download size={13} /></button>
                    {payment?.status === 'draft' && can('edit_bill_payments') && (
                      <button type="button" title="Remove" onClick={() => removeExisting(a)} className="rounded p-1 text-red-600 hover:bg-red-50"><Trash2 size={13} /></button>
                    )}
                  </span>
                </li>
              ))}
              {pendingFiles.map((f, i) => (
                <li key={`p-${i}`} className="flex items-center justify-between px-3 py-1.5 text-slate-600">
                  <span className="truncate">{f.name} <span className="text-amber-600">· uploads on save</span></span>
                  <button type="button" title="Remove" onClick={() => setPendingFiles((p) => p.filter((_, j) => j !== i))} className="rounded p-1 text-red-600 hover:bg-red-50"><X size={13} /></button>
                </li>
              ))}
              {attachments.length === 0 && pendingFiles.length === 0 && (
                <li className="px-3 py-2 text-slate-400">No files attached.</li>
              )}
            </ul>
          </div>

          {isView && (
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">
                <History size={12} /> History
              </div>
              <ul className="max-h-48 divide-y divide-slate-100 overflow-auto text-xs">
                {history.map((h) => (
                  <li key={h.id} className="flex items-start justify-between gap-2 px-3 py-1.5">
                    <span>
                      <span className="font-semibold text-slate-700">{EVENT_LABELS[h.event] ?? h.event}</span>
                      {h.new_values?.void_reason && <span className="text-slate-500"> — {h.new_values.void_reason}</span>}
                      {h.event === 'updated' && h.new_values && (
                        <span className="text-slate-500"> — {Object.keys(h.new_values).join(', ')}</span>
                      )}
                      {h.new_values?.file && <span className="text-slate-500"> — {h.new_values.file}</span>}
                      {h.old_values?.file && <span className="text-slate-500"> — {h.old_values.file}</span>}
                    </span>
                    <span className="shrink-0 text-right text-slate-400">
                      {h.user_name ?? 'System'} · {h.created_at ? new Date(h.created_at).toLocaleString() : ''}
                    </span>
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
          { keys: ['Ctrl', 'S'], desc: canConfirm ? (isCreate ? 'Create & Close' : 'Save & Pay') : 'Save Draft' },
          { keys: ['Ctrl', 'Shift', 'S'], desc: 'Save as Draft' },
          { keys: ['Alt', 'C'], desc: 'Clear' },
          { keys: ['Enter'], desc: 'Next bill row (in Payment column)' },
          { keys: ['Double-click'], desc: 'Pay a bill in full' },
          { keys: ['Space'], desc: 'Tick / untick a bill (on its checkbox)' },
        ]} />
      )}
    </div>
  )
}
