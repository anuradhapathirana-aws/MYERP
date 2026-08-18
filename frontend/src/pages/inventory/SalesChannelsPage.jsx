import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Save, ShoppingBag, Trash2, X } from 'lucide-react'
import {
  createSalesChannel,
  deleteSalesChannel,
  getSalesChannel,
  getSalesChannels,
  updateSalesChannel,
} from '../../api/salesChannels'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { ViewBtn, DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Sales Channels' },
]

const CHANNEL_TYPES  = ['Wholesale', 'e-commerce', 'Retail']
const STATUS_OPTIONS = ['Active', 'Inactive']

const TYPE_BADGE = {
  'Wholesale':  'bg-violet-50 text-violet-700',
  'e-commerce': 'bg-sky-50 text-sky-700',
  'Retail':     'bg-emerald-50 text-emerald-700',
}
const STATUS_BADGE = {
  'Active':   'bg-green-50 text-green-700',
  'Inactive': 'bg-slate-100 text-slate-500',
}

// ── Form helpers ─────────────────────────────────────────────────────────────
const EMPTY_FORM = {
  type:               '',
  sales_channel_name: '',
  max_qty:            '',
  applicable_from:    '',
  applicable_to:      '',
  description:        '',
  status:             '',
}

function validate(field, value, allValues = {}) {
  switch (field) {
    case 'type':
      if (!value) return 'Channel type is required.'
      break
    case 'sales_channel_name':
      if (!String(value).trim()) return 'Channel name is required.'
      if (String(value).length > 100) return 'Max 100 characters.'
      break
    case 'max_qty':
      if (value !== '' && (isNaN(Number(value)) || Number(value) < 0))
        return 'Must be a positive number.'
      break
    case 'applicable_to':
      if (value && allValues.applicable_from && value < allValues.applicable_from)
        return 'End date must be on or after start date.'
      break
    case 'description':
      if (String(value).length > 255) return 'Max 255 characters.'
      break
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'
const fieldCls = (errors, touched, name) =>
  errors[name] && touched[name] ? inputErr : inputBase

function FieldError({ errors, touched, name }) {
  if (!errors[name] || !touched[name]) return null
  return <p className={ERR_CLS}>{errors[name]}</p>
}

// ── Inline form panel ─────────────────────────────────────────────────────────
function SalesChannelForm({ editId, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['sales-channel', editId],
    queryFn:  () => getSalesChannel(editId),
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
      const c = fetchedData.data
      setForm({
        type:               c.type               ?? '',
        sales_channel_name: c.sales_channel_name ?? '',
        max_qty:            c.max_qty != null ? String(c.max_qty) : '',
        applicable_from:    c.applicable_from    ?? '',
        applicable_to:      c.applicable_to      ?? '',
        description:        c.description        ?? '',
        status:             c.status             ?? '',
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) nameRef.current?.focus()
  }, [isFetching, editId])

  const handleChange = (e) => {
    const { name, value } = e.target
    setForm((prev) => ({ ...prev, [name]: value }))
    if (touched[name])
      setErrors((prev) => ({ ...prev, [name]: validate(name, value, { ...form, [name]: value }) }))
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value, form) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) =>
      isEditing ? updateSalesChannel(editId, payload) : createSalesChannel(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-channels'] })
      queryClient.invalidateQueries({ queryKey: ['sales-channels-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['sales-channel', editId] })
      showSuccess(isEditing ? 'Sales channel updated.' : 'Sales channel created.')
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
    const fields    = Object.keys(EMPTY_FORM)
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f], form)]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(fields.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      type:               form.type,
      sales_channel_name: form.sales_channel_name.trim(),
      max_qty:            form.max_qty !== '' ? Number(form.max_qty) : null,
      applicable_from:    form.applicable_from || null,
      applicable_to:      form.applicable_to   || null,
      description:        form.description.trim() || null,
      status:             form.status || null,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-8 text-xs text-slate-400">Loading…</div>
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">

      {/* Channel Type */}
      <div>
        <label className={LABEL_CLS}>
          Channel Type <span className="text-red-500">*</span>
        </label>
        <select name="type" value={form.type} onChange={handleChange} onBlur={handleBlur} className={fieldCls(errors, touched, 'type')}>
          <option value="">— Select —</option>
          {CHANNEL_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <FieldError errors={errors} touched={touched} name="type" />
      </div>

      {/* Channel Name */}
      <div>
        <label className={LABEL_CLS}>
          Channel Name <span className="text-red-500">*</span>
        </label>
        <input
          ref={nameRef}
          name="sales_channel_name"
          type="text"
          value={form.sales_channel_name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Main Retail Store"
          maxLength={100}
          autoComplete="off"
          className={fieldCls(errors, touched, 'sales_channel_name')}
        />
        <FieldError errors={errors} touched={touched} name="sales_channel_name" />
      </div>

      {/* Status */}
      <div>
        <label className={LABEL_CLS}>Status</label>
        <select name="status" value={form.status} onChange={handleChange} onBlur={handleBlur} className={fieldCls(errors, touched, 'status')}>
          <option value="">— Select —</option>
          {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <FieldError errors={errors} touched={touched} name="status" />
      </div>

      {/* Max Quantity */}
      <div>
        <label className={LABEL_CLS}>
          Max Quantity <span className="text-[10px] font-normal text-slate-400">(optional)</span>
        </label>
        <input
          name="max_qty"
          type="number"
          min="0"
          step="any"
          value={form.max_qty}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. 1000"
          className={fieldCls(errors, touched, 'max_qty')}
        />
        <FieldError errors={errors} touched={touched} name="max_qty" />
      </div>

      {/* Date range */}
      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Applicable From</label>
          <input
            name="applicable_from"
            type="date"
            value={form.applicable_from}
            onChange={handleChange}
            onBlur={handleBlur}
            className={fieldCls(errors, touched, 'applicable_from')}
          />
          <FieldError errors={errors} touched={touched} name="applicable_from" />
        </div>
        <div>
          <label className={LABEL_CLS}>Applicable To</label>
          <input
            name="applicable_to"
            type="date"
            value={form.applicable_to}
            onChange={handleChange}
            onBlur={handleBlur}
            className={fieldCls(errors, touched, 'applicable_to')}
          />
          <FieldError errors={errors} touched={touched} name="applicable_to" />
        </div>
      </div>

      {/* Description */}
      <div>
        <label className={LABEL_CLS}>
          Description <span className="text-[10px] font-normal text-slate-400">(optional)</span>
        </label>
        <div className="relative">
          <textarea
            name="description"
            rows={2}
            value={form.description}
            onChange={handleChange}
            onBlur={handleBlur}
            maxLength={255}
            placeholder="Optional notes about this channel…"
            className={`${fieldCls(errors, touched, 'description')} resize-none pb-5`}
          />
          <span className="absolute bottom-1.5 right-2 text-[10px] text-slate-400">
            {form.description.length}/255
          </span>
        </div>
        <FieldError errors={errors} touched={touched} name="description" />
      </div>

      {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
        <p className="text-[10px] text-red-600">
          {mutation.error?.response?.data?.message ?? 'An unexpected error occurred.'}
        </p>
      )}

      {/* Actions */}
      <div className="flex items-center justify-end gap-1.5 pt-0.5">
        {isEditing && (
          <button
            type="button"
            onClick={onCancel}
            className="flex items-center gap-1 rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-200"
          >
            <X size={12} />
            Cancel
          </button>
        )}
        <button
          type="submit"
          disabled={mutation.isPending}
          className="flex items-center gap-1.5 rounded bg-indigo-600 px-4 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Save size={12} strokeWidth={2.5} />
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Channel'}
        </button>
      </div>
    </form>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function SalesChannelsPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sales-channels', page],
    queryFn:  () => getSalesChannels(page),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteSalesChannel,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-channels'] })
      showSuccess('Sales channel deleted.')
    },
    onError: () => showError('Failed to delete. The sales channel may be in use.'),
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

  return (
    <div className="w-full">
      <div>
        <h1 className="text-xl font-bold leading-none text-slate-800">Sales Channels</h1>
        <Breadcrumb crumbs={CRUMBS} />
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">

        {/* ── LEFT: Table ─────────────────────────────────────────────── */}
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          {isLoading && (
            <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
          )}
          {isError && (
            <div className="flex items-center justify-center py-14 text-sm text-red-500">
              Failed to load sales channels.
            </div>
          )}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                      <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                      <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Max Qty</th>
                      <th className="w-22 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">From</th>
                      <th className="w-22 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">To</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="px-4 py-8 text-center text-sm text-slate-400">
                          No sales channels yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((c, i) => (
                        <tr
                          key={c.id}
                          className={`transition-colors hover:bg-slate-50 ${editId === c.id ? 'bg-indigo-50/60' : ''}`}
                        >
                          <td className="px-3 py-2 text-slate-400">
                            {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                          </td>
                          <td className="px-3 py-2 font-medium text-slate-800">
                            <Link to={`/inventory/sales-channels/${c.id}`} className="hover:text-indigo-600 hover:underline">
                              {c.sales_channel_name}
                            </Link>
                          </td>
                          <td className="px-3 py-2">
                            {c.type
                              ? <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${TYPE_BADGE[c.type] ?? 'bg-slate-100 text-slate-500'}`}>{c.type}</span>
                              : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2 text-right text-slate-600">
                            {c.max_qty != null ? Number(c.max_qty).toLocaleString() : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-500">
                            {c.applicable_from ?? <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-500">
                            {c.applicable_to ?? <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2">
                            {c.status
                              ? <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${STATUS_BADGE[c.status] ?? 'bg-slate-100 text-slate-400'}`}>{c.status}</span>
                              : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              <ViewBtn to={`/inventory/sales-channels/${c.id}`} />
                              {can('edit_sales_channels') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(c.id)}
                                  className={`rounded p-1 transition-colors ${editId === c.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_sales_channels') && (
                                <DeleteBtn onClick={() => handleDelete(c.id, c.sales_channel_name)} disabled={deleteMutation.isPending} />
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

        {/* ── RIGHT: Form panel ───────────────────────────────────────── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm self-start">
          <div className="flex items-center justify-between border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <ShoppingBag size={13} />
              <h2 className="text-xs font-bold">
                {editId ? 'Edit Channel' : 'New Channel'}
              </h2>
            </div>
            {editId && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-600">
                <Edit2 size={10} /> Editing
              </span>
            )}
          </div>

          {can('create_sales_channels') || can('edit_sales_channels') ? (
            <SalesChannelForm
              key={editId ?? 'create'}
              editId={editId}
              onDone={handleDone}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">
              You don't have permission to manage sales channels.
            </div>
          )}
        </div>

      </div>
    </div>
  )
}
