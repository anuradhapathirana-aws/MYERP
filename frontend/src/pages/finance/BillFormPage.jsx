import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Ban, CheckCircle, ChevronDown, Download, Edit2, FileText, History, Info, ListPlus, Paperclip, Plus, RefreshCw, Trash2, Upload, Wallet, X,
} from 'lucide-react'
import {
  confirmBill, createBill, deleteBill, getBill, getBillGrnOptions, getBillHistory, getBillOptions,
  getNextBillNo, getSupplierBillDefaults, updateBill, voidBill,
} from '../../api/bills'
import { purchaseTaxCodesQuery } from '../../api/taxCodes'
import { calculateTax } from '../../utils/taxCalc'
import { deleteAttachment, downloadAttachment, getAttachments, uploadAttachment } from '../../api/financeAttachments'
import { getAllSuppliers } from '../../api/suppliers'
import Breadcrumb from '../../components/Breadcrumb'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import HelpTip from '../../components/ui/HelpTip'
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

const MAX_LINES = 50
const TAX_NOTICE_KEY = 'bill.linesNotice.dismissed'

let lineSeq = 0
const emptyLine = (accountId = '') => ({
  key: ++lineSeq,
  charge_type_id: '',
  ledger_account_id: accountId,
  description: '',
  grn_id: '',
  tax_code_id: '',
  amount: '',
})

const emptyForm = (companyId = '') => ({
  supplier_id: '',
  transaction_date: today(),
  due_date: today(),
  document_no: '',
  document_date: today(),
  company_id: companyId,
  location_id: '',
  description: '',
  prices_include_tax: false,
  lines: [emptyLine()],
})

/** Server errors keep their keys (lines.2.grn_id), so each lands on its own cell. */
const mapServerErrors = (errors = {}) => {
  const out = {}
  Object.entries(errors).forEach(([key, msgs]) => {
    out[key] = Array.isArray(msgs) ? msgs[0] : String(msgs)
  })
  return out
}

const TREATMENT_CHIP = {
  landed_cost:     'bg-indigo-50 text-indigo-700',
  recoverable_tax: 'bg-green-50 text-green-700',
  expense:         'bg-orange-50 text-orange-700',
}

/**
 * One line as the server will post it (BillLinePricer): an Import VAT line is
 * the tax itself -- claimable while VAT is on, cost while it is off; any other
 * line runs its tax code through the same calculator as the GRN.
 */
function priceLine(line, { treatment, components, inclusive, vatActive }) {
  const typed = Number(line.amount) || 0
  if (treatment === 'recoverable_tax') {
    const claim = vatActive ? typed : 0
    return { base: typed, tax: 0, total: typed, claimable: claim, cost: typed - claim }
  }
  const t = calculateTax(components, typed, inclusive && components.length > 0)
  return { base: t.base, tax: t.taxTotal, total: t.total, claimable: t.recoverable, cost: t.total - t.recoverable }
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
 * Header: Supplier, Transaction / Due / Document dates, Document No, Bill No
 * (auto), Company / Location, Description. Lines: one per charge on the
 * supplier's invoice -- a Charge Type (which brings the account and decides
 * where it posts), an optional GRN link for landed costs, a tax code, and the
 * amount. A plain expense bill is simply one line with no charge type.
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
      company_id: String(bill.company_id ?? ''),
      location_id: bill.location_id ? String(bill.location_id) : '',
      description: bill.description ?? '',
      prices_include_tax: Boolean(bill.prices_include_tax),
      lines: (bill.lines?.length ? bill.lines : [{}]).map((l) => ({
        key: ++lineSeq,
        charge_type_id: l.charge_type_id ? String(l.charge_type_id) : '',
        ledger_account_id: l.ledger_account_id ? String(l.ledger_account_id) : '',
        description: l.description ?? '',
        grn_id: l.grn_id ? String(l.grn_id) : '',
        grn_no: l.grn_no ?? null,
        tax_code_id: l.tax_code_id ? String(l.tax_code_id) : '',
        // An inclusive bill shows the amount as it was typed off the invoice.
        amount: (bill.prices_include_tax && l.amount_incl != null ? l.amount_incl : l.amount) ?? '',
      })),
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

  const chargeTypes = useMemo(() => options.charge_types ?? [], [options.charge_types])
  const chargeById  = useMemo(() => new Map(chargeTypes.map((t) => [String(t.id), t])), [chargeTypes])
  const chargeOptions = useMemo(() => chargeTypes.map((t) => ({ value: t.id, label: t.name, group: t.treatment_label })), [chargeTypes])
  const grnLinkAvailable = Boolean(options.grn_link_available)
  const vatActive        = options.vat_active !== false

  // Tax codes with each tax's rate on the bill date -- the server prices with the same.
  const { data: taxCodeOptions = [] } = useQuery(purchaseTaxCodesQuery(form.transaction_date))
  const taxCodesById = useMemo(() => new Map(taxCodeOptions.map((c) => [String(c.id), c])), [taxCodeOptions])

  // The latest confirmed GRNs, plus any this bill already links to (it may be older).
  const { data: grnList = [] } = useQuery({
    queryKey: ['bill-grn-options'],
    queryFn: () => getBillGrnOptions(),
    enabled: grnLinkAvailable && !isView,
    staleTime: 60 * 1000,
  })
  const grnOptions = useMemo(() => {
    const opts = grnList.map((g) => ({ value: g.id, label: `${g.grn_no} · ${g.supplier_name ?? ''} · ${g.grn_date ?? ''}` }))
    const known = new Set(opts.map((o) => String(o.value)))
    for (const l of form.lines) {
      if (l.grn_id && !known.has(String(l.grn_id))) {
        opts.push({ value: Number(l.grn_id), label: l.grn_no ?? `GRN #${l.grn_id}` })
        known.add(String(l.grn_id))
      }
    }
    return opts
  }, [grnList, form.lines])

  const [noticeOpen, setNoticeOpen] = useState(() => {
    try { return localStorage.getItem(TAX_NOTICE_KEY) !== '1' } catch { return true }
  })
  const dismissNotice = () => {
    setNoticeOpen(false)
    try { localStorage.setItem(TAX_NOTICE_KEY, '1') } catch { /* private mode: show again next time */ }
  }

  const ctxFor = (line) => {
    const type = chargeById.get(String(line.charge_type_id))
    return {
      treatment: type?.treatment ?? null,
      components: type?.treatment === 'recoverable_tax' ? [] : (taxCodesById.get(String(line.tax_code_id))?.components ?? []),
      inclusive: form.prices_include_tax,
      vatActive,
    }
  }

  const priced = form.lines.map((l) => ({ line: l, ctx: ctxFor(l), p: priceLine(l, ctxFor(l)) }))
  const totals = priced.reduce((acc, { ctx, p }) => {
    acc.total += p.total
    acc.claimable += p.claimable
    if (ctx.treatment === 'expense' || !ctx.treatment) acc.expense += p.cost
    else acc.landed += p.cost
    return acc
  }, { total: 0, claimable: 0, landed: 0, expense: 0 })
  const importVatAsCost = !vatActive && form.lines.some((l) => chargeById.get(String(l.charge_type_id))?.treatment === 'recoverable_tax')

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
    if (!f.company_id) e.company_id = 'Select the company.'
    if (!f.lines.length) e.lines = 'Add at least one line.'
    f.lines.forEach((l, i) => {
      const type = chargeById.get(String(l.charge_type_id))
      if (!l.ledger_account_id) e[`lines.${i}.ledger_account_id`] = 'Select the account.'
      const amt = Number(l.amount)
      if (l.amount === '' || Number.isNaN(amt)) e[`lines.${i}.amount`] = 'Enter the amount.'
      else if (amt <= 0) e[`lines.${i}.amount`] = 'Must be more than zero.'
      else if (!/^\d+(\.\d{1,2})?$/.test(String(l.amount).trim())) e[`lines.${i}.amount`] = 'At most 2 decimals.'
      else if (amt > MAX_AMOUNT) e[`lines.${i}.amount`] = 'Too large.'
      if (l.grn_id && (!type || type.treatment === 'expense')) e[`lines.${i}.grn_id`] = 'Only a landed cost or Import VAT can link to a GRN.'
      if (l.tax_code_id && type?.treatment === 'recoverable_tax') e[`lines.${i}.tax_code_id`] = 'Import VAT is the tax itself.'
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

  const setLine = (index, patch) => {
    setForm((f) => ({ ...f, lines: f.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)) }))
    const keys = Object.keys(patch).map((k) => `lines.${index}.${k}`)
    if (keys.some((k) => errors[k])) setErrors((prev) => ({ ...prev, ...Object.fromEntries(keys.map((k) => [k, undefined])) }))
  }

  // A charge type brings its account, and decides whether a GRN or a tax code applies.
  const setLineCharge = (index, value) => {
    const type = chargeById.get(String(value))
    const line = form.lines[index]
    setLine(index, {
      charge_type_id: value ? String(value) : '',
      ledger_account_id: type?.ledger_account_id ? String(type.ledger_account_id) : line.ledger_account_id,
      grn_id: type?.links_to_grn ? line.grn_id : '',
      tax_code_id: type?.treatment === 'recoverable_tax' ? '' : line.tax_code_id,
    })
  }

  const addLine = () => {
    if (form.lines.length >= MAX_LINES) return
    setForm((f) => {
      const last = f.lines[f.lines.length - 1]
      // A new line keeps the shipment of the one above -- an agent's bill is usually all one GRN.
      return { ...f, lines: [...f.lines, { ...emptyLine(), grn_id: last?.grn_id ?? '' }] }
    })
  }

  const removeLine = (index) => {
    setForm((f) => ({ ...f, lines: f.lines.length > 1 ? f.lines.filter((_, i) => i !== index) : f.lines }))
    setErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => !k.startsWith('lines.'))))
  }

  // Supplier picked (or date changed) -> due date from credit period, default account.
  const applySupplierDefaults = async (supplierId, txnDate, { keepDue = dueTouched } = {}) => {
    if (!supplierId || !txnDate) return
    try {
      const d = await getSupplierBillDefaults(supplierId, txnDate)
      // The supplier group's expense account fills lines that have none yet.
      setForm((f) => ({
        ...f,
        due_date: keepDue && f.due_date ? f.due_date : (d.due_date ?? f.due_date),
        lines: f.lines.map((l) => (l.ledger_account_id || !d.ledger_account_id ? l : { ...l, ledger_account_id: String(d.ledger_account_id) })),
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
    prices_include_tax: form.prices_include_tax,
    lines: form.lines.map((l) => ({
      charge_type_id: l.charge_type_id ? Number(l.charge_type_id) : null,
      ledger_account_id: Number(l.ledger_account_id),
      description: l.description.trim() || null,
      grn_id: l.grn_id ? Number(l.grn_id) : null,
      tax_code_id: l.tax_code_id ? Number(l.tax_code_id) : null,
      amount: String(l.amount).trim(),
    })),
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
  const addLineRef = useRef(addLine)
  useEffect(() => { saveRef.current = save; clearRef.current = clearForm; addLineRef.current = addLine })
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
      } else if (e.altKey && e.key.toLowerCase() === 'a') {
        e.preventDefault()
        if (!isView) addLineRef.current()
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
              <label className={LABEL_CLS}>Due Date <Req /> <span className="ml-1 text-[10px] font-medium normal-case text-indigo-400">from credit period</span> <HelpTip text="The date this bill should be paid by. It fills in from the supplier's credit period." /></label>
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
              <label className={LABEL_CLS}>Document No <Req /> <span className="ml-1 text-[10px] font-medium normal-case text-slate-400">supplier invoice no</span> <HelpTip text="The number printed on the supplier's invoice. If there is no invoice (for example a mason), use your own number, such as a work note number." /></label>
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

            <div className="flex items-end pb-1">
              <label className={`flex select-none items-center gap-1.5 ${readOnly ? 'opacity-70' : 'cursor-pointer'}`} title="Tick when the invoice prints prices with VAT already in them. The system splits each line back to before tax.">
                <input
                  type="checkbox"
                  checked={form.prices_include_tax}
                  onChange={(e) => setValue('prices_include_tax', e.target.checked)}
                  disabled={readOnly}
                />
                <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Amounts include VAT</span>
              </label>
            </div>

            <div className="md:col-span-2 lg:col-span-3">
              <label className={LABEL_CLS}>Description</label>
              <textarea
                rows={1}
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

          {/* ── Lines: one per charge on the supplier's invoice ── */}
          <div className="border-t border-slate-100">
            <div className="flex items-center justify-between bg-slate-50 px-3 py-1.5">
              <div className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
                <ListPlus size={12} /> Lines <span className="font-normal text-slate-400">one per charge on the invoice</span>
              </div>
              {!readOnly && (
                <button type="button" onClick={addLine} disabled={form.lines.length >= MAX_LINES} title="Add line (Alt+A)"
                  className="flex items-center gap-1 rounded-md border border-indigo-200 bg-white px-2 py-0.5 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50 disabled:opacity-40">
                  <Plus size={11} /> Add line
                </button>
              )}
            </div>

            {noticeOpen && !isView && (
              <div className="mx-2.5 mt-1.5 flex items-start gap-2 rounded border border-sky-100 bg-sky-50 px-2.5 py-1.5 text-[11px] leading-snug text-sky-800">
                <Info size={13} className="mt-0.5 shrink-0" />
                <span className="flex-1">
                  Enter <b>one line per charge</b> on the supplier&apos;s invoice and pick its <b>Charge Type</b>: the account fills in and the books follow.
                  Link <b>landed costs</b> (freight, duty, clearing) and <b>Import VAT</b> to the shipment&apos;s <b>GRN</b> so its Costing can pull them.
                  Import VAT is entered as the CusDec amount, with no tax. Plain expenses (electricity, rent) need no charge type.
                </span>
                <button type="button" onClick={dismissNotice} className="rounded p-0.5 text-sky-500 hover:bg-sky-100" title="Hide this tip"><X size={12} /></button>
              </div>
            )}
            {importVatAsCost && (
              <div className="mx-2.5 mt-1.5 rounded border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] text-amber-800">
                VAT is switched off for this business (Master Data &gt; Taxes), so Import VAT cannot be claimed: it is posted as a cost of the goods.
              </div>
            )}
            {errors.lines && <p className="px-3 pt-1 text-[10px] text-red-500">{errors.lines}</p>}

            <div className="overflow-x-auto p-2.5 pt-1.5">
              <table className="w-full min-w-[60rem] text-xs">
                <thead>
                  <tr className="text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                    <th className="w-6 py-1">#</th>
                    <th className="w-44 py-1 pr-1.5">Charge Type <HelpTip text="What this line is for, such as Freight or Customs Duty. It fills in the account and decides whether the amount can go into Costing. Leave empty for a plain expense and pick the account yourself." /></th>
                    <th className="w-52 py-1 pr-1.5">Account <Req /> <HelpTip text="The ledger account this line is charged to. Only expense accounts can be used on a bill." /></th>
                    <th className="py-1 pr-1.5">Description</th>
                    {grnLinkAvailable && <th className="w-44 py-1 pr-1.5">GRN (shipment) <HelpTip text="The shipment this charge belongs to. Link landed costs and Import VAT here so that shipment's Costing can pull them." /></th>}
                    <th className="w-40 py-1 pr-1.5">Tax <HelpTip text="The tax on this line. Pick VAT (Tax Invoice) only if you hold a valid Tax Invoice. Leave empty if the line has no tax." /></th>
                    <th className="w-28 py-1 pr-1.5 text-right">Amount <Req /></th>
                    <th className="w-28 py-1 pr-1.5 text-right">Total</th>
                    {!readOnly && <th className="w-7 py-1" />}
                  </tr>
                </thead>
                <tbody className="align-top">
                  {priced.map(({ line, ctx, p }, i) => {
                    const type = chargeById.get(String(line.charge_type_id))
                    const lerr = (f) => errors[`lines.${i}.${f}`]
                    const isTaxLine = ctx.treatment === 'recoverable_tax'
                    return (
                      <tr key={line.key} className="border-t border-slate-100">
                        <td className="py-1 pt-2 text-slate-400">{i + 1}</td>
                        <td className="py-1 pr-1.5">
                          <FilterSearchSelect portal size="form" value={line.charge_type_id} onChange={(v) => setLineCharge(i, v)}
                            options={chargeOptions} placeholder="— expense —" disabled={readOnly} invalid={Boolean(lerr('charge_type_id'))} />
                          {type && <span className={`mt-0.5 inline-flex rounded px-1 text-[9px] font-semibold ${TREATMENT_CHIP[type.treatment] ?? ''}`}>{type.treatment_label}</span>}
                          <FieldError msg={lerr('charge_type_id')} />
                        </td>
                        <td className="py-1 pr-1.5">
                          <FilterSearchSelect portal size="form" wide value={line.ledger_account_id} onChange={(v) => setLine(i, { ledger_account_id: v ? String(v) : '' })}
                            options={accountOptions} placeholder="Select" clearable={false} disabled={readOnly} invalid={Boolean(lerr('ledger_account_id'))} />
                          <FieldError msg={lerr('ledger_account_id')} />
                        </td>
                        <td className="py-1 pr-1.5">
                          <input className={inputCls(lerr('description'))} value={line.description} maxLength={500} placeholder="Optional"
                            onChange={(e) => setLine(i, { description: e.target.value })} disabled={readOnly} />
                        </td>
                        {grnLinkAvailable && (
                          <td className="py-1 pr-1.5">
                            {type?.links_to_grn ? (
                              <FilterSearchSelect portal size="form" wide value={line.grn_id} onChange={(v) => setLine(i, { grn_id: v ? String(v) : '' })}
                                options={grnOptions} placeholder="Link GRN" disabled={readOnly} invalid={Boolean(lerr('grn_id'))} />
                            ) : (
                              <span className="block pt-1.5 text-[10px] text-slate-400">{type ? 'Not a product cost' : '—'}</span>
                            )}
                            <FieldError msg={lerr('grn_id')} />
                          </td>
                        )}
                        <td className="py-1 pr-1.5">
                          {isTaxLine ? (
                            <span className="block pt-1.5 text-[10px] text-green-700">{vatActive ? 'Is the tax (claimable)' : 'Is the tax (cost)'}</span>
                          ) : (
                            <select className={selectCls(lerr('tax_code_id'))} value={line.tax_code_id}
                              onChange={(e) => setLine(i, { tax_code_id: e.target.value })} disabled={readOnly}>
                              <option value="">No tax</option>
                              {taxCodeOptions.filter((c) => c.components?.length).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                          )}
                          <FieldError msg={lerr('tax_code_id')} />
                        </td>
                        <td className="py-1 pr-1.5">
                          <input inputMode="decimal" className={`${inputCls(lerr('amount'))} text-right font-semibold`} value={line.amount} placeholder="0.00"
                            onChange={(e) => setLine(i, { amount: e.target.value.replace(/[^\d.]/g, '') })} disabled={readOnly} />
                          <FieldError msg={lerr('amount')} />
                        </td>
                        <td className="py-1 pr-1.5 pt-2 text-right tabular-nums"
                          title={p.tax ? `Before tax ${p.base.toFixed(2)} + tax ${p.tax.toFixed(2)}${p.claimable ? ` · ${p.claimable.toFixed(2)} claimable` : ''}` : undefined}>
                          <span className="font-semibold text-slate-800"><Money value={p.total} /></span>
                          {p.tax > 0 && <span className="block text-[10px] text-green-700">VAT <Money value={p.tax} /></span>}
                        </td>
                        {!readOnly && (
                          <td className="py-1 pt-1.5">
                            <button type="button" title="Remove line" onClick={() => removeLine(i)} disabled={form.lines.length === 1}
                              className="rounded p-1 text-red-600 hover:bg-red-50 disabled:opacity-30"><Trash2 size={13} /></button>
                          </td>
                        )}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <div className="flex flex-wrap items-center justify-end gap-x-5 gap-y-1 border-t border-slate-100 px-3 py-1.5 text-[11px] text-slate-500">
              {totals.landed > 0 && <span title="Goes into the cost of the goods; pulled into the shipment's Costing">Landed cost: <strong className="text-indigo-700"><Money value={totals.landed} /></strong></span>}
              {totals.claimable > 0 && <span title="Posted to Input VAT and set off against your output VAT">VAT claimable: <strong className="text-green-700"><Money value={totals.claimable} /></strong></span>}
              {totals.expense > 0 && <span title="Period expense, not a product cost">Expense: <strong className="text-orange-700"><Money value={totals.expense} /></strong></span>}
              <span>Bill total: <strong className="text-sm text-slate-900"><Money value={totals.total} /></strong></span>
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
          { keys: ['Alt', 'A'], desc: 'Add line' },
          { keys: ['Alt', 'C'], desc: 'Clear' },
          { keys: ['Tab'], desc: 'Next field' },
        ]} />
      )}
    </div>
  )
}
