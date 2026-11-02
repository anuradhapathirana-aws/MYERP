import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Ban, Banknote, CheckCircle, ChevronDown, Download, Edit2, History, Landmark, ListChecks, Paperclip,
  Printer, RefreshCw, Search, Trash2, Upload, X,
} from 'lucide-react'
import {
  confirmBankDeposit, createBankDeposit, deleteBankDeposit, downloadBankDepositPdf, getBankDeposit,
  getBankDepositHistory, getBankDepositOptions, getMoneyInHand, getNextBankDepositNo, updateBankDeposit,
  voidBankDeposit,
} from '../../api/bankDeposits'
import { deleteAttachment, downloadAttachment, getAttachments, uploadAttachment } from '../../api/financeAttachments'
import Breadcrumb from '../../components/Breadcrumb'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Money from '../../components/ui/Money'
import { usePermissions } from '../../hooks/usePermissions'
import {
  confirmAction, confirmDelete, confirmWithReasonAndDate, showError, showSuccess, showWarning,
} from '../../utils/alerts'
import { INPUT_DISABLED_CLS, LABEL_CLS, inputCls, selectCls, textareaCls } from '../../utils/fieldStyles'
import { printPdfBlob } from '../../utils/pdf'

const today = () => new Date().toLocaleDateString('en-CA')

const EMPTY_IN_HAND = { cash_accounts: [], cheques: [] }

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
  deposit_date: today(),
  company_id: companyId,
  bank_account_id: '',
  slip_no: '',
  cash_account_id: '',
  cash_amount: '',
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

/** Whether a cheque can go on today's slip; the server's reason when it cannot. */
function ChequeStatus({ cheque, selected }) {
  if (cheque.blocked) {
    const label = cheque.blocked.includes('post-dated') ? 'Post-dated' : cheque.blocked.includes('stale') ? 'Stale' : 'Not yet'
    return <span title={cheque.blocked} className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">{label}</span>
  }
  if (selected) return <span className="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-700">On slip</span>
  return <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">In hand</span>
}

const fmtSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

/**
 * Create / edit / view a Bank Deposit.
 *
 * Deposit Details (date, company, bank account, slip no) and the Deposit
 * Summary side by side; then the cash taken from a Cash Book, and the cheques
 * in hand to tick. Cheque amounts are never typed: each one is banked whole,
 * exactly as received. A post-dated or stale cheque is shown but cannot be
 * ticked; the server re-checks every rule on save and again on confirm.
 *
 * "Create" means save AND confirm (bank it and post). Save as Draft keeps it
 * editable; a draft already holds its cheques, so no other slip can take them.
 */
export default function BankDepositFormPage() {
  const { id } = useParams()
  const { pathname } = useLocation()
  const mode = !id ? 'create' : pathname.endsWith('/edit') ? 'edit' : 'view'
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { can } = usePermissions()

  const isCreate = mode === 'create'
  const isView = mode === 'view'

  const [form, setForm] = useState(() => emptyForm())
  const [selected, setSelected] = useState([]) // settlement ids of the cheques on the slip
  const [search, setSearch] = useState('')
  const [errors, setErrors] = useState({})
  const [rowErrors, setRowErrors] = useState({})
  const [touched, setTouched] = useState({})
  const [pendingFiles, setPendingFiles] = useState([])
  const [menuOpen, setMenuOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [pdfBusy, setPdfBusy] = useState(false)
  const requestIdRef = useRef(newRequestId())
  const fileInputRef = useRef(null)
  const firstFieldRef = useRef(null)

  // ── Lookups ────────────────────────────────────────────────────────────────
  const { data: options = {} } = useQuery({
    queryKey: ['bank-deposit-options'],
    queryFn: getBankDepositOptions,
    staleTime: 5 * 60 * 1000,
  })

  const { data: deposit, isLoading: loadingDeposit, isError: depositError } = useQuery({
    queryKey: ['bank-deposit', id],
    queryFn: () => getBankDeposit(id),
    enabled: !isCreate && Boolean(id),
  })

  const { data: inHandData, isFetching: loadingInHand } = useQuery({
    queryKey: ['bank-deposit-in-hand', form.company_id, form.deposit_date, id ?? null],
    queryFn: () => getMoneyInHand(form.company_id, form.deposit_date, id),
    enabled: !isView && Boolean(form.company_id) && Boolean(form.deposit_date),
  })

  const { data: attachments = [] } = useQuery({
    queryKey: ['bank-deposit-attachments', id],
    queryFn: () => getAttachments('bank_deposit', id),
    enabled: !isCreate && Boolean(id),
  })

  const { data: history = [] } = useQuery({
    queryKey: ['bank-deposit-history', id],
    queryFn: () => getBankDepositHistory(id),
    enabled: isView && Boolean(id),
  })

  const { data: nextNo = '' } = useQuery({
    queryKey: ['bank-deposit-next-no', form.deposit_date],
    queryFn: () => getNextBankDepositNo(form.deposit_date),
    enabled: isCreate && Boolean(form.deposit_date),
  })

  const inHand = inHandData ?? EMPTY_IN_HAND
  const companies = useMemo(() => options.companies ?? [], [options.companies])
  const lockDate = options.lock_date ?? null

  if (isCreate && !form.company_id && companies.length === 1) {
    setForm((f) => ({ ...f, company_id: String(companies[0].id) }))
  }

  // Load the deposit into the form for edit / view, once per saved version.
  const [loadedVersion, setLoadedVersion] = useState(null)
  if (deposit && loadedVersion !== deposit.updated_at) {
    setLoadedVersion(deposit.updated_at)
    setForm({
      deposit_date: deposit.deposit_date ?? '',
      company_id: String(deposit.company_id ?? ''),
      bank_account_id: String(deposit.bank_account_id ?? ''),
      slip_no: deposit.slip_no ?? '',
      cash_account_id: deposit.cash_account_id ? String(deposit.cash_account_id) : '',
      cash_amount: toCents(deposit.cash_total) > 0 ? String(deposit.cash_total) : '',
      description: deposit.description ?? '',
    })
    setSelected((deposit.cheques ?? []).map((c) => c.settlement_id))
  }

  useEffect(() => {
    if (mode === 'edit' && deposit && deposit.status !== 'draft') navigate(`/finance/bank-deposits/${deposit.id}`, { replace: true })
  }, [mode, deposit, navigate])

  useEffect(() => { firstFieldRef.current?.focus() }, [])

  // ── Accounts and cheques ───────────────────────────────────────────────────
  const bankAccounts = (options.bank_accounts ?? []).filter((a) => String(a.company_id) === String(form.company_id))
  const selectedBank = isView
    ? null
    : bankAccounts.find((a) => String(a.id) === String(form.bank_account_id))
  const cashAccounts = inHand.cash_accounts ?? []
  const cashAccount = cashAccounts.find((a) => String(a.id) === String(form.cash_account_id))

  // One bank account or one cash book for the company: pick it.
  if (!isView && !form.bank_account_id && bankAccounts.length === 1) {
    setForm((f) => ({ ...f, bank_account_id: String(bankAccounts[0].id) }))
  }
  if (!isView && !form.cash_account_id && cashAccounts.length === 1) {
    setForm((f) => ({ ...f, cash_account_id: String(cashAccounts[0].id) }))
  }

  const cheques = isView ? (deposit?.cheques ?? []) : (inHand.cheques ?? [])
  const term = search.trim().toLowerCase()
  const shownCheques = term === '' ? cheques : cheques.filter((c) => [c.cheque_no, c.receipt_no, c.customer_name, c.drawee_bank]
    .some((v) => String(v ?? '').toLowerCase().includes(term)))
  const selectable = shownCheques.filter((c) => !c.blocked)
  const isSelected = (c) => isView || selected.includes(c.settlement_id)
  const allSelected = selectable.length > 0 && selectable.every(isSelected)

  const chequeCents = isView
    ? toCents(deposit?.cheque_total)
    : cheques.filter((c) => selected.includes(c.settlement_id)).reduce((s, c) => s + toCents(c.amount), 0)
  const chequeCount = isView ? cheques.length : selected.length
  const cashCents = isView ? toCents(deposit?.cash_total) : toCents(form.cash_amount)
  const totalCents = cashCents + chequeCents

  const toggleCheque = (c, on) => {
    setSelected((s) => (on ? [...s, c.settlement_id] : s.filter((x) => x !== c.settlement_id)))
    setRowErrors((r) => ({ ...r, [c.settlement_id]: undefined }))
    if (errors.cheque_ids) setErrors((e) => ({ ...e, cheque_ids: undefined }))
  }

  const toggleAll = (on) => {
    const ids = selectable.map((c) => c.settlement_id)
    setSelected((s) => (on ? [...new Set([...s, ...ids])] : s.filter((x) => !ids.includes(x))))
    setRowErrors({})
  }

  // A changed company or date can make ticked cheques disappear or turn
  // post-dated; drop them rather than send ids the server will refuse.
  const offered = cheques.filter((c) => !c.blocked).map((c) => c.settlement_id)
  if (!isView && inHandData && !loadingInHand && selected.some((sid) => !offered.includes(sid))) {
    setSelected((s) => s.filter((sid) => offered.includes(sid)))
  }

  // ── Validation (on blur, and again on save) ────────────────────────────────
  const validate = (f) => {
    const e = {}
    if (!f.deposit_date) e.deposit_date = 'Deposit date is required.'
    else if (f.deposit_date > today()) e.deposit_date = 'The deposit date cannot be in the future.'
    else if (lockDate && f.deposit_date <= lockDate) e.deposit_date = `Period closed up to ${lockDate}. Choose a later date.`
    if (!f.company_id) e.company_id = 'Select the company.'
    if (!f.bank_account_id) e.bank_account_id = 'Select the bank account.'
    const cash = String(f.cash_amount ?? '').trim()
    if (cash !== '' && !/^\d+(\.\d{1,2})?$/.test(cash)) e.cash_amount = 'Enter an amount with at most 2 decimals.'
    else if (toCents(cash) > 0) {
      if (!f.cash_account_id) e.cash_account_id = 'Select the cash book the cash is taken from.'
      else if (cashAccount && toCents(cash) > toCents(cashAccount.available)) e.cash_amount = `Only ${fmt(toCents(cashAccount.available))} is available.`
    }
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
    const chequeIds = cheques.filter((c) => selected.includes(c.settlement_id)).map((c) => c.settlement_id)
    sentRef.current = chequeIds
    const cash = toCents(form.cash_amount)
    return {
      request_id: isCreate ? requestIdRef.current : undefined,
      company_id: Number(form.company_id),
      deposit_date: form.deposit_date,
      bank_account_id: Number(form.bank_account_id),
      slip_no: form.slip_no.trim() || null,
      description: form.description.trim() || null,
      cash_account_id: cash > 0 && form.cash_account_id ? Number(form.cash_account_id) : null,
      cash_amount: fromCents(cash),
      cheque_ids: chequeIds,
      updated_at: isCreate ? undefined : deposit?.updated_at,
    }
  }

  /** Server errors: cheque_ids.N lands on the cheque it came from. */
  const applyServerErrors = (serverErrors = {}) => {
    const fieldErrs = { __submitted: true }
    const rowErrs = {}
    Object.entries(serverErrors).forEach(([key, msgs]) => {
      const msg = Array.isArray(msgs) ? msgs[0] : String(msgs)
      const m = key.match(/^cheque_ids\.(\d+)$/)
      if (m && sentRef.current[Number(m[1])] !== undefined) rowErrs[sentRef.current[Number(m[1])]] = msg
      else fieldErrs[key === 'cheques' ? 'cheque_ids' : key] = msg
    })
    setErrors(fieldErrs)
    setRowErrors(rowErrs)
  }

  const resetForNew = () => {
    requestIdRef.current = newRequestId()
    setForm({
      ...emptyForm(companies.length === 1 ? String(companies[0].id) : form.company_id),
      bank_account_id: form.bank_account_id,
    })
    setSelected([])
    setSearch('')
    setErrors({})
    setRowErrors({})
    setTouched({})
    setPendingFiles([])
    qc.invalidateQueries({ queryKey: ['bank-deposit-next-no'] })
    firstFieldRef.current?.focus()
  }

  const bankLabel = (a) => (a ? `${a.code} - ${a.name}` : 'the selected bank account')

  /** action: 'close' | 'new' | 'draft' */
  const save = async (action) => {
    if (saving) return
    setMenuOpen(false)
    const e = validate(form)
    const body = payload()
    if (toCents(body.cash_amount) <= 0 && body.cheque_ids.length === 0) e.cheque_ids = 'Enter a cash amount or tick at least one cheque to deposit.'
    if (Object.keys(e).length) {
      setErrors({ ...e, __submitted: true })
      showError(e.cheque_ids ?? 'Please fix the highlighted fields.')
      return
    }

    const willConfirm = action !== 'draft' && can('confirm_bank_deposits')
    if (willConfirm) {
      const ok = await confirmAction({
        title: 'Deposit now?',
        message: `Deposit <strong>${fmt(totalCents)}</strong> (cash ${fmt(cashCents)} + ${body.cheque_ids.length} cheque(s) ${fmt(chequeCents)}) into <strong>${bankLabel(selectedBank)}</strong>? This posts to the ledger.`,
        confirmText: 'Yes, Deposit',
      })
      if (!ok) return
    }

    setSaving(true)
    try {
      const saved = isCreate ? await createBankDeposit(body) : await updateBankDeposit(id, body)

      for (const file of pendingFiles) {
        try { await uploadAttachment('bank_deposit', saved.id, file) } catch (upErr) {
          showWarning(`${file.name}: ${firstError(upErr, 'could not be attached.')}`)
        }
      }

      let confirmed = false
      if (willConfirm) {
        try {
          await confirmBankDeposit(saved.id)
          confirmed = true
        } catch (cErr) {
          showWarning(`${saved.deposit_no} saved as draft but not deposited: ${firstError(cErr, 'confirmation failed.')}`)
        }
      }

      if (confirmed) showSuccess(`${saved.deposit_no} banked and posted.`)
      else if (action === 'draft') showSuccess(`${saved.deposit_no} saved as draft.`)

      qc.invalidateQueries({ queryKey: ['bank-deposits'] })
      qc.invalidateQueries({ queryKey: ['bank-deposit', String(saved.id)] })
      qc.invalidateQueries({ queryKey: ['bank-deposit-in-hand'] })
      qc.invalidateQueries({ queryKey: ['journal-entries'] })

      if (action === 'new' && isCreate) resetForNew()
      else navigate('/finance/bank-deposits')
    } catch (sErr) {
      if (sErr?.response?.status === 409) {
        showError('This deposit was changed by someone else. Reload it and try again.')
      } else if (sErr?.response?.status === 422) {
        applyServerErrors(sErr.response.data?.errors)
        qc.invalidateQueries({ queryKey: ['bank-deposit-in-hand'] })
        showError(firstError(sErr, 'Please fix the highlighted fields.'))
      } else {
        showError(firstError(sErr, 'Could not save the deposit.'))
      }
    } finally {
      setSaving(false)
    }
  }

  const clearForm = async () => {
    if (isView) return
    if (isCreate) { resetForNew(); return }
    if (deposit) {
      const ok = await confirmAction({ title: 'Discard changes?', message: 'Reload the saved deposit and lose your edits.', confirmText: 'Discard', confirmColor: '#ef4444' })
      if (ok) { setLoadedVersion(null); qc.invalidateQueries({ queryKey: ['bank-deposit', id] }) }
    }
  }

  // ── Actions on a saved deposit (view mode) ─────────────────────────────────
  const refreshDeposit = () => {
    qc.invalidateQueries({ queryKey: ['bank-deposit', id] })
    qc.invalidateQueries({ queryKey: ['bank-deposit-history', id] })
    qc.invalidateQueries({ queryKey: ['bank-deposits'] })
    qc.invalidateQueries({ queryKey: ['bank-deposit-in-hand'] })
    qc.invalidateQueries({ queryKey: ['journal-entries'] })
  }

  const confirmMutation = useMutation({
    mutationFn: () => confirmBankDeposit(id),
    onSuccess: (d) => { refreshDeposit(); showSuccess(`${d?.deposit_no} banked and posted.`) },
    onError: (e) => showError(firstError(e, 'Could not confirm this deposit.')),
  })
  const voidMutation = useMutation({
    mutationFn: (body) => voidBankDeposit(id, body),
    onSuccess: (d) => { refreshDeposit(); showSuccess(`${d?.deposit_no} voided. Journal reversed and cheques back in hand.`) },
    onError: (e) => showError(firstError(e, 'Could not void this deposit.')),
  })
  const deleteMutation = useMutation({
    mutationFn: () => deleteBankDeposit(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bank-deposits'] })
      qc.invalidateQueries({ queryKey: ['bank-deposit-in-hand'] })
      showSuccess('Draft deposit deleted.')
      navigate('/finance/bank-deposits')
    },
    onError: (e) => showError(firstError(e, 'Only draft deposits can be deleted.')),
  })

  const handleConfirm = async () => {
    const ok = await confirmAction({
      title: `Confirm ${deposit.deposit_no}?`,
      message: `This deposits <strong>${fmt(toCents(deposit.total_amount))}</strong> into <strong>${deposit.bank_account_name}</strong> and posts it to the ledger. Once confirmed it can only be reversed by a Void.`,
      confirmText: 'Yes, Deposit',
    })
    if (ok) confirmMutation.mutate()
  }
  const handleVoid = async () => {
    const result = await confirmWithReasonAndDate({
      title: `Void ${deposit.deposit_no}?`, documentDate: deposit.deposit_date, lockDate, confirmText: 'Yes, Void Deposit',
    })
    if (result) voidMutation.mutate(result)
  }
  const handleDelete = async () => {
    if (await confirmDelete(deposit.deposit_no)) deleteMutation.mutate()
  }

  /** The paying-in slip: print it (opens the browser's PDF viewer) or save it. */
  const handlePdf = async (print) => {
    setPdfBusy(true)
    try {
      const blob = await downloadBankDepositPdf(id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `BD_${deposit.deposit_no}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError('Failed to generate the deposit slip.')
    } finally {
      setPdfBusy(false)
    }
  }

  // ── Attachments ────────────────────────────────────────────────────────────
  const uploadMutation = useMutation({
    mutationFn: (file) => uploadAttachment('bank_deposit', id, file),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bank-deposit-attachments', id] })
      qc.invalidateQueries({ queryKey: ['bank-deposit-history', id] })
      showSuccess('File attached.')
    },
    onError: (e) => showError(firstError(e, 'Could not attach the file.')),
  })
  const removeMutation = useMutation({
    mutationFn: deleteAttachment,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bank-deposit-attachments', id] })
      qc.invalidateQueries({ queryKey: ['bank-deposit-history', id] })
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
        if (!isView) saveRef.current(can('confirm_bank_deposits') ? 'close' : 'draft')
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

  // ── Render ─────────────────────────────────────────────────────────────────
  if (!isCreate && loadingDeposit) {
    return (
      <div className="flex items-center justify-center py-20 text-sm text-slate-400">
        <RefreshCw size={14} className="mr-2 animate-spin" /> Loading…
      </div>
    )
  }
  if (!isCreate && depositError) {
    return <div className="py-20 text-center text-sm text-red-500">Deposit not found, or you do not have access to it.</div>
  }

  const readOnly = isView || saving
  const title = isCreate ? 'Create Bank Deposit' : isView ? `Bank Deposit ${deposit?.deposit_no ?? ''}` : `Edit Bank Deposit ${deposit?.deposit_no ?? ''}`
  const crumbs = [
    { label: 'Finance' },
    { label: 'Bank Deposits', to: '/finance/bank-deposits' },
    { label: isCreate ? 'Create Deposit' : isView ? deposit?.deposit_no : 'Edit Deposit' },
  ]
  const canConfirm = can('confirm_bank_deposits')
  const primaryLabel = isCreate ? (canConfirm ? 'Create & Close' : 'Save as Draft') : (canConfirm ? 'Save & Deposit' : 'Save Draft')
  const primaryAction = canConfirm ? 'close' : 'draft'
  const bankOptions = isView && deposit
    ? [{ value: deposit.bank_account_id, label: deposit.bank_account_name }]
    : bankAccounts.map((a) => ({ value: a.id, label: `${a.code} - ${a.name}` }))
  const bankDetails = isView && deposit
    ? { bank_name: deposit.bank_name, branch_name: deposit.bank_branch_name, bank_account_no: deposit.bank_account_no }
    : selectedBank

  return (
    <div className="w-full pb-16">
      <div className="mb-2 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{title}</h1>
          <Breadcrumb crumbs={crumbs} />
        </div>
        {deposit && (
          <span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${
            deposit.status === 'confirmed' ? 'bg-green-100 text-green-700' : deposit.status === 'voided' ? 'bg-slate-200 text-slate-500' : 'bg-amber-100 text-amber-700'
          }`}>{deposit.status_label}</span>
        )}
      </div>

      <div className="space-y-2">
        {/* ── Deposit details + summary, side by side ── */}
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">
          <div className="rounded-lg border border-slate-200 bg-white shadow-sm lg:col-span-2">
            <div className="flex items-center gap-1.5 rounded-t-lg border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
              <Landmark size={13} />
              <h2 className="text-xs font-bold">Deposit Details</h2>
              {lockDate && !isView && (
                <span className="ml-auto text-[10px] font-semibold text-amber-600">Period closed up to {lockDate}</span>
              )}
            </div>

            <div className="grid grid-cols-1 gap-x-3 gap-y-2 p-2.5 md:grid-cols-2 lg:grid-cols-3">
              <div>
                <label className={LABEL_CLS}>Deposit Date <Req /></label>
                <input
                  ref={firstFieldRef}
                  type="date"
                  className={inputCls(err('deposit_date'))}
                  value={form.deposit_date}
                  max={today()}
                  onChange={(e) => setValue('deposit_date', e.target.value)}
                  onBlur={blur('deposit_date')}
                  disabled={readOnly}
                />
                <FieldError msg={err('deposit_date') ? errors.deposit_date : null} />
              </div>

              <div>
                <label className={LABEL_CLS}>Document No <span className="ml-1 text-[10px] font-medium normal-case text-indigo-400">auto</span></label>
                <input readOnly tabIndex={-1} className={INPUT_DISABLED_CLS} value={isCreate ? nextNo : (deposit?.deposit_no ?? '')} placeholder="Automatically filled" />
              </div>

              <div>
                <label className={LABEL_CLS}>Company <Req /></label>
                <select
                  className={selectCls(err('company_id'))}
                  value={form.company_id}
                  onChange={(e) => {
                    setForm((f) => ({ ...f, company_id: e.target.value, bank_account_id: '', cash_account_id: '', cash_amount: '' }))
                    setSelected([])
                  }}
                  onBlur={blur('company_id')}
                  disabled={readOnly || (isCreate && companies.length === 1)}
                >
                  <option value="">Select</option>
                  {(isView && deposit ? [{ id: deposit.company_id, name: deposit.company_name }] : companies)
                    .map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <FieldError msg={err('company_id') ? errors.company_id : null} />
              </div>

              <div className="md:col-span-2">
                <label className={LABEL_CLS}>Deposit Into (Bank Account) <Req /></label>
                <div tabIndex={-1} onBlur={blur('bank_account_id')}>
                  <FilterSearchSelect
                    size="form"
                    value={form.bank_account_id}
                    onChange={(v) => setValue('bank_account_id', v)}
                    options={bankOptions}
                    placeholder={!form.company_id ? 'Select the company first' : (bankOptions.length ? 'Select' : 'No bank accounts set up')}
                    invalid={Boolean(err('bank_account_id'))}
                    clearable={false}
                    disabled={readOnly || !form.company_id}
                  />
                </div>
                {bankDetails && (bankDetails.bank_name || bankDetails.bank_account_no) && (
                  <p className="mt-0.5 truncate text-[10px] text-slate-500">
                    {[bankDetails.bank_name, bankDetails.branch_name, bankDetails.bank_account_no && `A/C ${bankDetails.bank_account_no}`].filter(Boolean).join(' · ')}
                  </p>
                )}
                <FieldError msg={err('bank_account_id') ? errors.bank_account_id : null} />
              </div>

              <div>
                <label className={LABEL_CLS}>Slip No <span className="ml-1 text-[10px] font-medium normal-case text-slate-400">bank paying-in slip</span></label>
                <input
                  className={inputCls(err('slip_no'))}
                  value={form.slip_no}
                  maxLength={50}
                  placeholder="Type"
                  onChange={(e) => setValue('slip_no', e.target.value)}
                  onBlur={blur('slip_no')}
                  disabled={readOnly}
                />
                <FieldError msg={err('slip_no') ? errors.slip_no : null} />
              </div>
            </div>
          </div>

          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700">
              <ListChecks size={13} /> Deposit Summary
            </div>
            <dl className="space-y-1 p-2.5 text-xs">
              <div className="flex justify-between"><dt className="text-slate-500">Cash</dt><dd className="font-semibold text-slate-700">{fmt(cashCents)}</dd></div>
              <div className="flex justify-between"><dt className="text-slate-500">Cheques ({chequeCount})</dt><dd className="font-semibold text-slate-700">{fmt(chequeCents)}</dd></div>
              <div className="flex justify-between border-t border-slate-100 pt-1.5 text-sm">
                <dt className="font-bold text-slate-700">Total Deposit</dt>
                <dd className={`font-bold ${totalCents > 0 ? 'text-indigo-700' : 'text-slate-400'}`}>{fmt(totalCents)}</dd>
              </div>
            </dl>
          </div>
        </div>

        {/* ── Cash ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 rounded-t-lg border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700">
            <Banknote size={13} /> Cash <span className="font-normal text-indigo-400">optional</span>
          </div>
          <div className="grid grid-cols-1 gap-x-3 gap-y-2 p-2.5 md:grid-cols-2 lg:grid-cols-4">
            <div className="md:col-span-2">
              <label className={LABEL_CLS}>From Cash Book {toCents(form.cash_amount) > 0 && <Req />}</label>
              <div tabIndex={-1} onBlur={blur('cash_account_id')}>
                <FilterSearchSelect
                  size="form"
                  value={form.cash_account_id}
                  onChange={(v) => setValue('cash_account_id', v)}
                  options={isView && deposit
                    ? (deposit.cash_account_id ? [{ value: deposit.cash_account_id, label: deposit.cash_account_name }] : [])
                    : cashAccounts.map((a) => ({ value: a.id, label: `${a.code} - ${a.name}` }))}
                  placeholder={isView ? 'No cash' : !form.company_id ? 'Select the company first' : (cashAccounts.length ? 'Select' : 'No cash books set up')}
                  invalid={Boolean(err('cash_account_id'))}
                  disabled={readOnly || !form.company_id}
                />
              </div>
              <FieldError msg={err('cash_account_id') ? errors.cash_account_id : null} />
            </div>
            {!isView && (
              <div>
                <label className={LABEL_CLS}>Available</label>
                <input readOnly tabIndex={-1} className={`${INPUT_DISABLED_CLS} text-right`} value={cashAccount ? fmt(toCents(cashAccount.available)) : ''} placeholder="—" />
              </div>
            )}
            <div>
              <label className={LABEL_CLS}>Cash Amount {!isView && cashAccount && <span className="ml-1 text-[10px] font-medium normal-case text-indigo-400">double-click: all</span>}</label>
              <input
                inputMode="decimal"
                className={`${isView ? INPUT_DISABLED_CLS : inputCls(err('cash_amount'))} text-right font-bold`}
                value={isView ? fmt(cashCents) : form.cash_amount}
                placeholder="0.00"
                onChange={(e) => setValue('cash_amount', e.target.value.replace(/[^\d.]/g, ''))}
                onDoubleClick={() => cashAccount && setValue('cash_amount', fromCents(toCents(cashAccount.available)))}
                onBlur={blur('cash_amount')}
                disabled={readOnly}
              />
              <FieldError msg={err('cash_amount') ? errors.cash_amount : null} />
            </div>
          </div>
        </div>

        {/* ── Cheques ── */}
        <div className={`overflow-hidden rounded-lg border bg-white shadow-sm ${errors.cheque_ids ? 'border-red-300' : 'border-slate-200'}`}>
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-3 py-1.5">
            <div className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
              <ListChecks size={12} /> {isView ? 'Cheques Deposited' : 'Cheques in Hand'}
              {!isView && <span className="font-normal text-slate-400">oldest cheque date first</span>}
            </div>
            {loadingInHand && <RefreshCw size={12} className="animate-spin text-slate-400" />}
            {!isView && cheques.length > 0 && (
              <span className={`rounded px-1.5 py-0.5 text-[11px] font-semibold ${chequeCount ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500'}`}>
                Selected: {chequeCount} of {cheques.length} · {fmt(chequeCents)}
              </span>
            )}
            {errors.cheque_ids && <span className="text-[11px] font-semibold text-red-600">{errors.cheque_ids}</span>}
            {cheques.length > 0 && (
              <div className="relative ml-auto">
                <Search size={11} className="pointer-events-none absolute left-1.5 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                  className="h-6 w-52 rounded border border-slate-300 bg-white pl-5 pr-1.5 text-[11px] focus:border-indigo-400 focus:outline-none"
                  value={search}
                  placeholder="Cheque, receipt, customer, bank…"
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
            )}
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
                        ref={(el) => { if (el) el.indeterminate = chequeCount > 0 && !allSelected }}
                        disabled={readOnly || selectable.length === 0}
                        onChange={(e) => toggleAll(e.target.checked)}
                        title="Tick every cheque that can be deposited / clear all"
                      />
                    </th>
                  )}
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Cheque No</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Cheque Date</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Drawee Bank</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Receipt No</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Received</th>
                  <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Held In</th>
                  <th className="px-2 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                  {!isView && <th className="px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Status</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {shownCheques.length === 0 && (
                  <tr>
                    <td colSpan={10} className="px-3 py-6 text-center text-slate-400">
                      {isView ? 'No cheques on this deposit.'
                        : !form.company_id ? 'Select the Company to load its cheques in hand.'
                          : loadingInHand ? 'Loading…'
                            : term ? 'No cheques match the search.'
                              : 'No cheques in hand. Cheques received on a Customer Receipt into a "Cheques in Hand" cash book appear here.'}
                    </td>
                  </tr>
                )}
                {shownCheques.map((c) => {
                  const on = isSelected(c)
                  return (
                    <tr key={c.settlement_id} className={`hover:bg-slate-50 ${!isView && on ? 'bg-indigo-50/40' : ''} ${c.blocked ? 'text-slate-400' : ''}`}>
                      {!isView && (
                        <td className="px-2 py-1">
                          <input
                            type="checkbox"
                            className="h-3.5 w-3.5 cursor-pointer accent-indigo-600 disabled:cursor-not-allowed"
                            checked={on}
                            disabled={readOnly || Boolean(c.blocked)}
                            onChange={(e) => toggleCheque(c, e.target.checked)}
                            title={c.blocked ?? (on ? 'Untick to leave this cheque in hand' : 'Tick to deposit this cheque')}
                          />
                        </td>
                      )}
                      <td className="whitespace-nowrap px-2 py-1 font-mono font-medium text-slate-700">
                        {c.cheque_no}
                        <FieldError msg={rowErrors[c.settlement_id]} />
                      </td>
                      <td className={`whitespace-nowrap px-2 py-1 ${c.blocked ? 'font-semibold text-amber-600' : 'text-slate-500'}`}>{c.cheque_date}</td>
                      <td className="max-w-[140px] truncate px-2 py-1 text-slate-600" title={c.drawee_bank ?? ''}>{c.drawee_bank}</td>
                      <td className="max-w-[180px] truncate px-2 py-1 font-medium text-slate-700" title={c.customer_name ?? ''}>{c.customer_name}</td>
                      <td className="whitespace-nowrap px-2 py-1 font-mono text-slate-500">{c.receipt_no}</td>
                      <td className="whitespace-nowrap px-2 py-1 text-slate-500">{c.receipt_date}</td>
                      <td className="max-w-[160px] truncate px-2 py-1 text-slate-500" title={c.account ?? ''}>{c.account}</td>
                      <td className="whitespace-nowrap px-2 py-1 text-right">
                        <span className="inline-block rounded bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">{fmt(toCents(c.amount))}</span>
                      </td>
                      {!isView && <td className="px-2 py-1"><ChequeStatus cheque={c} selected={on} /></td>}
                    </tr>
                  )
                })}
              </tbody>
              {cheques.length > 0 && (
                <tfoot className="sticky bottom-0 bg-slate-50 font-bold text-slate-700">
                  <tr className="border-t border-slate-200">
                    <td colSpan={isView ? 7 : 8} className="px-2 py-1.5 text-right uppercase tracking-wider text-slate-500">
                      {isView ? 'Total' : 'Selected'}
                    </td>
                    <td className="px-2 py-1.5 text-right">{fmt(chequeCents)}</td>
                    {!isView && <td />}
                  </tr>
                </tfoot>
              )}
            </table>
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

          {isView && deposit && (
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 text-[11px] text-slate-500">
              <div className="flex flex-wrap gap-x-4 gap-y-1">
                <span>Deposited: <strong className="text-slate-800"><Money value={deposit.total_amount} /></strong></span>
                {deposit.journal_no && <span>Journal: <Link to="/finance/journal-entries" className="font-mono text-indigo-600 hover:underline">{deposit.journal_no}</Link></span>}
                {deposit.reversal_journal_no && <span>Reversal: <span className="font-mono">{deposit.reversal_journal_no}</span> on {deposit.reversal_date}</span>}
                {deposit.void_reason && <span className="text-red-600">Void reason: {deposit.void_reason}</span>}
                {deposit.created_by_name && <span>Entered by {deposit.created_by_name}</span>}
              </div>
              <div className="flex items-center gap-1.5">
                {deposit.status !== 'voided' && (
                  <>
                    <button type="button" onClick={() => handlePdf(true)} disabled={pdfBusy} className="flex items-center gap-1 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200 disabled:opacity-40">
                      <Printer size={12} /> Print Slip
                    </button>
                    <button type="button" onClick={() => handlePdf(false)} disabled={pdfBusy} title="Download the slip as PDF" className="flex items-center gap-1 rounded-md bg-sky-50 px-3 py-1.5 text-xs font-bold text-sky-700 hover:bg-sky-100 disabled:opacity-40">
                      <Download size={12} /> PDF
                    </button>
                  </>
                )}
                {deposit.status === 'draft' && can('edit_bank_deposits') && (
                  <Link to={`/finance/bank-deposits/${deposit.id}/edit`} className="flex items-center gap-1 rounded-md bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700 hover:bg-amber-100">
                    <Edit2 size={12} /> Edit
                  </Link>
                )}
                {deposit.status === 'draft' && canConfirm && (
                  <button type="button" onClick={handleConfirm} disabled={confirmMutation.isPending} className="flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-40">
                    <CheckCircle size={12} /> Confirm &amp; Deposit
                  </button>
                )}
                {deposit.status === 'draft' && can('delete_bank_deposits') && (
                  <button type="button" onClick={handleDelete} disabled={deleteMutation.isPending} className="flex items-center gap-1 rounded-md bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100 disabled:opacity-40">
                    <Trash2 size={12} /> Delete
                  </button>
                )}
                {deposit.status === 'confirmed' && can('void_bank_deposits') && (
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
                <Paperclip size={12} /> Attachments <span className="font-normal text-slate-400">stamped slip · PDF, JPG, PNG · max 5 MB · up to 10</span>
              </div>
              {deposit?.status !== 'voided' && (isView ? can('edit_bank_deposits') : true) && (
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
                    {deposit?.status === 'draft' && can('edit_bank_deposits') && (
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
          { keys: ['Ctrl', 'S'], desc: canConfirm ? (isCreate ? 'Create & Close' : 'Save & Deposit') : 'Save Draft' },
          { keys: ['Ctrl', 'Shift', 'S'], desc: 'Save as Draft' },
          { keys: ['Alt', 'C'], desc: 'Clear' },
          { keys: ['Double-click'], desc: 'Deposit all the cash available (on Cash Amount)' },
          { keys: ['Space'], desc: 'Tick / untick a cheque (on its checkbox)' },
        ]} />
      )}
    </div>
  )
}
