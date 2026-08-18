import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Edit2, Save, X } from 'lucide-react'
import {
  createIndustry, deleteIndustry, getIndustries, getIndustry, updateIndustry,
} from '../../api/industries'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { ViewBtn, DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Industries' },
]

// ── Form helpers ─────────────────────────────────────────────────────────────
const EMPTY_FORM = { name: '', description: '' }

function validate(field, value) {
  switch (field) {
    case 'name':
      if (!String(value).trim()) return 'Industry name is required.'
      if (String(value).length > 100) return 'Max 100 characters.'
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
const fieldCls = (errors, touched, name) =>
  errors[name] && touched[name] ? inputErr : inputBase

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function FieldError({ errors, touched, name }) {
  if (!errors[name] || !touched[name]) return null
  return <p className={ERR_CLS}>{errors[name]}</p>
}

function SectionHeader({ icon: Icon, title, colorClass, extra }) {
  return (
    <div className={`flex items-center justify-between gap-1.5 px-3 py-2 border-b ${colorClass}`}>
      <div className="flex items-center gap-1.5">
        {Icon && <Icon size={13} />}
        <h2 className="text-xs font-bold">{title}</h2>
      </div>
      {extra}
    </div>
  )
}

// ── Inline form panel ─────────────────────────────────────────────────────────
function IndustryForm({ editId, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['industry', editId],
    queryFn:  () => getIndustry(editId),
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
      const ind = fetchedData.data
      setForm({ name: ind.name ?? '', description: ind.description ?? '' })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) nameRef.current?.focus()
  }, [isFetching, editId])

  const handleChange = (e) => {
    const { name, value } = e.target
    setForm((prev) => ({ ...prev, [name]: value }))
    if (touched[name]) setErrors((prev) => ({ ...prev, [name]: validate(name, value) }))
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) =>
      isEditing ? updateIndustry(editId, payload) : createIndustry(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['industries'] })
      queryClient.invalidateQueries({ queryKey: ['industries-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['industry', editId] })
      showSuccess(isEditing ? 'Industry updated.' : 'Industry created.')
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
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f])]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(fields.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      name:        form.name.trim(),
      description: form.description.trim() || null,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">

      {/* Name */}
      <div>
        <label className={LABEL_CLS}>
          Industry Name <span className="text-red-500">*</span>
        </label>
        <input
          ref={nameRef}
          name="name"
          type="text"
          value={form.name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Agriculture"
          maxLength={100}
          autoComplete="off"
          className={fieldCls(errors, touched, 'name')}
        />
        {errors.name && touched.name
          ? <FieldError errors={errors} touched={touched} name="name" />
          : <p className="mt-0.5 text-[10px] text-slate-400">{form.name.length}/100</p>}
      </div>

      {/* Description */}
      <div>
        <label className={LABEL_CLS}>
          Description <span className="font-normal normal-case text-slate-400">(optional)</span>
        </label>
        <div className="relative">
          <textarea
            name="description"
            rows={3}
            value={form.description}
            onChange={handleChange}
            onBlur={handleBlur}
            maxLength={255}
            placeholder="Optional notes about this industry…"
            className={`${fieldCls(errors, touched, 'description')} resize-none pb-4`}
          />
          <span className="absolute bottom-1 right-2 text-[10px] text-slate-400">
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
      <div className="flex items-center justify-end gap-2 pt-0.5">
        {isEditing && (
          <button
            type="button"
            onClick={onCancel}
            className="flex items-center gap-1 rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-200"
          >
            <X size={11} />
            Cancel
          </button>
        )}
        <button
          type="submit"
          disabled={mutation.isPending}
          className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Save size={12} strokeWidth={2.5} />
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Industry'}
        </button>
      </div>
    </form>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function IndustriesPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['industries', page],
    queryFn:  () => getIndustries(page),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteIndustry,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['industries'] })
      showSuccess('Industry deleted.')
    },
    onError: () => showError('Failed to delete. The industry may be in use.'),
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
        <h1 className="text-xl font-bold leading-none text-slate-800">Industries</h1>
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
              Failed to load industries.
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
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Description</th>
                      <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                      <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={5} className="px-4 py-8 text-center text-sm text-slate-400">
                          No industries yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((industry, i) => (
                        <tr
                          key={industry.id}
                          className={`transition-colors hover:bg-slate-50 ${editId === industry.id ? 'bg-indigo-50/60' : ''}`}
                        >
                          <td className="px-3 py-2 text-slate-400">
                            {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                          </td>
                          <td className="px-3 py-2 font-medium text-slate-800">
                            <Link
                              to={`/inventory/industries/${industry.id}`}
                              className="hover:text-indigo-600 hover:underline"
                            >
                              {industry.name}
                            </Link>
                          </td>
                          <td className="max-w-xs px-3 py-2 text-slate-500">
                            {industry.description
                              ? <span className="block truncate" title={industry.description}>{industry.description}</span>
                              : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                            {new Date(industry.created_at).toLocaleDateString()}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              <ViewBtn to={`/inventory/industries/${industry.id}`} />
                              {can('edit_industries') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(industry.id)}
                                  className={`rounded p-1 transition-colors ${editId === industry.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_industries') && (
                                <DeleteBtn onClick={() => handleDelete(industry.id, industry.name)} disabled={deleteMutation.isPending} />
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
          <SectionHeader
            icon={Building2}
            title={editId ? 'Edit Industry' : 'New Industry'}
            colorClass="text-indigo-700 bg-indigo-50 border-indigo-100"
            extra={editId && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          />

          {can('create_industries') || can('edit_industries') ? (
            <IndustryForm
              key={editId ?? 'create'}
              editId={editId}
              onDone={handleDone}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">
              You don't have permission to manage industries.
            </div>
          )}
        </div>

      </div>
    </div>
  )
}
