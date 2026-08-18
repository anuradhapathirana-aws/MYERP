import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CreditCard, Edit2, Save, X } from 'lucide-react'
import {
  createPaymentMode, deletePaymentMode, getPaymentMode, getPaymentModes, updatePaymentMode,
} from '../../api/paymentModes'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/payment-modes' },
  { label: 'Payment Modes' },
]

const EMPTY_FORM = {
  payment_mode_name: '', code: '', requires_bank_details: false,
  requires_reference_no: false, requires_date: false, sort_order: 0, is_active: true,
}

function validate(field, value) {
  if (field === 'payment_mode_name') {
    if (!String(value).trim()) return 'Payment mode name is required.'
    if (String(value).length > 50) return 'Max 50 characters.'
  }
  if (field === 'code') {
    if (!String(value).trim()) return 'Code is required.'
    if (String(value).length > 30) return 'Max 30 characters.'
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function Toggle({ name, checked, onChange, label, hint }) {
  return (
    <label className="flex cursor-pointer items-center gap-2 rounded border border-slate-100 bg-slate-50 p-1.5">
      <div className="relative shrink-0">
        <input type="checkbox" name={name} checked={checked} onChange={onChange} className="sr-only peer" />
        <div className="h-4 w-7 rounded-full bg-slate-200 transition-colors peer-checked:bg-indigo-600" />
        <div className="absolute left-0.5 top-0.5 h-3 w-3 rounded-full bg-white shadow transition-transform peer-checked:translate-x-3" />
      </div>
      <div>
        <span className="text-[11px] font-medium text-slate-700">{label}</span>
        {hint && <p className="text-[9px] text-slate-400">{hint}</p>}
      </div>
    </label>
  )
}

// ── Inline form panel ───────────────────────────────────────────────────────
function PaymentModeForm({ editId, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['payment-mode', editId],
    queryFn:  () => getPaymentMode(editId),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    initialized.current = false
    setForm(EMPTY_FORM)
    setErrors({})
    setTouched({})
  }, [editId])

  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const m = fetchedData.data
      setForm({
        payment_mode_name:     m.payment_mode_name     ?? '',
        code:                  m.code                  ?? '',
        requires_bank_details: m.requires_bank_details  ?? false,
        requires_reference_no: m.requires_reference_no  ?? false,
        requires_date:         m.requires_date          ?? false,
        sort_order:            m.sort_order             ?? 0,
        is_active:             m.is_active              ?? true,
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) nameRef.current?.focus()
  }, [isFetching, editId])

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target
    const newVal = type === 'checkbox' ? checked : value
    setForm((prev) => ({ ...prev, [name]: newVal }))
    if (touched[name]) setErrors((prev) => ({ ...prev, [name]: validate(name, newVal) }))
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) =>
      isEditing ? updatePaymentMode(editId, payload) : createPaymentMode(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-modes'] })
      queryClient.invalidateQueries({ queryKey: ['payment-modes-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['payment-mode', editId] })
      showSuccess(isEditing ? 'Payment mode updated.' : 'Payment mode created.')
      onDone()
    },
    onError: (err) => {
      const apiErrors = err.response?.data?.errors ?? {}
      if (Object.keys(apiErrors).length) {
        setErrors(Object.fromEntries(Object.entries(apiErrors).map(([k, v]) => [k, v[0]])))
        setTouched(Object.fromEntries(Object.keys(apiErrors).map((k) => [k, true])))
      }
      showError('Failed to save. Please check the form and try again.')
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    const newErrors = {
      payment_mode_name: validate('payment_mode_name', form.payment_mode_name),
      code:               validate('code', form.code),
    }
    setErrors(newErrors)
    setTouched({ payment_mode_name: true, code: true })
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      payment_mode_name:     form.payment_mode_name.trim(),
      code:                  form.code.trim().toLowerCase(),
      requires_bank_details: form.requires_bank_details,
      requires_reference_no: form.requires_reference_no,
      requires_date:         form.requires_date,
      sort_order:            parseInt(form.sort_order) || 0,
      is_active:             form.is_active,
    })
  }

  if (isEditing && isFetching) {
    return (
      <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
    )
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">
      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>
            Name <span className="text-red-500">*</span>
          </label>
          <input
            ref={nameRef}
            name="payment_mode_name"
            type="text"
            value={form.payment_mode_name}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="e.g. Cash, Cheque"
            maxLength={50}
            autoComplete="off"
            className={errors.payment_mode_name && touched.payment_mode_name ? inputErr : inputBase}
          />
          {errors.payment_mode_name && touched.payment_mode_name && <p className={ERR_CLS}>{errors.payment_mode_name}</p>}
        </div>
        <div>
          <label className={LABEL_CLS}>
            Code <span className="text-red-500">*</span>
          </label>
          <input
            name="code"
            type="text"
            value={form.code}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="e.g. cash, cheque"
            maxLength={30}
            autoComplete="off"
            className={errors.code && touched.code ? inputErr : inputBase}
          />
          {errors.code && touched.code && <p className={ERR_CLS}>{errors.code}</p>}
        </div>
      </div>

      <div>
        <label className={LABEL_CLS}>Sort Order</label>
        <input
          name="sort_order"
          type="number"
          value={form.sort_order}
          onChange={handleChange}
          className={inputBase}
        />
      </div>

      <div className="space-y-1.5">
        <Toggle name="requires_bank_details" checked={form.requires_bank_details} onChange={handleChange} label="Requires Bank Details" hint="Shows Bank Name / Account No fields" />
        <Toggle name="requires_reference_no" checked={form.requires_reference_no} onChange={handleChange} label="Requires Reference No" hint="Shows Cheque/Card No field" />
        <Toggle name="requires_date"         checked={form.requires_date}         onChange={handleChange} label="Requires Instrument Date" hint="Shows a date field (e.g. cheque date)" />
      </div>

      <div className="rounded border border-slate-100 bg-slate-50 p-2">
        <label className="flex cursor-pointer items-center gap-3">
          <div className="relative">
            <input type="checkbox" name="is_active" checked={form.is_active} onChange={handleChange} className="sr-only peer" />
            <div className="h-5 w-9 rounded-full bg-slate-200 transition-colors peer-checked:bg-indigo-600" />
            <div className="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
          </div>
          <span className="text-xs font-medium text-slate-700">{form.is_active ? 'Active' : 'Inactive'}</span>
        </label>
        <p className="mt-0.5 text-[10px] text-slate-400">Inactive modes are hidden from the payment settlement dropdown.</p>
      </div>

      {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
        <p className={ERR_CLS}>{mutation.error?.response?.data?.message ?? 'An unexpected error occurred.'}</p>
      )}

      <div className="flex items-center justify-end gap-2 pt-0.5">
        {isEditing && (
          <button type="button" onClick={onCancel} className="flex items-center gap-1 rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-200">
            <X size={11} /> Cancel
          </button>
        )}
        <button
          type="submit"
          disabled={mutation.isPending}
          className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Save size={12} strokeWidth={2.5} />
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Payment Mode'}
        </button>
      </div>
    </form>
  )
}

// ── Main page ───────────────────────────────────────────────────────────────
export default function PaymentModesPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['payment-modes', page],
    queryFn:  () => getPaymentModes(page),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deletePaymentMode,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-modes'] })
      showSuccess('Payment mode deleted.')
    },
    onError: () => showError('Failed to delete. The payment mode may be in use.'),
  })

  const handleDelete = async (id, name) => {
    const ok = await confirmDelete(name)
    if (ok) {
      if (editId === id) setEditId(null)
      deleteMutation.mutate(id)
    }
  }

  const handleDone = () => setEditId(null)

  const meta = data?.meta
  const rows = data?.data ?? []
  const isEditMode = editId !== null

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Payment Modes</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load payment modes.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Requires</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-400">
                          No payment modes yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((row, i) => (
                        <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                          <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.payment_mode_name}</td>
                          <td className="px-3 py-2 font-mono text-slate-500">{row.code}</td>
                          <td className="px-3 py-2 text-slate-500">
                            <div className="flex flex-wrap gap-1">
                              {row.requires_bank_details && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold">Bank</span>}
                              {row.requires_reference_no && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold">Ref No</span>}
                              {row.requires_date         && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold">Date</span>}
                              {!row.requires_bank_details && !row.requires_reference_no && !row.requires_date && <span className="italic text-slate-300">—</span>}
                            </div>
                          </td>
                          <td className="px-3 py-2">
                            {row.is_active ? (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                            ) : (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Inactive</span>
                            )}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              {can('edit_payment_modes') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(row.id)}
                                  className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_payment_modes') && (
                                <DeleteBtn onClick={() => handleDelete(row.id, row.payment_mode_name)} disabled={deleteMutation.isPending} />
                              )}
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              <Pagination meta={meta} page={page} onPageChange={setPage} />
            </>
          )}
        </div>

        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm self-start">
          <div className="flex items-center justify-between gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <CreditCard size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Payment Mode' : 'New Payment Mode'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_payment_modes') || can('edit_payment_modes') ? (
            <PaymentModeForm key={editId ?? 'create'} editId={editId} onDone={handleDone} onCancel={() => setEditId(null)} />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage payment modes.</div>
          )}
        </div>
      </div>
    </div>
  )
}
