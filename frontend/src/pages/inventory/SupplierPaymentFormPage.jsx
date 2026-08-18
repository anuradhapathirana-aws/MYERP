import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CreditCard, FileText, Percent, Plus, RefreshCw, Save, Trash2, Wallet } from 'lucide-react'
import {
  confirmSupplierPayment,
  createSupplierPayment,
  getNextPaymentNo,
  getOpenCreditNotes,
  getOutstandingGrns,
  getSupplierPayment,
  updateSupplierPayment,
} from '../../api/supplierPayments'
import { getAllSuppliers } from '../../api/suppliers'
import { getAllPaymentModes } from '../../api/paymentModes'
import Breadcrumb from '../../components/Breadcrumb'
import Money from '../../components/ui/Money'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'
import { fmtMoneyWithSymbol } from '../../utils/currency'
import { INPUT_CLS, INPUT_DISABLED_CLS, LABEL_CLS, SELECT_CLS } from '../../utils/fieldStyles'

const SUPPLIER_TYPES = ['Trade', 'Service']
const BANK_NAMES = [
  'Bank of Ceylon', 'Commercial Bank', 'Sampath Bank', 'Hatton National Bank',
  "People's Bank", 'Nations Trust Bank', 'Seylan Bank', 'DFCC Bank', 'NDB Bank', 'Union Bank',
]
const EMPTY_ARRAY = []

function SectionHeader({ icon: Icon, title, colorClass, right }) {
  return (
    <div className={`flex items-center justify-between gap-1.5 px-3 py-2 border-b ${colorClass}`}>
      <div className="flex items-center gap-1.5">
        {Icon && <Icon size={13} />}
        <h2 className="text-xs font-bold">{title}</h2>
      </div>
      {right}
    </div>
  )
}

function emptySettlementDraft() {
  return {
    payment_mode_id: '', amount: '', bank_name: '', bank_account_no: '',
    reference_no: '', instrument_date: '',
  }
}

/** Every figure on this page is money. Plain-string contexts (messages, labels) only. */
function fmt(n) {
  return fmtMoneyWithSymbol(n)
}

export default function SupplierPaymentFormPage() {
  const { id }       = useParams()
  const isEdit       = Boolean(id)
  const navigate     = useNavigate()
  const queryClient  = useQueryClient()
  const today        = new Date().toISOString().slice(0, 10)

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Supplier Payments', to: '/inventory/supplier-payments' },
    { label: isEdit ? 'Edit Payment' : 'New Payment' },
  ]

  const [form, setForm] = useState({
    payment_date:     today,
    transaction_date: today,
    payment_no:       '',
    reference_no:     '',
    supplier_type:    '',
    supplier_id:      '',
    payment_remark:   '',
  })
  const [isAdvance, setIsAdvance]         = useState(false)
  const [advanceAmount, setAdvanceAmount] = useState('')
  const [rows, setRows]                   = useState([])       // pending GRN picker
  const [continued, setContinued]         = useState(false)    // Continue → Payment Summary view
  const [discountPct, setDiscountPct]     = useState('')
  const [creditNoteRows, setCreditNoteRows] = useState([])     // Set Off table
  const [setoffPct, setSetoffPct]         = useState('')
  const [settlements, setSettlements]     = useState([])
  const [settlementDraft, setSettlementDraft] = useState(emptySettlementDraft())
  const [errors, setErrors]               = useState({})
  const [status, setStatus]               = useState('draft')

  const { data: suppliers = EMPTY_ARRAY } = useQuery({ queryKey: ['suppliers-all'], queryFn: getAllSuppliers })
  const { data: paymentModes = EMPTY_ARRAY } = useQuery({ queryKey: ['payment-modes-all'], queryFn: getAllPaymentModes })
  const settleableModes = paymentModes.filter((m) => m.code !== 'setoff')

  const { data: nextPaymentNo } = useQuery({
    queryKey:  ['supplier-payments-next-no'],
    queryFn:   getNextPaymentNo,
    enabled:   !isEdit,
    staleTime: 0,
  })

  useEffect(() => {
    if (!isEdit && nextPaymentNo) {
      setForm((f) => ({ ...f, payment_no: nextPaymentNo }))
    }
  }, [nextPaymentNo, isEdit])

  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['supplier-payment', id],
    queryFn:  () => getSupplierPayment(id),
    enabled:  isEdit,
  })

  // Live pickers only matter while the payment is still editable — a confirmed payment
  // renders from its saved snapshot instead (see the row-building effects below).
  const { data: outstandingGrns = EMPTY_ARRAY } = useQuery({
    queryKey: ['outstanding-grns', form.supplier_id],
    queryFn:  () => getOutstandingGrns(form.supplier_id),
    enabled:  Boolean(form.supplier_id) && !isAdvance && status === 'draft',
  })

  const { data: openCreditNotes = EMPTY_ARRAY } = useQuery({
    queryKey: ['open-credit-notes', form.supplier_id],
    queryFn:  () => getOpenCreditNotes(form.supplier_id),
    enabled:  Boolean(form.supplier_id) && status === 'draft',
  })

  // Seed header + advance state from an existing draft
  useEffect(() => {
    if (!existing?.data) return
    const p = existing.data
    setForm({
      payment_date:     p.payment_date     ?? today,
      transaction_date: p.transaction_date ?? today,
      payment_no:       p.payment_no       ?? '',
      reference_no:     p.reference_no     ?? '',
      supplier_type:    p.supplier_type    ?? '',
      supplier_id:      p.supplier_id      ?? '',
      payment_remark:   p.payment_remark   ?? '',
    })
    setIsAdvance(Boolean(p.is_advance))
    setAdvanceAmount(p.is_advance ? String(p.advance_amount) : '')
    setStatus(p.status)
    setContinued(true)
    setSettlements((p.settlements ?? []).map((s) => ({
      _key: s.id, payment_mode_id: s.payment_mode_id, payment_mode_name: s.payment_mode_name,
      amount: String(s.amount), bank_name: s.bank_name ?? '', bank_account_no: s.bank_account_no ?? '',
      reference_no: s.reference_no ?? '', instrument_date: s.instrument_date ?? '',
    })))
  }, [existing])

  // Build the GRN rows. Confirmed payments render purely from the saved allocation
  // snapshot — their GRNs are paid off, so the live "outstanding" list no longer
  // contains them. Drafts merge the live outstanding list with any saved allocations.
  useEffect(() => {
    const allocations = existing?.data?.allocations ?? []

    if (existing?.data && existing.data.status !== 'draft') {
      setRows(allocations.map((a) => ({
        grn_id:       a.reference_id,
        grn_no:       a.grn_no ?? `#${a.reference_id}`,
        grn_date:     a.grn_date,
        po_no:        a.po_no,
        reference_no: a.reference_no,
        due_date:     a.due_date ?? '',
        amount:       a.outstanding_before,
        checked:      true,
        discount:     String(a.discount),
        payAmount:    String(a.payment_amount),
      })))
      return
    }

    const savedByGrn = new Map(allocations.map((a) => [a.reference_id, a]))
    const liveRows = outstandingGrns.map((g) => {
      const saved = savedByGrn.get(g.grn_id)
      return {
        grn_id:       g.grn_id,
        grn_no:       g.grn_no,
        grn_date:     g.grn_date,
        po_no:        g.po_no,
        reference_no: g.reference_no,
        due_date:     saved?.due_date ?? g.due_date ?? '',
        amount:       g.outstanding,
        checked:      Boolean(saved),
        discount:     saved ? String(saved.discount) : '',
        // Defaults to full payment (outstanding − discount); user can reduce it for a partial payment.
        payAmount:    saved ? String(saved.payment_amount) : String(g.outstanding),
      }
    })

    // Draft allocations whose GRN vanished from the live list (paid off by another
    // confirmed payment meanwhile) must stay visible so the user can review/remove them.
    const liveIds = new Set(outstandingGrns.map((g) => g.grn_id))
    const orphanRows = allocations
      .filter((a) => !liveIds.has(a.reference_id))
      .map((a) => ({
        grn_id:       a.reference_id,
        grn_no:       a.grn_no ?? `#${a.reference_id}`,
        grn_date:     a.grn_date,
        po_no:        a.po_no,
        reference_no: a.reference_no,
        due_date:     a.due_date ?? '',
        amount:       0, // nothing left outstanding on this GRN
        checked:      true,
        discount:     String(a.discount),
        payAmount:    String(a.payment_amount),
      }))

    setRows([...liveRows, ...orphanRows])
  }, [outstandingGrns, existing])

  // Build the Set Off rows. Confirmed payments render from the saved setoffs — the
  // consumed credit notes are exhausted, so the live "open" list no longer has them.
  // Drafts merge the live open credit notes with any setoffs already saved.
  useEffect(() => {
    if (existing?.data && existing.data.status !== 'draft') {
      setCreditNoteRows((existing.data.setoffs ?? []).map((s) => ({
        id:                 s.credit_note_id ?? `setoff-${s.id}`,
        credit_note_no:     s.credit_note?.credit_note_no ?? '—',
        credit_type:        s.setoff_type,
        credit_type_label:  s.credit_note?.credit_type_label ?? s.setoff_type_label,
        created_at:         s.credit_note?.created_at,
        amount:             s.credit_note?.amount ?? s.amount,
        // Reconstruct the balance as it stood before this payment consumed it, so the
        // "Remaining" column (balance − setoff) shows the credit note's current balance.
        remaining_balance:  (s.credit_note?.remaining_balance ?? 0) + s.amount,
        checked:            true,
        setoff_amount:      String(s.amount),
      })))
      return
    }

    const savedByCn = new Map((existing?.data?.setoffs ?? []).map((s) => [s.credit_note_id, s]))
    setCreditNoteRows(openCreditNotes.map((cn) => {
      const saved = savedByCn.get(cn.id)
      return {
        id:                 cn.id,
        credit_note_no:     cn.credit_note_no,
        credit_type:        cn.credit_type,
        credit_type_label:  cn.credit_type_label,
        created_at:         cn.created_at,
        amount:             cn.amount,
        remaining_balance:  cn.remaining_balance,
        checked:            Boolean(saved),
        setoff_amount:      saved ? String(saved.amount) : '',
      }
    }))
  }, [openCreditNotes, existing])

  const setField = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }))

  const toggleRow = (grnId) => setRows((prev) => prev.map((r) => r.grn_id === grnId ? { ...r, checked: !r.checked } : r))
  const checkedRows = rows.filter((r) => r.checked)

  // What's actually still owed on this GRN once the discount write-off is applied.
  // payAmount is allowed to exceed this — that's a deliberate overpayment, and the excess
  // becomes an over_payment credit note (available for setoff later) rather than being blocked.
  const maxPayable = (r) => Math.max(0, r.amount - (parseFloat(r.discount) || 0))

  const setRowDiscount = (grnId, value) => setRows((prev) => prev.map((r) => r.grn_id === grnId ? { ...r, discount: value } : r))
  const setRowPayAmount = (grnId, value) => setRows((prev) => prev.map((r) => r.grn_id === grnId ? { ...r, payAmount: value } : r))
  const removeSummaryRow = (grnId) => setRows((prev) => prev.map((r) => r.grn_id === grnId ? { ...r, checked: false, discount: '', payAmount: String(r.amount) } : r))

  const applyDiscountPct = () => {
    const pct = parseFloat(discountPct)
    if (!pct || pct <= 0) return
    setRows((prev) => prev.map((r) => {
      if (!r.checked) return r
      const discount = ((r.amount * pct) / 100).toFixed(2)
      const cap = Math.max(0, r.amount - parseFloat(discount))
      return { ...r, discount, payAmount: String(cap) }
    }))
  }

  const totalPayable = checkedRows.reduce((sum, r) => sum + (parseFloat(r.payAmount) || 0), 0)
  const target = isAdvance ? (parseFloat(advanceAmount) || 0) : totalPayable

  const toggleCreditNote = (cnId) => setCreditNoteRows((prev) => prev.map((c) => c.id === cnId ? { ...c, checked: !c.checked, setoff_amount: !c.checked ? c.setoff_amount : '' } : c))
  const setCreditNoteAmount = (cnId, value) => setCreditNoteRows((prev) => prev.map((c) => c.id === cnId ? { ...c, setoff_amount: value } : c))

  const applySetoffPct = () => {
    const pct = parseFloat(setoffPct)
    if (!pct || pct <= 0) return
    setCreditNoteRows((prev) => prev.map((c) => c.checked ? { ...c, setoff_amount: Math.min(c.remaining_balance, (c.remaining_balance * pct) / 100).toFixed(2) } : c))
  }

  const checkedCreditNotes = creditNoteRows.filter((c) => c.checked && parseFloat(c.setoff_amount) > 0)
  const setoffTotal = checkedCreditNotes.reduce((sum, c) => sum + (parseFloat(c.setoff_amount) || 0), 0)

  const settlementTotal = settlements.reduce((sum, s) => sum + (parseFloat(s.amount) || 0), 0)
  const remaining = target - setoffTotal - settlementTotal
  // Underfunded (remaining > 0) blocks saving. Overfunded (remaining < 0) is allowed — the
  // excess becomes an over_payment credit note, available for setoff later, once confirmed.
  const isUnderfunded = remaining > 0.01
  const isOverfunded  = remaining < -0.01

  // Prefill the next settlement's amount with the outstanding remaining balance — recomputed
  // whenever it changes (GRN selection, setoffs, prior settlements). Only applies to a fresh
  // draft (no pay mode chosen yet), so it never clobbers an amount the user is editing.
  useEffect(() => {
    setSettlementDraft((d) => (
      d.payment_mode_id ? d : { ...d, amount: remaining > 0.01 ? remaining.toFixed(2) : '' }
    ))
  }, [remaining])

  const selectedMode = settleableModes.find((m) => String(m.id) === String(settlementDraft.payment_mode_id))

  // Cheque numbers must be exactly 6 digits — validated live while typing and again on Add
  // (the backend re-validates on save as well).
  const isChequeMode      = selectedMode?.code === 'cheque'
  const chequeNoInvalid   = isChequeMode && !/^\d{6}$/.test(settlementDraft.reference_no)
  const chequeDateInvalid = isChequeMode && !settlementDraft.instrument_date

  const addSettlement = () => {
    if (!settlementDraft.payment_mode_id || !(parseFloat(settlementDraft.amount) > 0)) {
      showError('Select a pay mode and enter an amount.')
      return
    }
    if (chequeNoInvalid) {
      showError('Cheque number must be exactly 6 digits.')
      return
    }
    if (chequeDateInvalid) {
      showError('Cheque date is required.')
      return
    }
    setSettlements((prev) => [...prev, {
      _key: Date.now() + Math.random(),
      payment_mode_id:   settlementDraft.payment_mode_id,
      payment_mode_name: selectedMode?.payment_mode_name ?? '',
      amount:             settlementDraft.amount,
      bank_name:          settlementDraft.bank_name,
      bank_account_no:    settlementDraft.bank_account_no,
      reference_no:       settlementDraft.reference_no,
      instrument_date:    settlementDraft.instrument_date,
    }])
    setSettlementDraft(emptySettlementDraft())
  }
  const removeSettlement = (key) => setSettlements((prev) => prev.filter((s) => s._key !== key))

  const saveMutation = useMutation({
    mutationFn: (payload) => isEdit ? updateSupplierPayment(id, payload) : createSupplierPayment(payload),
    onSuccess: (res) => {
      showSuccess(isEdit ? 'Supplier payment updated.' : 'Supplier payment created.')
      const savedId = res?.data?.id ?? id
      navigate(`/inventory/supplier-payments/${savedId}/edit`)
      queryClient.invalidateQueries({ queryKey: ['supplier-payments'] })
      queryClient.invalidateQueries({ queryKey: ['supplier-payment', String(savedId)] })
    },
    onError: (e) => {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save supplier payment.')
    },
  })

  const confirmMutation = useMutation({
    mutationFn: () => confirmSupplierPayment(id),
    onSuccess: () => {
      showSuccess('Payment confirmed. Outstanding balances updated.')
      queryClient.invalidateQueries({ queryKey: ['supplier-payment', id] })
      queryClient.invalidateQueries({ queryKey: ['supplier-payments'] })
      queryClient.invalidateQueries({ queryKey: ['outstanding-grns'] })
      queryClient.invalidateQueries({ queryKey: ['open-credit-notes'] })
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to confirm payment.'),
  })

  const err = (f) => errors[f]?.[0]

  const handleSubmit = () => {
    const clientErrors = {}
    if (!form.supplier_id) clientErrors.supplier_id = ['Supplier is required.']
    if (isAdvance && !(parseFloat(advanceAmount) > 0)) clientErrors.advance_amount = ['Advance amount is required.']
    if (!isAdvance && checkedRows.length === 0) clientErrors.allocations = ['Select at least one GRN, or mark this as a standalone advance.']
    if (isUnderfunded) clientErrors.balance = [`Payment is underfunded by ${fmt(remaining)}. Add more Setoffs or Payment Details.`]

    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors)
      showError('Please resolve the highlighted issues before saving.')
      return
    }

    setErrors({})
    saveMutation.mutate({
      payment_date:     form.payment_date,
      transaction_date: form.transaction_date || null,
      reference_no:     form.reference_no || null,
      supplier_type:    form.supplier_type || null,
      supplier_id:      parseInt(form.supplier_id),
      payment_remark:   form.payment_remark || null,
      is_advance:       isAdvance,
      advance_amount:   isAdvance ? parseFloat(advanceAmount) : null,
      allocations: isAdvance ? [] : checkedRows.map((r) => ({
        reference_type: 'grn',
        reference_id:   r.grn_id,
        due_date:       r.due_date || null,
        discount:       parseFloat(r.discount) || 0,
        payment_amount: parseFloat(r.payAmount) || 0,
        line_remark:    null,
      })),
      setoffs: checkedCreditNotes.map((c) => ({
        setoff_type:    c.credit_type,
        credit_note_id: c.id,
        amount:         parseFloat(c.setoff_amount),
        remark:         null,
      })),
      settlements: settlements.map((s) => ({
        payment_mode_id:  parseInt(s.payment_mode_id),
        amount:           parseFloat(s.amount),
        bank_name:        s.bank_name || null,
        bank_account_no:  s.bank_account_no || null,
        reference_no:     s.reference_no || null,
        instrument_date:  s.instrument_date || null,
        remark:           null,
      })),
    })
  }

  const handleConfirm = async () => {
    const ok = await confirmAction({
      title: 'Confirm this payment?',
      message: 'This will deduct against GRN outstanding balances and post any setoffs. This action cannot be easily undone.',
      confirmText: 'Yes, Confirm Payment',
    })
    if (ok) confirmMutation.mutate()
  }

  if (isEdit && loadingExisting) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <RefreshCw size={14} className="animate-spin" /> Loading…
        </div>
      </div>
    )
  }

  const isDraft = !isEdit || status === 'draft'
  const showSummary = isAdvance || continued

  return (
    <div className="w-full pb-16">
      <div className="mb-2 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">
            {isEdit ? 'Edit Supplier Payment' : 'New Supplier Payment'}
          </h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {isEdit && (
          <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold ${
            status === 'confirmed' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'
          }`}>
            {status === 'confirmed' ? 'Confirmed' : 'Draft'}
          </span>
        )}
      </div>

      <div className="space-y-2">
        {/* ── Header details ── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader icon={FileText} title="Payment Note Details" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100" />
          <div className="grid grid-cols-1 gap-2 p-2.5 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <div>
              <label className={LABEL_CLS}>Date <span className="text-red-500">*</span></label>
              <input type="date" className={INPUT_CLS} value={form.payment_date} onChange={setField('payment_date')} disabled={!isDraft} />
            </div>
            <div>
              <label className={LABEL_CLS}>Transaction Date</label>
              <input type="date" className={INPUT_CLS} value={form.transaction_date} onChange={setField('transaction_date')} disabled={!isDraft} />
            </div>
            <div>
              <label className={LABEL_CLS}>Document Number <span className="ml-1 normal-case font-medium text-indigo-400 text-[10px]">auto</span></label>
              <input readOnly className={INPUT_DISABLED_CLS} placeholder={!isEdit ? 'Generating…' : ''} value={form.payment_no} />
            </div>
            <div>
              <label className={LABEL_CLS}>Reference Number</label>
              <input className={INPUT_CLS} value={form.reference_no} onChange={setField('reference_no')} disabled={!isDraft} />
            </div>
            <div>
              <label className={LABEL_CLS}>Supplier Type <span className="text-red-500">*</span></label>
              <select className={SELECT_CLS} value={form.supplier_type} onChange={setField('supplier_type')} disabled={!isDraft}>
                <option value="">— Select —</option>
                {SUPPLIER_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <div>
              <label className={LABEL_CLS}>Supplier <span className="text-red-500">*</span></label>
              <select
                className={err('supplier_id') ? SELECT_CLS.replace('border-slate-200', 'border-red-300') : SELECT_CLS}
                value={form.supplier_id}
                onChange={(e) => { setField('supplier_id')(e); setContinued(false) }}
                disabled={!isDraft}
              >
                <option value="">— Select supplier —</option>
                {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
              {err('supplier_id') && <p className="mt-0.5 text-[10px] text-red-500">{err('supplier_id')}</p>}
            </div>
            <div className="md:col-span-2 xl:col-span-2">
              <label className={LABEL_CLS}>Payment Remark</label>
              <input className={INPUT_CLS} value={form.payment_remark} onChange={setField('payment_remark')} disabled={!isDraft} />
            </div>
          </div>

          {isDraft && (
            <div className="flex items-center gap-2 border-t border-slate-100 px-3 py-2">
              <input
                type="checkbox"
                id="is_advance"
                checked={isAdvance}
                onChange={(e) => { setIsAdvance(e.target.checked); setContinued(false) }}
                className="h-3.5 w-3.5 cursor-pointer rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <label htmlFor="is_advance" className="cursor-pointer text-xs font-semibold text-slate-600">
                This is a standalone advance payment (no GRNs)
              </label>
            </div>
          )}
        </div>

        {/* ── Advance amount ── */}
        {isAdvance && (
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <SectionHeader icon={Wallet} title="Advance Amount" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100" />
            <div className="p-2.5 md:w-64">
              <label className={LABEL_CLS}>Amount <span className="text-red-500">*</span></label>
              <input
                type="number" min="0" step="0.01"
                className={err('advance_amount') ? INPUT_CLS.replace('border-slate-200', 'border-red-300') : INPUT_CLS}
                value={advanceAmount}
                onChange={(e) => setAdvanceAmount(e.target.value)}
                disabled={!isDraft}
              />
              {err('advance_amount') && <p className="mt-0.5 text-[10px] text-red-500">{err('advance_amount')}</p>}
            </div>
          </div>
        )}

        {/* ── Step 1: Pending GRN picker (selection only) ── */}
        {!isAdvance && !showSummary && (
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <SectionHeader icon={FileText} title="Pending GRNs" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100" />

            {!form.supplier_id ? (
              <div className="px-4 py-10 text-center text-xs text-slate-400">Select a supplier to load pending GRNs.</div>
            ) : rows.length === 0 ? (
              <div className="px-4 py-10 text-center text-xs text-slate-400">No pending GRNs for this supplier.</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="bg-slate-50 border-b border-slate-200">
                      <th className="w-8 px-2 py-1.5"></th>
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">GRN No</th>
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">GRN Date</th>
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">PO Number</th>
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Reference No</th>
                      <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Outstanding</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.map((r) => (
                      <tr key={r.grn_id} className="hover:bg-slate-50/60 cursor-pointer" onClick={() => toggleRow(r.grn_id)}>
                        <td className="px-2 py-1 text-center">
                          <input
                            type="checkbox"
                            checked={r.checked}
                            onChange={() => toggleRow(r.grn_id)}
                            onClick={(e) => e.stopPropagation()}
                            className="h-3.5 w-3.5 cursor-pointer rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                          />
                        </td>
                        <td className="px-2 py-1 font-mono text-indigo-600">{r.grn_no}</td>
                        <td className="px-2 py-1 text-slate-600 whitespace-nowrap">{r.grn_date}</td>
                        <td className="px-2 py-1 text-slate-500 font-mono">{r.po_no || '—'}</td>
                        <td className="px-2 py-1 text-slate-500 font-mono">{r.reference_no || '—'}</td>
                        <td className="px-2 py-1 text-right font-medium text-slate-700"><Money value={r.amount} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {err('allocations') && (
              <div className="border-t border-red-100 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600">{err('allocations')}</div>
            )}

            <div className="flex items-center justify-end border-t border-slate-100 px-3 py-2">
              <button
                type="button"
                disabled={checkedRows.length === 0}
                onClick={() => setContinued(true)}
                className="flex items-center gap-1 rounded bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-indigo-700 disabled:opacity-40"
              >
                Continue ({checkedRows.length} selected)
              </button>
            </div>
          </div>
        )}

        {/* ── Step 2: Payment Summary + Set Off ── */}
        {!isAdvance && showSummary && (
          <>
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <SectionHeader
                icon={FileText}
                title="Payment Summary"
                colorClass="text-indigo-700 bg-indigo-50 border-indigo-100"
                right={
                  <div className="flex items-center gap-2">
                    {isDraft && (
                      <>
                        <Percent size={12} className="text-slate-400" />
                        <input
                          type="number" min="0" max="100" step="0.01"
                          placeholder="Enter %"
                          className="w-20 rounded-md border-2 border-slate-200 bg-white px-2 py-0.5 text-xs outline-none focus:border-indigo-400"
                          value={discountPct}
                          onChange={(e) => setDiscountPct(e.target.value)}
                        />
                        <button type="button" onClick={applyDiscountPct} className="rounded-md border-2 border-indigo-200 bg-white px-2 py-0.5 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50">
                          Apply as Discount
                        </button>
                        <button type="button" onClick={() => setContinued(false)} className="flex items-center gap-1 rounded-md border-2 border-slate-200 bg-white px-2 py-0.5 text-[11px] font-bold text-slate-600 hover:bg-slate-50">
                          <ArrowLeft size={11} /> Change Selection
                        </button>
                      </>
                    )}
                  </div>
                }
              />
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="bg-slate-50 border-b border-slate-200">
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">GRN Number</th>
                      <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Amount</th>
                      <th className="w-28 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Discount</th>
                      <th className="w-32 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Total Payable Amount</th>
                      <th className="w-8"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {checkedRows.map((r) => {
                      const cap = maxPayable(r)
                      const paid = parseFloat(r.payAmount) || 0
                      const remainderAfterThis = Math.max(0, cap - paid)
                      const overpaidAfterThis  = Math.max(0, paid - cap)
                      return (
                        <tr key={r.grn_id} className="hover:bg-slate-50/60">
                          <td className="px-2 py-1 font-mono text-indigo-600">{r.grn_no}</td>
                          <td className="px-2 py-1 text-right text-slate-600"><Money value={r.amount} /></td>
                          <td className="px-2 py-1">
                            <input
                              type="number" min="0" step="0.01"
                              className="w-full rounded-md border-2 border-slate-200 bg-slate-50 px-1.5 py-0.5 text-right text-xs outline-none focus:border-indigo-400 disabled:cursor-not-allowed disabled:bg-slate-100"
                              value={r.discount}
                              disabled={!isDraft}
                              onChange={(e) => setRowDiscount(r.grn_id, e.target.value)}
                            />
                          </td>
                          <td className="px-2 py-1">
                            <input
                              type="number" min="0" step="0.01"
                              className="w-full rounded-md border-2 border-slate-200 bg-slate-50 px-1.5 py-0.5 text-right text-xs font-medium text-slate-700 outline-none focus:border-indigo-400 disabled:cursor-not-allowed disabled:bg-slate-100"
                              value={r.payAmount}
                              disabled={!isDraft}
                              onChange={(e) => setRowPayAmount(r.grn_id, e.target.value)}
                            />
                            {remainderAfterThis > 0.01 && (
                              <div className="mt-0.5 text-right text-[9px] font-semibold text-amber-500">
                                {fmt(remainderAfterThis)} stays outstanding
                              </div>
                            )}
                            {overpaidAfterThis > 0.01 && (
                              <div className="mt-0.5 text-right text-[9px] font-semibold text-emerald-600">
                                {fmt(overpaidAfterThis)} extra → credit for setoff
                              </div>
                            )}
                          </td>
                          <td className="px-2 py-1 text-center">
                            {isDraft && (
                              <button type="button" onClick={() => removeSummaryRow(r.grn_id)} className="rounded-md p-1 text-slate-300 hover:bg-red-50 hover:text-red-500" title="Remove">
                                <Trash2 size={12} />
                              </button>
                            )}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            {/* ── Set Off ── */}
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <SectionHeader
                icon={CreditCard}
                title="Set Off"
                colorClass="text-amber-700 bg-amber-50 border-amber-100"
                right={isDraft && creditNoteRows.length > 0 && (
                  <div className="flex items-center gap-2">
                    <Percent size={12} className="text-slate-400" />
                    <input
                      type="number" min="0" max="100" step="0.01"
                      placeholder="Enter %"
                      className="w-20 rounded-md border-2 border-slate-200 bg-white px-2 py-0.5 text-xs outline-none focus:border-amber-400"
                      value={setoffPct}
                      onChange={(e) => setSetoffPct(e.target.value)}
                    />
                    <button type="button" onClick={applySetoffPct} className="rounded-md border-2 border-amber-200 bg-white px-2 py-0.5 text-[11px] font-bold text-amber-600 hover:bg-amber-50">
                      Apply
                    </button>
                  </div>
                )}
              />
              {creditNoteRows.length === 0 ? (
                <div className="px-4 py-8 text-center text-xs text-slate-400">No open credit notes for this supplier.</div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="bg-slate-50 border-b border-slate-200">
                        <th className="w-8 px-2 py-1.5"></th>
                        <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Date</th>
                        <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Type</th>
                        <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Document Number</th>
                        <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Total Amount</th>
                        <th className="w-28 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Set Off Amount</th>
                        <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Remaining</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {creditNoteRows.map((c) => {
                        const applied = parseFloat(c.setoff_amount) || 0
                        return (
                          <tr key={c.id} className="hover:bg-slate-50/60">
                            <td className="px-2 py-1 text-center">
                              <input
                                type="checkbox"
                                checked={c.checked}
                                disabled={!isDraft}
                                onChange={() => toggleCreditNote(c.id)}
                                className="h-3.5 w-3.5 cursor-pointer rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                              />
                            </td>
                            <td className="px-2 py-1 text-slate-600 whitespace-nowrap">{c.created_at?.slice(0, 10)}</td>
                            <td className="px-2 py-1 text-slate-600">{c.credit_type_label}</td>
                            <td className="px-2 py-1 font-mono text-amber-600">{c.credit_note_no}</td>
                            <td className="px-2 py-1 text-right text-slate-600"><Money value={c.remaining_balance} /></td>
                            <td className="px-2 py-1">
                              <input
                                type="number" min="0" max={c.remaining_balance} step="0.01"
                                className="w-full rounded-md border-2 border-slate-200 bg-slate-50 px-1.5 py-0.5 text-right text-xs outline-none focus:border-amber-400 disabled:cursor-not-allowed disabled:bg-slate-100"
                                value={c.setoff_amount}
                                disabled={!isDraft || !c.checked}
                                onChange={(e) => setCreditNoteAmount(c.id, e.target.value)}
                              />
                            </td>
                            <td className="px-2 py-1 text-right text-slate-500"><Money value={Math.max(0, c.remaining_balance - applied)} /></td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            {/* ── Total / Remaining ── */}
            <div className="flex items-center justify-end gap-6 rounded-lg border border-slate-200 bg-white px-4 py-2 shadow-sm">
              <div className="text-sm text-slate-600">Total: <span className="font-black text-slate-800">{fmt(target)}</span></div>
              <div className={`text-sm ${isUnderfunded ? 'text-amber-600' : isOverfunded ? 'text-sky-600' : 'text-emerald-600'}`}>
                Remaining: <span className="font-black">{fmt(remaining)}</span>
                {isOverfunded && <span className="ml-1 text-[10px] font-semibold">(overfunded → credit note on confirm)</span>}
              </div>
            </div>
          </>
        )}

        {/* ── Payment Details (Cash/Cheque/Card settlement lines) ── */}
        {showSummary && (
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <SectionHeader icon={Wallet} title="Payment Details" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100" />

            {isDraft && (
              <div className="grid grid-cols-1 gap-2 border-b border-slate-100 p-2.5 md:grid-cols-2 lg:grid-cols-4">
                <div>
                  <label className={LABEL_CLS}>Pay Mode</label>
                  <select
                    className={SELECT_CLS}
                    value={settlementDraft.payment_mode_id}
                    onChange={(e) => setSettlementDraft((d) => ({ ...d, payment_mode_id: e.target.value }))}
                  >
                    <option value="">— Select —</option>
                    {settleableModes.map((m) => <option key={m.id} value={m.id}>{m.payment_mode_name}</option>)}
                  </select>
                </div>
                <div>
                  <label className={LABEL_CLS}>Amount</label>
                  <input
                    type="number" min="0" step="0.01"
                    className={INPUT_CLS}
                    value={settlementDraft.amount}
                    onChange={(e) => setSettlementDraft((d) => ({ ...d, amount: e.target.value }))}
                  />
                </div>
                {selectedMode?.requires_bank_details && (
                  <>
                    {/* Feature disabled — shown for layout continuity but never interactive or submitted */}
                    <div className="order-last flex items-end pb-1.5">
                      <label className="flex cursor-not-allowed items-center gap-1.5 text-xs font-medium text-slate-400" title="This feature is disabled">
                        <input
                          type="checkbox"
                          checked={false}
                          disabled
                          readOnly
                          className="h-3.5 w-3.5 cursor-not-allowed rounded border-slate-300 opacity-50"
                        />
                        Is Thirdparty
                      </label>
                    </div>
                    <div>
                      <label className={LABEL_CLS}>Bank Name</label>
                      <select className={SELECT_CLS} value={settlementDraft.bank_name} onChange={(e) => setSettlementDraft((d) => ({ ...d, bank_name: e.target.value }))}>
                        <option value="">— Select —</option>
                        {BANK_NAMES.map((b) => <option key={b} value={b}>{b}</option>)}
                      </select>
                    </div>
                    <div>
                      <label className={LABEL_CLS}>Bank Account</label>
                      <input className={INPUT_CLS} value={settlementDraft.bank_account_no} onChange={(e) => setSettlementDraft((d) => ({ ...d, bank_account_no: e.target.value }))} />
                    </div>
                  </>
                )}
                {selectedMode?.requires_reference_no && (
                  <div>
                    <label className={LABEL_CLS}>{isChequeMode ? 'Cheque Number' : 'Card Number'}</label>
                    <input
                      className={isChequeMode && settlementDraft.reference_no && chequeNoInvalid ? INPUT_CLS.replace('border-slate-200', 'border-red-300') : INPUT_CLS}
                      inputMode={isChequeMode ? 'numeric' : undefined}
                      maxLength={isChequeMode ? 6 : undefined}
                      placeholder={isChequeMode ? '6 digits' : ''}
                      value={settlementDraft.reference_no}
                      onChange={(e) => {
                        const value = isChequeMode ? e.target.value.replace(/\D/g, '').slice(0, 6) : e.target.value
                        setSettlementDraft((d) => ({ ...d, reference_no: value }))
                      }}
                    />
                    {isChequeMode && settlementDraft.reference_no && chequeNoInvalid && (
                      <p className="mt-0.5 text-[10px] text-red-500">Cheque number must be exactly 6 digits.</p>
                    )}
                  </div>
                )}
                {selectedMode?.requires_date && (
                  <div>
                    <label className={LABEL_CLS}>{selectedMode.code === 'cheque' ? 'Cheque Date' : 'Instrument Date'}{isChequeMode && <span className="text-red-500"> *</span>}</label>
                    <input
                      type="date"
                      className={chequeDateInvalid ? INPUT_CLS.replace('border-slate-200', 'border-red-300') : INPUT_CLS}
                      value={settlementDraft.instrument_date}
                      onChange={(e) => setSettlementDraft((d) => ({ ...d, instrument_date: e.target.value }))}
                    />
                  </div>
                )}
                <div className="flex items-end">
                  <button type="button" onClick={addSettlement} className="flex items-center gap-1 rounded bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700">
                    <Plus size={12} /> Add
                  </button>
                </div>
              </div>
            )}

            {settlements.length === 0 ? (
              <p className="px-4 py-6 text-center text-xs text-slate-400">No payment mode lines added yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="bg-slate-50 border-b border-slate-200">
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Mode</th>
                      <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Amount</th>
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Bank</th>
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Bank Account</th>
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Ref No</th>
                      <th className="px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Date</th>
                      <th className="w-8"></th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {settlements.map((s) => (
                      <tr key={s._key} className="hover:bg-slate-50/60">
                        <td className="px-2 py-1 font-medium text-slate-700">{s.payment_mode_name}</td>
                        <td className="px-2 py-1 text-right text-slate-700"><Money value={s.amount} /></td>
                        <td className="px-2 py-1 text-slate-500">{s.bank_name || '—'}</td>
                        <td className="px-2 py-1 text-slate-500">{s.bank_account_no || '—'}</td>
                        <td className="px-2 py-1 text-slate-500">{s.reference_no || '—'}</td>
                        <td className="px-2 py-1 text-slate-500 whitespace-nowrap">{s.instrument_date || '—'}</td>
                        <td className="px-2 py-1 text-center">
                          {isDraft && (
                            <button type="button" onClick={() => removeSettlement(s._key)} className="rounded-md p-1 text-slate-300 hover:bg-red-50 hover:text-red-500" title="Remove">
                              <Trash2 size={12} />
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {err('balance') && (
          <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">{err('balance')}</div>
        )}
      </div>

      {/* ── Footer: totals + actions ── */}
      <div className="fixed bottom-0 left-0 right-0 z-10 border-t-2 border-slate-200 bg-white px-4 py-2 shadow-[0_-2px_8px_rgba(0,0,0,0.05)] lg:pl-64">
        <div className="mx-auto flex max-w-full items-center justify-end gap-4">
          {showSummary && (
            <div className={`text-sm ${isUnderfunded ? 'text-amber-600' : isOverfunded ? 'text-sky-600' : 'text-emerald-600'}`}>
              Remaining: <span className="text-base font-black">{fmt(remaining)}</span>
            </div>
          )}

          {isDraft && (
            <button
              type="button"
              disabled={saveMutation.isPending || isUnderfunded}
              onClick={handleSubmit}
              title={isUnderfunded ? 'Cover the full Total Payable before saving' : undefined}
              className="flex items-center gap-1 rounded bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm shadow-indigo-100 transition-all hover:bg-indigo-700 disabled:opacity-40 active:scale-95"
            >
              {saveMutation.isPending ? (<><RefreshCw size={11} className="animate-spin" /> Saving…</>) : (<><Save size={11} /> {isEdit ? 'Update' : 'Save Draft'}</>)}
            </button>
          )}

          {isEdit && status === 'draft' && (
            <button
              type="button"
              disabled={confirmMutation.isPending || isUnderfunded}
              onClick={handleConfirm}
              title={isUnderfunded ? 'Cover the full Total Payable before confirming' : undefined}
              className="flex items-center gap-1 rounded bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm shadow-emerald-100 transition-all hover:bg-emerald-700 disabled:opacity-40 active:scale-95"
            >
              {confirmMutation.isPending ? 'Confirming…' : 'Confirm Payment'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
