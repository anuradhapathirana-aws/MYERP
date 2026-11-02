import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Ban, CheckCircle, ChevronDown, Download, Edit2, FileText, History, Paperclip, RefreshCw, Trash2, Upload, Wallet, X,
} from 'lucide-react'
import {
  confirmBill, createBill, deleteBill, getBill, getBillHistory, getBillOptions,
  getNextBillNo, getSupplierBillDefaults, updateBill, voidBill,
} from '../../api/bills'
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

const MAX_AMOUNT = 9999999999.99
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

const emptyForm = (companyId = '') => ({
  supplier_id: '',
  transaction_date: today(),
  due_date: today(),
  document_no: '',
  document_date: today(),
  ledger_account_id: '',
  amount: '',
  company_id: companyId,
  location_id: '',
  description: '',
})

/** Map server field names (lines.0.amount) onto the single-line form. */
const mapServerErrors = (errors = {}) => {
  const out = {}
  Object.entries(errors).forEach(([key, msgs]) => {
    const field = key === 'lines.0.ledger_account_id' ? 'ledger_account_id'
      : key === 'lines.0.amount' ? 'amount'
        : key.startsWith('lines') ? 'ledger_account_id'
          : key
    out[field] = Array.isArray(msgs) ? msgs[0] : String(msgs)
  })
  return out
}

const firstError = (err, fallback) => {
  const errors = err?.response?.data?.errors
  return (errors && Object.values(errors)[0]?.[0]) ?? err?.response?.data?.message ?? fallback
}

const EVENT_LABELS = {
  created: 'Created', updated: 'Edited', confirmed: 'Confirmed', voided: 'Voided', deleted: 'Deleted',
  attachment_added: 'File attached', attachment_removed: 'File removed',
  payment_applied: 'Payment applied', payment_reversed: 'Payment reversed',
}

function Req() {
  return <span className="text-red-500">*</span>
}

function FieldError({ msg }) {
  return msg ? <p className="mt-0.5 text-[10px] text-red-500">{msg}</p> : null
}

const fmtSize = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)

/**
 * Create / edit / view a supplier bill.
 *
 * Follows the agreed mockup: Transaction Date, Due Date, Document No, Bill No
 * (auto), Account, Amount, Description -- plus Supplier, Document Date,
 * Company / Location and attachments. One account and one amount per bill;
 * it is saved as one line so splits can come later without a data change.
 *
 * "Create" means save AND confirm (post to the ledger). Save as Draft keeps it
 * editable. If confirming fails (e.g. maker-checker), the bill stays a draft
 * and the user is told why.
 */
export default function BillFormPage() {
  const { id } = useParams()
  const { pathname } = useLocation()
  // /finance/bills/create -> create · /finance/bills/:id/edit -> edit · /finance/bills/:id -> view
  const mode = !id ? 'create' : pathname.endsWith('/edit') ? 'edit' : 'view'
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { can } = usePermissions()

  const isCreate = mode === 'create'
  const isView = mode === 'view'

  const [form, setForm] = useState(emptyForm())
  const [errors, setErrors] = useState({})
  const [touched, setTouched] = useState({})
  const [dueTouched, setDueTouched] = useState(false)
  const [pendingFiles, setPendingFiles] = useState([])
  const [menuOpen, setMenuOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const requestIdRef = useRef(newRequestId())
  const fileInputRef = useRef(null)
  const firstFieldRef = useRef(null)

  // ── Lookups ────────────────────────────────────────────────────────────────
  const { data: options = {} } = useQuery({
    queryKey: ['bill-options'],
    queryFn: getBillOptions,
    staleTime: 5 * 60 * 1000,
  })
  const { data: suppliers = [] } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn: getAllSuppliers,
    staleTime: 5 * 60 * 1000,
  })

  const { data: bill, isLoading: loadingBill, isError: billError } = useQuery({
    queryKey: ['bill', id],
    queryFn: () => getBill(id),
    enabled: !isCreate && Boolean(id),
  })

  const { data: attachments = [] } = useQuery({
    queryKey: ['bill-attachments', id],
    queryFn: () => getAttachments('bill', id),
    enabled: !isCreate && Boolean(id),
  })

  const { data: history = [] } = useQuery({
    queryKey: ['bill-history', id],
    queryFn: () => getBillHistory(id),
    enabled: isView && Boolean(id),
  })

  const { data: nextNo = '' } = useQuery({
    queryKey: ['bill-next-no', form.transaction_date],
    queryFn: () => getNextBillNo(form.transaction_date),
    enabled: isCreate && Boolean(form.transaction_date),
  })

  const companies = useMemo(() => options.companies ?? [], [options.companies])
  const lockDate = options.lock_date ?? null

  // One company -> pre-select it (smart default). Adjusting state while
  // rendering (not in an effect) is React's recommended pattern for this.
  if (isCreate && !form.company_id && companies.length === 1) {
    setForm((f) => ({ ...f, company_id: String(companies[0].id) }))
  }

  // Load the bill into the form for edit / view, once per saved version.
  const [loadedVersion, setLoadedVersion] = useState(null)
  if (bill && loadedVersion !== bill.updated_at) {
    setLoadedVersion(bill.updated_at)
    setForm({
      supplier_id: String(bill.supplier_id ?? ''),
      transaction_date: bill.transaction_date ?? '',
      due_date: bill.due_date ?? '',
      document_no: bill.document_no ?? '',
      document_date: bill.document_date ?? '',
      ledger_account_id: String(bill.ledger_account_id ?? ''),
      amount: bill.lines?.[0]?.amount ?? bill.total_amount ?? '',
      company_id: String(bill.company_id ?? ''),
      location_id: bill.location_id ? String(bill.location_id) : '',
      description: bill.description ?? '',
    })
    setDueTouched(true)
  }

  // Edit route on a non-draft bill -> show it read-only instead.
  useEffect(() => {
    if (mode === 'edit' && bill && bill.status !== 'draft') navigate(`/finance/bills/${bill.id}`, { replace: true })
  }, [mode, bill, navigate])

  useEffect(() => { firstFieldRef.current?.focus() }, [])

  const supplierOptions = useMemo(() => suppliers.map((s) => ({ value: s.id, label: s.name })), [suppliers])

  const accountOptions = useMemo(() => (options.expense_accounts ?? [])
    .filter((a) => !a.company_id || !form.company_id || String(a.company_id) === String(form.company_id))
    .map((a) => ({ value: a.id, label: `${a.code} - ${a.name}` })), [options.expense_accounts, form.company_id])

  const locationOptions = useMemo(() => (options.locations ?? [])
    .filter((l) => String(l.company_id) === String(form.company_id)), [options.locations, form.company_id])

  // Company has one location -> pre-select it, once per company picked, so
  // the user can still clear it afterwards.
  const [autoLocationFor, setAutoLocationFor] = useState(null)
  if (isCreate && form.company_id && autoLocationFor !== form.company_id && options.locations) {
    setAutoLocationFor(form.company_id)
    if (!form.location_id && locationOptions.length === 1) {
      setForm((f) => ({ ...f, location_id: String(locationOptions[0].id) }))
    }
  }

  // ── Validation (on blur, and again on save) ────────────────────────────────
  const validate = (f) => {
    const e = {}
    if (!f.supplier_id) e.supplier_id = 'Select the supplier.'
    if (!f.transaction_date) e.transaction_date = 'Transaction date is required.'
    else if (lockDate && f.transaction_date <= lockDate) e.transaction_date = `Period closed up to ${lockDate}. Choose a later date.`
    if (!f.due_date) e.due_date = 'Due date is required.'
    else if (f.transaction_date && f.due_date < f.transaction_date) e.due_date = 'Due date cannot be before the transaction date.'
    if (!f.document_no.trim()) e.document_no = "Enter the supplier's invoice number."
    if (f.document_date && f.transaction_date && f.document_date > f.transaction_date) e.document_date = 'Cannot be after the transaction date.'
    if (!f.ledger_account_id) e.ledger_account_id = 'Select the expense account.'
    const amt = Number(f.amount)
    if (f.amount === '' || Number.isNaN(amt)) e.amount = 'Enter the amount.'
    else if (amt <= 0) e.amount = 'Amount must be greater than zero.'
    else if (!/^\d+(\.\d{1,2})?$/.test(String(f.amount).trim())) e.amount = 'Use at most 2 decimal places.'
    else if (amt > MAX_AMOUNT) e.amount = 'Amount is too large.'
    if (!f.company_id) e.company_id = 'Select the company.'
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

  // Supplier picked (or date changed) -> due date from credit period, default account.
  const applySupplierDefaults = async (supplierId, txnDate, { keepDue = dueTouched } = {}) => {
    if (!supplierId || !txnDate) return
    try {
      const d = await getSupplierBillDefaults(supplierId, txnDate)
      setForm((f) => ({
        ...f,
        due_date: keepDue && f.due_date ? f.due_date : (d.due_date ?? f.due_date),
        ledger_account_id: f.ledger_account_id || (d.ledger_account_id ? String(d.ledger_account_id) : ''),
      }))
    } catch { /* defaults are a convenience; the user can still type */ }
  }

  // ── Saving ─────────────────────────────────────────────────────────────────
  const payload = () => ({
    request_id: isCreate ? requestIdRef.current : undefined,
    supplier_id: Number(form.supplier_id),
    transaction_date: form.transaction_date,
    due_date: form.due_date,
    document_no: form.document_no.trim(),
    document_date: form.document_date || null,
    company_id: Number(form.company_id),
    location_id: form.location_id ? Number(form.location_id) : null,
    description: form.description.trim() || null,
    lines: [{ ledger_account_id: Number(form.ledger_account_id), amount: String(form.amount).trim() }],
    updated_at: isCreate ? undefined : bill?.updated_at,
  })

  const resetForNew = () => {
    requestIdRef.current = newRequestId()
    setForm(emptyForm(companies.length === 1 ? String(companies[0].id) : form.company_id))
    setErrors({})
    setTouched({})
    setDueTouched(false)
    setAutoLocationFor(null)
    setPendingFiles([])
    qc.invalidateQueries({ queryKey: ['bill-next-no'] })
    firstFieldRef.current?.focus()
  }

  /** action: 'close' | 'new' | 'draft' */
  const save = async (action) => {
    if (saving) return
    setMenuOpen(false)
    const e = validate(form)
    if (Object.keys(e).length) {
      setErrors({ ...e, __submitted: true })
      showError('Please fix the highlighted fields.')
      return
    }

    setSaving(true)
    try {
      const saved = isCreate ? await createBill(payload()) : await updateBill(id, payload())

      for (const file of pendingFiles) {
        try { await uploadAttachment('bill', saved.id, file) } catch (upErr) {
          showWarning(`${file.name}: ${firstError(upErr, 'could not be attached.')}`)
        }
      }

      let confirmed = false
      if (action !== 'draft' && can('confirm_bills')) {
        try {
          await confirmBill(saved.id)
          confirmed = true
        } catch (cErr) {
          showWarning(`${saved.bill_no} saved as draft but not confirmed: ${firstError(cErr, 'confirmation failed.')}`)
        }
      }

      if (confirmed) showSuccess(`${saved.bill_no} created and posted.`)
      else if (action === 'draft') showSuccess(`${saved.bill_no} saved as draft.`)

      qc.invalidateQueries({ queryKey: ['bills'] })
      qc.invalidateQueries({ queryKey: ['bill', String(saved.id)] })
      qc.invalidateQueries({ queryKey: ['journal-entries'] })

      if (action === 'new' && isCreate) resetForNew()
      else navigate('/finance/bills')
    } catch (sErr) {
      if (sErr?.response?.status === 409) {
        showError('This bill was changed by someone else. Reload it and try again.')
      } else if (sErr?.response?.status === 422) {
        setErrors({ ...mapServerErrors(sErr.response.data?.errors), __submitted: true })
        showError(firstError(sErr, 'Please fix the highlighted fields.'))
      } else {
        showError(firstError(sErr, 'Could not save the bill.'))
      }
    } finally {
      setSaving(false)
    }
  }

  const clearForm = async () => {
    if (isView) return
    if (isCreate) { resetForNew(); return }
    if (bill) {
      const ok = await confirmAction({ title: 'Discard changes?', message: 'Reload the saved bill and lose your edits.', confirmText: 'Discard', confirmColor: '#ef4444' })
      if (ok) qc.invalidateQueries({ queryKey: ['bill', id] })
    }
  }

  // ── Actions on a saved bill (view mode) ────────────────────────────────────
  const refreshBill = () => {
    qc.invalidateQueries({ queryKey: ['bill', id] })
    qc.invalidateQueries({ queryKey: ['bill-history', id] })
    qc.invalidateQueries({ queryKey: ['bills'] })
    qc.invalidateQueries({ queryKey: ['journal-entries'] })
  }

  const confirmMutation = useMutation({
    mutationFn: () => confirmBill(id),
    onSuccess: (b) => { refreshBill(); showSuccess(`${b?.bill_no} confirmed and posted.`) },
    onError: (e) => showError(firstError(e, 'Could not confirm this bill.')),
  })
  const voidMutation = useMutation({
    mutationFn: (body) => voidBill(id, body),
    onSuccess: (b) => { refreshBill(); showSuccess(`${b?.bill_no} voided. Journal reversed.`) },
    onError: (e) => showError(firstError(e, 'Could not void this bill.')),
  })
  const deleteMutation = useMutation({
    mutationFn: () => deleteBill(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['bills'] }); showSuccess('Draft bill deleted.'); navigate('/finance/bills') },
    onError: (e) => showError(firstError(e, 'Only draft bills can be deleted.')),
  })

  const handleConfirm = async () => {
    const ok = await confirmAction({
      title: `Confirm ${bill.bill_no}?`,
      message: 'This posts the bill to the ledger. Once confirmed it can only be reversed by a Void.',
      confirmText: 'Yes, Confirm',
    })
    if (ok) confirmMutation.mutate()
  }
  const handleVoid = async () => {
    const result = await confirmWithReasonAndDate({
      title: `Void ${bill.bill_no}?`, documentDate: bill.transaction_date, lockDate, confirmText: 'Yes, Void Bill',
    })
    if (result) voidMutation.mutate(result)
  }
  const handleDelete = async () => {
    if (await confirmDelete(bill.bill_no)) deleteMutation.mutate()
  }

  // ── Attachments ────────────────────────────────────────────────────────────
  const uploadMutation = useMutation({
    mutationFn: (file) => uploadAttachment('bill', id, file),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bill-attachments', id] })
      qc.invalidateQueries({ queryKey: ['bill-history', id] })
      showSuccess('File attached.')
    },
    onError: (e) => showError(firstError(e, 'Could not attach the file.')),
  })
  const removeMutation = useMutation({
    mutationFn: deleteAttachment,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['bill-attachments', id] })
      qc.invalidateQueries({ queryKey: ['bill-history', id] })
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
        if (!isView) saveRef.current(can('confirm_bills') ? 'close' : 'draft')
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
  if (!isCreate && loadingBill) {
    return (
      <div className="flex items-center justify-center py-20 text-sm text-slate-400">
        <RefreshCw size={14} className="mr-2 animate-spin" /> Loading…
      </div>
    )
  }
  if (!isCreate && billError) {
    return <div className="py-20 text-center text-sm text-red-500">Bill not found, or you do not have access to it.</div>
  }

  const readOnly = isView || saving
  const title = isCreate ? 'Create Bill Entry' : isView ? `Bill ${bill?.bill_no ?? ''}` : `Edit Bill ${bill?.bill_no ?? ''}`
  const crumbs = [
    { label: 'Finance' },
    { label: 'Bills', to: '/finance/bills' },
    { label: isCreate ? 'Create Bill' : isView ? bill?.bill_no : 'Edit Bill' },
  ]
  const canConfirm = can('confirm_bills')
  const primaryLabel = isCreate ? (canConfirm ? 'Create & Close' : 'Save as Draft') : (canConfirm ? 'Save & Confirm' : 'Save Draft')
  const primaryAction = canConfirm ? 'close' : 'draft'

  return (
    <div className="w-full pb-16">
      <div className="mb-2 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{title}</h1>
          <Breadcrumb crumbs={crumbs} />
        </div>
        {bill && (
          <div className="flex items-center gap-1.5">
            <span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${
              bill.status === 'confirmed' ? 'bg-green-100 text-green-700' : bill.status === 'voided' ? 'bg-slate-200 text-slate-500' : 'bg-amber-100 text-amber-700'
            }`}>{bill.status_label}</span>
            {bill.status === 'confirmed' && (
              <span className={`rounded-full px-2.5 py-1 text-[11px] font-bold ${bill.is_overdue ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'}`}>
                {bill.is_overdue ? 'Overdue' : bill.payment_status_label}
              </span>
            )}
          </div>
        )}
      </div>

      <div className="space-y-2">
        {/* ── Bill details (the mockup) ── */}
        {/* No overflow-hidden here: it would clip the select dropdowns. */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 rounded-t-lg border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
            <FileText size={13} />
            <h2 className="text-xs font-bold">Bill Details</h2>
            {lockDate && !isView && (
              <span className="ml-auto text-[10px] font-semibold text-amber-600">Period closed up to {lockDate}</span>
            )}
          </div>

          <div className="grid grid-cols-1 gap-x-3 gap-y-2 p-2.5 md:grid-cols-2 lg:grid-cols-3">
            <div className="md:col-span-2 lg:col-span-1">
              <label className={LABEL_CLS}>Supplier <Req /></label>
              <div ref={firstFieldRef} tabIndex={-1} onBlur={blur('supplier_id')}>
                <FilterSearchSelect
                  size="form"
                  value={form.supplier_id}
                  onChange={(v) => { setValue('supplier_id', v); applySupplierDefaults(v, form.transaction_date, { keepDue: false }) }}
                  options={supplierOptions}
                  placeholder="Select supplier"
                  invalid={Boolean(err('supplier_id'))}
                  clearable={false}
                  disabled={readOnly}
                />
              </div>
              <FieldError msg={err('supplier_id') ? errors.supplier_id : null} />
            </div>

            <div>
              <label className={LABEL_CLS}>Transaction Date <Req /></label>
              <input
                type="date"
                className={inputCls(err('transaction_date'))}
                value={form.transaction_date}
                onChange={(e) => { setValue('transaction_date', e.target.value); if (!dueTouched) applySupplierDefaults(form.supplier_id, e.target.value, { keepDue: false }) }}
                onBlur={blur('transaction_date')}
                disabled={readOnly}
              />
              <FieldError msg={err('transaction_date') ? errors.transaction_date : null} />
            </div>

            <div>
              <label className={LABEL_CLS}>Due Date <Req /> <span className="ml-1 text-[10px] font-medium normal-case text-indigo-400">from credit period</span></label>
              <input
                type="date"
                className={inputCls(err('due_date'))}
                value={form.due_date}
                min={form.transaction_date || undefined}
                onChange={(e) => { setDueTouched(true); setValue('due_date', e.target.value) }}
                onBlur={blur('due_date')}
                disabled={readOnly}
              />
              <FieldError msg={err('due_date') ? errors.due_date : null} />
            </div>

            <div>
              <label className={LABEL_CLS}>Document No <Req /> <span className="ml-1 text-[10px] font-medium normal-case text-slate-400">supplier invoice no</span></label>
              <input
                className={inputCls(err('document_no'))}
                value={form.document_no}
                maxLength={100}
                placeholder="Type"
                onChange={(e) => setValue('document_no', e.target.value)}
                onBlur={blur('document_no')}
                disabled={readOnly}
              />
              <FieldError msg={err('document_no') ? errors.document_no : null} />
            </div>

            <div>
              <label className={LABEL_CLS}>Document Date</label>
              <input
                type="date"
                className={inputCls(err('document_date'))}
                value={form.document_date}
                max={form.transaction_date || undefined}
                onChange={(e) => setValue('document_date', e.target.value)}
                onBlur={blur('document_date')}
                disabled={readOnly}
              />
              <FieldError msg={err('document_date') ? errors.document_date : null} />
            </div>

            <div>
              <label className={LABEL_CLS}>Bill No <span className="ml-1 text-[10px] font-medium normal-case text-indigo-400">auto</span></label>
              <input readOnly tabIndex={-1} className={INPUT_DISABLED_CLS} value={isCreate ? nextNo : (bill?.bill_no ?? '')} placeholder="Automatically filled" />
            </div>

            <div>
              <label className={LABEL_CLS}>Account <Req /> <span className="ml-1 text-[10px] font-medium normal-case text-slate-400">expense</span></label>
              <div tabIndex={-1} onBlur={blur('ledger_account_id')}>
                <FilterSearchSelect
                  size="form"
                  wide
                  value={form.ledger_account_id}
                  onChange={(v) => setValue('ledger_account_id', v)}
                  options={accountOptions}
                  placeholder="Select"
                  invalid={Boolean(err('ledger_account_id'))}
                  clearable={false}
                  disabled={readOnly}
                />
              </div>
              <FieldError msg={err('ledger_account_id') ? errors.ledger_account_id : null} />
            </div>

            <div>
              <label className={LABEL_CLS}>Amount <Req /></label>
              <input
                inputMode="decimal"
                className={`${inputCls(err('amount'))} text-right font-semibold`}
                value={form.amount}
                placeholder="0.00"
                onChange={(e) => setValue('amount', e.target.value.replace(/[^\d.]/g, ''))}
                onBlur={blur('amount')}
                disabled={readOnly}
              />
              <FieldError msg={err('amount') ? errors.amount : null} />
            </div>

            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className={LABEL_CLS}>Company <Req /></label>
                <select
                  className={selectCls(err('company_id'))}
                  value={form.company_id}
                  onChange={(e) => { setValue('company_id', e.target.value); setValue('location_id', '') }}
                  onBlur={blur('company_id')}
                  disabled={readOnly || (isCreate && companies.length === 1)}
                >
                  <option value="">Select</option>
                  {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <FieldError msg={err('company_id') ? errors.company_id : null} />
              </div>
              <div>
                <label className={LABEL_CLS}>Location</label>
                <select
                  className={selectCls(err('location_id'))}
                  value={form.location_id}
                  onChange={(e) => setValue('location_id', e.target.value)}
                  disabled={readOnly || !form.company_id}
                >
                  <option value="">—</option>
                  {locationOptions.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                </select>
                <FieldError msg={err('location_id') ? errors.location_id : null} />
              </div>
            </div>

            <div className="md:col-span-2 lg:col-span-3">
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
          </div>

          {/* ── Footer: Required · Clear · Create & Close ▾ ── */}
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

          {/* ── View-mode actions ── */}
          {isView && bill && (
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 text-[11px] text-slate-500">
              <div className="flex flex-wrap gap-x-4 gap-y-1">
                <span>Total: <strong className="text-slate-800"><Money value={bill.total_amount} /></strong></span>
                {bill.status !== 'draft' && <span>Balance: <strong className="text-slate-800"><Money value={bill.balance} /></strong></span>}
                {bill.journal_no && <span>Journal: <Link to="/finance/journal-entries" className="font-mono text-indigo-600 hover:underline">{bill.journal_no}</Link></span>}
                {bill.reversal_journal_no && <span>Reversal: <span className="font-mono">{bill.reversal_journal_no}</span> on {bill.reversal_date}</span>}
                {bill.void_reason && <span className="text-red-600">Void reason: {bill.void_reason}</span>}
                {bill.created_by_name && <span>Entered by {bill.created_by_name}</span>}
              </div>
              <div className="flex items-center gap-1.5">
                {bill.status === 'draft' && can('edit_bills') && (
                  <Link to={`/finance/bills/${bill.id}/edit`} className="flex items-center gap-1 rounded-md bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700 hover:bg-amber-100">
                    <Edit2 size={12} /> Edit
                  </Link>
                )}
                {bill.status === 'draft' && canConfirm && (
                  <button type="button" onClick={handleConfirm} disabled={confirmMutation.isPending} className="flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-40">
                    <CheckCircle size={12} /> Confirm
                  </button>
                )}
                {bill.status === 'draft' && can('delete_bills') && (
                  <button type="button" onClick={handleDelete} disabled={deleteMutation.isPending} className="flex items-center gap-1 rounded-md bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-100 disabled:opacity-40">
                    <Trash2 size={12} /> Delete
                  </button>
                )}
                {bill.status === 'confirmed' && bill.payment_status !== 'paid' && can('create_bill_payments') && (
                  <Link
                    to={`/finance/bill-payments/create?supplier_id=${bill.supplier_id}&company_id=${bill.company_id}&bill_id=${bill.id}`}
                    className="flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-700"
                  >
                    <Wallet size={12} /> Pay
                  </Link>
                )}
                {bill.status === 'confirmed' && can('void_bills') && (
                  <button type="button" onClick={handleVoid} disabled={voidMutation.isPending} className="flex items-center gap-1 rounded-md bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700 disabled:opacity-40">
                    <Ban size={12} /> Void
                  </button>
                )}
              </div>
            </div>
          )}
        </div>

        {/* ── Payments made against this bill ── */}
        {isView && bill?.payments?.length > 0 && (
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">
              <Wallet size={12} /> Payments
            </div>
            <table className="w-full text-xs">
              <tbody className="divide-y divide-slate-100">
                {bill.payments.map((p) => (
                  <tr key={p.bill_payment_id} className={p.status === 'voided' ? 'text-slate-400 line-through' : 'text-slate-700'}>
                    <td className="px-3 py-1.5 font-mono"><Link to={`/finance/bill-payments/${p.bill_payment_id}`} className="text-indigo-600 hover:underline">{p.payment_no}</Link></td>
                    <td className="px-3 py-1.5">{p.payment_date}</td>
                    <td className="px-3 py-1.5">{p.method}</td>
                    <td className="px-3 py-1.5 capitalize">{p.status}</td>
                    <td className="px-3 py-1.5 text-right font-semibold"><Money value={p.amount} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* ── Attachments + History side by side ── */}
        <div className={`grid grid-cols-1 gap-2 ${isView ? 'lg:grid-cols-2' : ''}`}>
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-3 py-1.5">
              <div className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
                <Paperclip size={12} /> Attachments <span className="font-normal text-slate-400">PDF, JPG, PNG · max 5 MB · up to 10</span>
              </div>
              {bill?.status !== 'voided' && (isView ? can('edit_bills') : true) && (
                <>
                  <input ref={fileInputRef} type="file" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" className="hidden" onChange={pickFiles} />
                  <button type="button" onClick={() => fileInputRef.current?.click()} disabled={saving || uploadMutation.isPending} className="flex items-center gap-1 rounded-md border border-indigo-200 bg-white px-2 py-0.5 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50 disabled:opacity-40">
                    <Upload size={11} /> Attach
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
                    {bill?.status === 'draft' && can('edit_bills') && (
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
                      {h.new_values?.payment_no && <span className="text-slate-500"> — {h.new_values.payment_no} · paid {h.new_values.paid_amount}</span>}
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
          { keys: ['Ctrl', 'S'], desc: canConfirm ? (isCreate ? 'Create & Close' : 'Save & Confirm') : 'Save Draft' },
          { keys: ['Ctrl', 'Shift', 'S'], desc: 'Save as Draft' },
          { keys: ['Alt', 'C'], desc: 'Clear' },
          { keys: ['Tab'], desc: 'Next field' },
        ]} />
      )}
    </div>
  )
}
