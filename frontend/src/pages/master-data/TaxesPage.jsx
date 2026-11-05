import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Edit2, Info, Lock, Percent, Save, X } from 'lucide-react'
import { createTax, deleteTax, getTax, getTaxes, updateTax } from '../../api/taxes'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Master Data', to: '/master-data/taxes' },
  { label: 'Taxes' },
]

const TYPES = [
  { value: 'vat',   label: 'VAT' },
  { value: 'sscl',  label: 'SSCL' },
  { value: 'other', label: 'Other' },
]

const today = () => new Date().toISOString().slice(0, 10)

const emptyForm = () => ({ code: '', name: '', tax_type: 'vat', rate: '', effective_from: today(), is_active: true })

const CODE_RE = /^[A-Za-z0-9_-]+$/

function validate(field, value, form) {
  const v = String(value ?? '').trim()
  if (field === 'code') {
    if (!v) return 'Code is required.'
    if (v.length > 20) return 'Max 20 characters.'
    if (!CODE_RE.test(v)) return 'Letters, numbers, hyphens and underscores only.'
  }
  if (field === 'name') {
    if (!v) return 'Name is required.'
    if (v.length > 100) return 'Max 100 characters.'
  }
  if (field === 'rate') {
    if (v === '') return 'Rate is required.'
    const n = Number(v)
    const max = form?.tax_type === 'sscl' ? 10 : 100
    if (Number.isNaN(n) || n < 0 || n > max) return `Enter 0 – ${max}.`
    if (!/^\d+(\.\d{1,2})?$/.test(v)) return 'Up to 2 decimals.'
  }
  if (field === 'effective_from' && !v) return 'Effective date is required.'
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
const inputRo =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-100 px-2 py-1 text-xs text-slate-500 outline-none cursor-not-allowed'

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

const FIELDS = ['code', 'name', 'rate', 'effective_from']

function TaxForm({ editId, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const firstRef    = useRef(null)

  const [form,     setForm]     = useState(emptyForm)
  const [isSystem, setIsSystem] = useState(false)
  const [errors,   setErrors]   = useState({})
  const [touched,  setTouched]  = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['tax', editId],
    queryFn:  () => getTax(editId),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    initialized.current = false
    setForm(emptyForm())
    setIsSystem(false)
    setErrors({})
    setTouched({})
  }, [editId])

  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const t = fetchedData.data
      setForm({
        code:           t.code ?? '',
        name:           t.name ?? '',
        tax_type:       t.tax_type ?? 'vat',
        rate:           t.rate != null ? String(t.rate) : '',
        effective_from: t.effective_from ?? today(),
        is_active:      t.is_active ?? true,
      })
      setIsSystem(Boolean(t.is_system))
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) firstRef.current?.focus()
  }, [isFetching, editId])

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target
    const newVal = type === 'checkbox' ? checked : value
    setForm((prev) => {
      const next = { ...prev, [name]: newVal }
      if (touched[name]) setErrors((p) => ({ ...p, [name]: validate(name, newVal, next) }))
      return next
    })
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value, form) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) => (isEditing ? updateTax(editId, payload) : createTax(payload)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['taxes'] })
      queryClient.invalidateQueries({ queryKey: ['taxes-current'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['tax', editId] })
      showSuccess(isEditing ? 'Tax updated.' : 'Tax rate added.')
      onDone()
    },
    onError: (err) => {
      const apiErrors = err.response?.data?.errors ?? {}
      if (Object.keys(apiErrors).length) {
        setErrors(Object.fromEntries(Object.entries(apiErrors).map(([k, v]) => [k, v[0]])))
        setTouched(Object.fromEntries(Object.keys(apiErrors).map((k) => [k, true])))
      }
      showError(err.response?.data?.message ?? 'Failed to save. Please check the form and try again.')
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    const newErrors = Object.fromEntries(FIELDS.map((f) => [f, validate(f, form[f], form)]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(FIELDS.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      code:           form.code.trim().toUpperCase(),
      name:           form.name.trim(),
      tax_type:       form.tax_type,
      rate:           Number(form.rate),
      effective_from: form.effective_from,
      is_active:      form.is_active,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
  }

  const fieldCls = (f) => (errors[f] && touched[f] ? inputErr : inputBase)

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">
      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Code <span className="text-red-500">*</span></label>
          <input
            ref={isSystem ? undefined : firstRef}
            name="code" type="text" value={form.code} onChange={handleChange} onBlur={handleBlur}
            placeholder="e.g. VAT" maxLength={20} autoComplete="off"
            readOnly={isSystem}
            title={isSystem ? 'Built-in tax — the code is what invoices and costings look up, so it cannot change' : undefined}
            className={isSystem ? inputRo : `${fieldCls('code')} uppercase`}
          />
          {errors.code && touched.code && <p className={ERR_CLS}>{errors.code}</p>}
        </div>
        <div>
          <label className={LABEL_CLS}>Type <span className="text-red-500">*</span></label>
          <select
            name="tax_type" value={form.tax_type} onChange={handleChange} disabled={isSystem}
            className={isSystem ? inputRo : `${inputBase} cursor-pointer`}
          >
            {TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
          </select>
          {errors.tax_type && <p className={ERR_CLS}>{errors.tax_type}</p>}
        </div>
      </div>

      <div>
        <label className={LABEL_CLS}>Name <span className="text-red-500">*</span></label>
        <input
          ref={isSystem ? firstRef : undefined}
          name="name" type="text" value={form.name} onChange={handleChange} onBlur={handleBlur}
          placeholder="e.g. Value Added Tax" maxLength={100} autoComplete="off" className={fieldCls('name')}
        />
        {errors.name && touched.name && <p className={ERR_CLS}>{errors.name}</p>}
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Rate % <span className="text-red-500">*</span></label>
          <input
            name="rate" type="number" min="0" max={form.tax_type === 'sscl' ? 10 : 100} step="0.01"
            value={form.rate} onChange={handleChange} onBlur={handleBlur} placeholder="0.00"
            className={`${fieldCls('rate')} text-right`}
          />
          {errors.rate && touched.rate && <p className={ERR_CLS}>{errors.rate}</p>}
        </div>
        <div>
          <label className={LABEL_CLS}>Effective From <span className="text-red-500">*</span></label>
          <input
            name="effective_from" type="date" value={form.effective_from} onChange={handleChange} onBlur={handleBlur}
            className={fieldCls('effective_from')}
          />
          {errors.effective_from && touched.effective_from && <p className={ERR_CLS}>{errors.effective_from}</p>}
        </div>
      </div>

      {form.tax_type === 'sscl' && form.code.toUpperCase() === 'SSCL_PUR' && (
        <p className="rounded border border-orange-100 bg-orange-50 px-2 py-1 text-[10px] leading-snug text-orange-800">
          <b>SSCL charged by suppliers</b> on their invoices (normally 2.5%). Used by the "SSCL + VAT" tax code on GRNs.
          It is a cost of the goods, never claimed back, and separate from your own SSCL on sales.
        </p>
      )}
      {form.tax_type === 'sscl' && form.code.toUpperCase() !== 'SSCL_PUR' && (
        <p className="rounded border border-sky-100 bg-sky-50 px-2 py-1 text-[10px] leading-snug text-sky-700">
          Enter the <b>effective</b> rate on turnover, e.g. 1.25 = 2.5% on 50% of a trader's turnover.
          While Active, each new invoice splits this SSCL out of Sales into SSCL Payable. Costings use it as their default SSCL %.
        </p>
      )}

      <div className="rounded border border-slate-100 bg-slate-50 p-2">
        <label className="flex cursor-pointer items-center gap-3">
          <div className="relative">
            <input type="checkbox" name="is_active" checked={form.is_active} onChange={handleChange} className="sr-only peer" />
            <div className="h-5 w-9 rounded-full bg-slate-200 transition-colors peer-checked:bg-indigo-600" />
            <div className="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
          </div>
          <span className="text-xs font-medium text-slate-700">{form.is_active ? 'Active' : 'Inactive (switched off)'}</span>
        </label>
        <p className="mt-0.5 text-[10px] text-slate-400">
          A rate change? Add a new row with the same code and a later Effective From date — older documents keep the old rate.
        </p>
      </div>

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
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Add Tax Rate'}
        </button>
      </div>
    </form>
  )
}

export default function TaxesPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['taxes', page],
    queryFn:  () => getTaxes(page),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteTax,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['taxes'] })
      queryClient.invalidateQueries({ queryKey: ['taxes-current'] })
      showSuccess('Tax rate deleted.')
    },
    onError: (err) => showError(err.response?.data?.message ?? 'Failed to delete.'),
  })

  const handleDelete = async (id, label) => {
    const ok = await confirmDelete(label)
    if (ok) {
      if (editId === id) setEditId(null)
      deleteMutation.mutate(id)
    }
  }

  const meta       = data?.meta
  const rows       = data?.data ?? []
  const currentIds = new Set(meta?.current_ids ?? [])
  const isEditMode = editId !== null

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Taxes</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="mt-2 flex items-start gap-2 rounded-lg border border-sky-100 bg-sky-50 px-3 py-1.5 text-[11px] leading-snug text-sky-800">
        <Info size={13} className="mt-0.5 shrink-0" />
        <span>
          These are the <b>rates set by law</b>, by date. Staff don't pick them directly: on a GRN they pick a{' '}
          <Link to="/master-data/tax-codes" className="font-semibold text-indigo-700 underline">Tax Code</Link>, which says which of these
          taxes apply and whether each can be claimed back.
        </span>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load taxes.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 z-10">
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                      <th className="w-16 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                      <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Rate</th>
                      <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Effective From</th>
                      <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-400">No taxes yet.</td>
                      </tr>
                    ) : (
                      rows.map((row) => {
                        const inForce = currentIds.has(row.id)
                        return (
                          <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                            <td className="px-3 py-2 font-mono font-semibold text-slate-700">
                              <span className="inline-flex items-center gap-1">
                                {row.code}
                                {row.is_system && <Lock size={10} className="text-slate-400" title="Built-in — cannot be deleted" />}
                              </span>
                            </td>
                            <td className="max-w-[16rem] truncate px-3 py-2 text-slate-700" title={row.name}>{row.name}</td>
                            <td className="px-3 py-2 text-slate-500">{row.tax_type_label}</td>
                            <td className="px-3 py-2 text-right font-semibold tabular-nums text-slate-800">{Number(row.rate).toFixed(2)}%</td>
                            <td className="px-3 py-2 tabular-nums text-slate-500">{row.effective_from}</td>
                            <td className="px-3 py-2">
                              <div className="flex flex-wrap items-center gap-1">
                                {row.is_active ? (
                                  <span className="inline-flex rounded px-2 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                                ) : (
                                  <span className="inline-flex rounded px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Off</span>
                                )}
                                {inForce && (
                                  <span className="inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold bg-indigo-50 text-indigo-600" title="The rate in force today">Current</span>
                                )}
                              </div>
                            </td>
                            <td className="px-3 py-2">
                              <div className="flex items-center justify-end gap-1">
                                {can('edit_taxes') && (
                                  <button
                                    type="button"
                                    title="Edit"
                                    onClick={() => setEditId(row.id)}
                                    className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                  >
                                    <Edit2 size={13} />
                                  </button>
                                )}
                                {can('delete_taxes') && !row.is_system && (
                                  <DeleteBtn onClick={() => handleDelete(row.id, `${row.code} ${row.rate}% from ${row.effective_from}`)} disabled={deleteMutation.isPending} />
                                )}
                              </div>
                            </td>
                          </tr>
                        )
                      })
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
              <Percent size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Tax' : 'New Tax Rate'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_taxes') || can('edit_taxes') ? (
            <TaxForm key={editId ?? 'create'} editId={editId} onDone={() => setEditId(null)} onCancel={() => setEditId(null)} />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage taxes.</div>
          )}
        </div>
      </div>
    </div>
  )
}
