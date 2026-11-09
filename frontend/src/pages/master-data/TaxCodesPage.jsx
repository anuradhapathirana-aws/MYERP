import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Edit2, Info, Lock, Plus, Receipt, Save, Trash2, X } from 'lucide-react'
import { createTaxCode, deleteTaxCode, getTaxCode, getTaxCodes, updateTaxCode } from '../../api/taxCodes'
import { getTaxes } from '../../api/taxes'
import HelpTip from '../../components/ui/HelpTip'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Master Data', to: '/master-data/tax-codes' },
  { label: 'Tax Codes' },
]

// Fallback until the list loads; the server sends the same set in meta.vat_categories.
const DEFAULT_CATEGORIES = [
  { value: 'standard',     label: 'Standard rated',                carries_tax: true },
  { value: 'zero_rated',   label: 'Zero rated',                    carries_tax: false },
  { value: 'exempt',       label: 'Exempt',                        carries_tax: false },
  { value: 'out_of_scope', label: 'No Tax Invoice / out of scope', carries_tax: false },
  { value: 'import',       label: 'Import',                        carries_tax: false },
]

const CATEGORY_BADGE = {
  standard:     'bg-indigo-50 text-indigo-700',
  zero_rated:   'bg-sky-50 text-sky-700',
  exempt:       'bg-emerald-50 text-emerald-700',
  out_of_scope: 'bg-slate-100 text-slate-600',
  import:       'bg-amber-50 text-amber-700',
}

const MAX_COMPONENTS = 4
const CODE_RE = /^[A-Za-z0-9_-]+$/

const emptyComponent = () => ({ tax_code_ref: '', is_compound: false, is_recoverable: true })
const emptyForm      = () => ({ code: '', name: '', vat_category: 'standard', is_active: true, sort_order: 0, components: [emptyComponent()] })

function validateField(field, value) {
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
  return ''
}

function validateComponents(form, carriesTax) {
  if (!carriesTax) return ''
  if (!form.components.length) return 'Add at least one tax.'
  if (form.components.some((c) => !c.tax_code_ref)) return 'Pick a tax on every row.'
  const refs = form.components.map((c) => c.tax_code_ref)
  if (new Set(refs).size !== refs.length) return 'The same tax is listed twice.'
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

/** "SSCL_PUR 2.5% → VAT 18% on top", each tax coloured by whether it can be claimed. */
function ComponentsSummary({ components }) {
  if (!components?.length) return <span className="text-slate-400">No tax</span>
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      {components.map((c, i) => (
        <span key={c.tax_code_ref} className="inline-flex items-center gap-1">
          {i > 0 && <span className="text-slate-300">→</span>}
          <span
            className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${c.is_recoverable ? 'bg-green-50 text-green-700' : 'bg-orange-50 text-orange-700'}`}
            title={c.is_recoverable ? 'Claimable as Input VAT' : 'Not claimable — added to the cost of the goods'}
          >
            {c.tax_code_ref} {Number(c.rate)}%{c.is_compound && i > 0 ? ' on top' : ''}
          </span>
        </span>
      ))}
    </span>
  )
}

function TaxCodeForm({ editId, categories, taxOptions, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const firstRef    = useRef(null)

  const [form,     setForm]     = useState(emptyForm)
  const [isSystem, setIsSystem] = useState(false)
  const [errors,   setErrors]   = useState({})
  const [touched,  setTouched]  = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['tax-code', editId],
    queryFn:  () => getTaxCode(editId),
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
        code:         t.code ?? '',
        name:         t.name ?? '',
        vat_category: t.vat_category ?? 'standard',
        is_active:    t.is_active ?? true,
        sort_order:   t.sort_order ?? 0,
        components:   (t.components ?? []).map((c) => ({
          tax_code_ref:   c.tax_code_ref,
          is_compound:    Boolean(c.is_compound),
          is_recoverable: Boolean(c.is_recoverable),
        })),
      })
      setIsSystem(Boolean(t.is_system))
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) firstRef.current?.focus()
  }, [isFetching, editId])

  const category   = categories.find((c) => c.value === form.vat_category)
  const carriesTax = category?.carries_tax ?? true
  const locked     = isSystem

  const setField = (name, value) => {
    setForm((prev) => ({ ...prev, [name]: value }))
    if (touched[name]) setErrors((p) => ({ ...p, [name]: validateField(name, value) }))
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validateField(name, value) }))
  }

  const setComponent = (index, patch) =>
    setForm((prev) => ({ ...prev, components: prev.components.map((c, i) => (i === index ? { ...c, ...patch } : c)) }))

  const moveComponent = (index, dir) =>
    setForm((prev) => {
      const next = [...prev.components]
      const to   = index + dir
      if (to < 0 || to >= next.length) return prev
      ;[next[index], next[to]] = [next[to], next[index]]
      return { ...prev, components: next }
    })

  const mutation = useMutation({
    mutationFn: (payload) => (isEditing ? updateTaxCode(editId, payload) : createTaxCode(payload)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tax-codes'] })
      queryClient.invalidateQueries({ queryKey: ['tax-code-options'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['tax-code', editId] })
      showSuccess(isEditing ? 'Tax code updated.' : 'Tax code added.')
      onDone()
    },
    onError: (err) => {
      const apiErrors = err.response?.data?.errors ?? {}
      if (Object.keys(apiErrors).length) {
        const flat = Object.fromEntries(Object.entries(apiErrors).map(([k, v]) => [k.startsWith('components') ? 'components' : k, v[0]]))
        setErrors(flat)
        setTouched(Object.fromEntries(Object.keys(flat).map((k) => [k, true])))
      }
      showError(err.response?.data?.message ?? 'Failed to save. Please check the form and try again.')
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    const newErrors = {
      code:       validateField('code', form.code),
      name:       validateField('name', form.name),
      components: validateComponents(form, carriesTax),
    }
    setErrors(newErrors)
    setTouched({ code: true, name: true, components: true })
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      code:         form.code.trim().toUpperCase(),
      name:         form.name.trim(),
      vat_category: form.vat_category,
      is_active:    form.is_active,
      sort_order:   Number(form.sort_order) || 0,
      components:   carriesTax ? form.components : [],
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
  }

  const fieldCls = (f) => (errors[f] && touched[f] ? inputErr : inputBase)

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">
      {locked && (
        <p className="flex items-start gap-1 rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] leading-snug text-slate-600">
          <Lock size={10} className="mt-0.5 shrink-0" />
          Built-in code: its taxes are fixed because GRNs and suppliers rely on what it means. You can rename it or switch it off.
        </p>
      )}

      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Code <span className="text-red-500">*</span></label>
          <input
            ref={locked ? undefined : firstRef}
            name="code" type="text" value={form.code} onChange={(e) => setField('code', e.target.value)} onBlur={handleBlur}
            placeholder="e.g. VAT-IN" maxLength={20} autoComplete="off" readOnly={locked}
            className={locked ? inputRo : `${fieldCls('code')} uppercase`}
          />
          {errors.code && touched.code && <p className={ERR_CLS}>{errors.code}</p>}
        </div>
        <div>
          <label className={LABEL_CLS}>VAT Category <span className="text-red-500">*</span> <HelpTip text="The kind of tax this code is. Standard rated charges the taxes listed below. Zero rated, Exempt, No Tax Invoice and Import charge no tax on the document." /></label>
          <select
            name="vat_category" value={form.vat_category} disabled={locked}
            onChange={(e) => {
              const next = categories.find((c) => c.value === e.target.value)
              setForm((prev) => ({
                ...prev,
                vat_category: e.target.value,
                components: next?.carries_tax ? (prev.components.length ? prev.components : [emptyComponent()]) : [],
              }))
            }}
            className={locked ? inputRo : `${inputBase} cursor-pointer`}
          >
            {categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
          </select>
          {errors.vat_category && <p className={ERR_CLS}>{errors.vat_category}</p>}
        </div>
      </div>

      <div>
        <label className={LABEL_CLS}>Name <span className="text-red-500">*</span></label>
        <input
          ref={locked ? firstRef : undefined}
          name="name" type="text" value={form.name} onChange={(e) => setField('name', e.target.value)} onBlur={handleBlur}
          placeholder="e.g. VAT (Tax Invoice)" maxLength={100} autoComplete="off" className={fieldCls('name')}
        />
        {errors.name && touched.name && <p className={ERR_CLS}>{errors.name}</p>}
      </div>

      {/* ── Taxes in this code ─────────────────────────────────────────────── */}
      <div className="rounded border border-slate-200">
        <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-2 py-1">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Taxes, in order <HelpTip text="The taxes this code applies, from top to bottom. Order matters: a tax ticked 'On top of taxes above' includes only the taxes listed above it." /></span>
          {carriesTax && !locked && form.components.length < MAX_COMPONENTS && (
            <button
              type="button"
              onClick={() => setForm((prev) => ({ ...prev, components: [...prev.components, { ...emptyComponent(), is_compound: prev.components.length > 0 }] }))}
              className="flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-semibold text-indigo-600 hover:bg-indigo-50"
            >
              <Plus size={10} /> Add tax
            </button>
          )}
        </div>

        {!carriesTax ? (
          <p className="px-2 py-1.5 text-[10px] text-slate-500">
            A <b>{category?.label}</b> code carries no tax: the whole price is the cost of the goods.
          </p>
        ) : (
          <div className="divide-y divide-slate-100">
            {form.components.map((c, i) => (
              <div key={i} className="grid grid-cols-[1fr_auto] items-center gap-1 px-2 py-1">
                <div className="flex flex-col gap-1">
                  <select
                    value={c.tax_code_ref} disabled={locked}
                    onChange={(e) => setComponent(i, { tax_code_ref: e.target.value })}
                    className={locked ? inputRo : `${inputBase} cursor-pointer`}
                  >
                    <option value="">— pick a tax —</option>
                    {taxOptions.map((t) => (
                      <option key={t.code} value={t.code}>{t.code} · {t.name} ({Number(t.rate)}%)</option>
                    ))}
                  </select>
                  <div className="flex flex-wrap items-center gap-3 text-[10px] text-slate-600">
                    <label className={`flex items-center gap-1 ${i === 0 ? 'opacity-40' : ''}`}>
                      <input type="checkbox" checked={c.is_compound} disabled={locked || i === 0} onChange={(e) => setComponent(i, { is_compound: e.target.checked })} />
                      On top of taxes above
                      <HelpTip text="Not ticked: this tax is calculated on the price only. Ticked: on the price plus the taxes above it. Example on 100,000: SSCL 2,500, then VAT 18% on 102,500 = 18,450. Tick only if the supplier's invoice calculates it this way." />
                    </label>
                    <label className="flex items-center gap-1">
                      <input type="checkbox" checked={c.is_recoverable} disabled={locked} onChange={(e) => setComponent(i, { is_recoverable: e.target.checked })} />
                      Claimable
                      <HelpTip text="Ticked: this tax goes to Input VAT and is claimed back. Not ticked: it is added to the cost of the goods. Untick for SSCL, and for VAT without a valid Tax Invoice." />
                    </label>
                  </div>
                </div>
                {!locked && (
                  <div className="flex flex-col items-center">
                    <button type="button" title="Move up" onClick={() => moveComponent(i, -1)} disabled={i === 0} className="rounded p-0.5 text-slate-400 hover:bg-slate-100 disabled:opacity-30"><ArrowUp size={11} /></button>
                    <button type="button" title="Remove" onClick={() => setForm((prev) => ({ ...prev, components: prev.components.filter((_, j) => j !== i) }))} className="rounded p-0.5 text-red-500 hover:bg-red-50"><Trash2 size={11} /></button>
                    <button type="button" title="Move down" onClick={() => moveComponent(i, 1)} disabled={i === form.components.length - 1} className="rounded p-0.5 text-slate-400 hover:bg-slate-100 disabled:opacity-30"><ArrowDown size={11} /></button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
        {errors.components && touched.components && <p className={`${ERR_CLS} px-2 pb-1`}>{errors.components}</p>}
      </div>

      <div className="grid grid-cols-[1fr_5rem] items-end gap-2">
        <div className="rounded border border-slate-100 bg-slate-50 p-2">
          <label className="flex cursor-pointer items-center gap-3">
            <div className="relative">
              <input type="checkbox" checked={form.is_active} onChange={(e) => setField('is_active', e.target.checked)} className="sr-only peer" />
              <div className="h-5 w-9 rounded-full bg-slate-200 transition-colors peer-checked:bg-indigo-600" />
              <div className="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
            </div>
            <span className="text-xs font-medium text-slate-700">{form.is_active ? 'Active — staff can pick it' : 'Inactive (hidden from GRNs)'}</span>
          </label>
        </div>
        <div>
          <label className={LABEL_CLS}>Order <HelpTip text="Position of this code in the Tax dropdown. Lower numbers appear first." /></label>
          <input type="number" min="0" max="999" value={form.sort_order} onChange={(e) => setField('sort_order', e.target.value)} className={`${inputBase} text-right`} />
        </div>
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
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Add Tax Code'}
        </button>
      </div>
    </form>
  )
}

export default function TaxCodesPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['tax-codes', page],
    queryFn:  () => getTaxCodes(page),
    placeholderData: (prev) => prev,
  })

  // The taxes a component can point at: one entry per code, at its latest rate.
  const { data: taxesData } = useQuery({
    queryKey: ['taxes', 1],
    queryFn:  () => getTaxes(1),
    staleTime: 60 * 1000,
  })
  const taxOptions = useMemo(() => {
    const byCode = new Map()
    for (const t of taxesData?.data ?? []) {
      if (!byCode.has(t.code)) byCode.set(t.code, t) // the list is newest-first per code
    }
    return [...byCode.values()]
  }, [taxesData])

  const deleteMutation = useMutation({
    mutationFn: deleteTaxCode,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tax-codes'] })
      queryClient.invalidateQueries({ queryKey: ['tax-code-options'] })
      showSuccess('Tax code deleted.')
    },
    onError: (err) => showError(err.response?.data?.errors?.id?.[0] ?? err.response?.data?.message ?? 'Failed to delete.'),
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
  const categories = meta?.vat_categories ?? DEFAULT_CATEGORIES
  const isEditMode = editId !== null

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Tax Codes</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="mt-2 flex items-start gap-2 rounded-lg border border-sky-100 bg-sky-50 px-3 py-1.5 text-[11px] leading-snug text-sky-800">
        <Info size={13} className="mt-0.5 shrink-0" />
        <span>
          <b>Taxes</b> hold the rates set by law (VAT 18% from a date). <b>Tax Codes</b> are what staff pick on a GRN line:
          which of those taxes apply, in what order, and whether each can be <span className="font-semibold text-green-700">claimed back</span> or is a{' '}
          <span className="font-semibold text-orange-700">cost</span>. Change a rate in Taxes and every code follows it automatically.
        </span>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load tax codes.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead className="sticky top-0 z-10">
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                      <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">VAT Category</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Taxes</th>
                      <th className="w-16 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="sticky right-0 w-16 bg-slate-50 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-400">No tax codes yet.</td>
                      </tr>
                    ) : (
                      rows.map((row) => (
                        <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                          <td className="px-3 py-2 font-mono font-semibold text-slate-700">
                            <span className="inline-flex items-center gap-1">
                              {row.code}
                              {row.is_system && <Lock size={10} className="text-slate-400" title="Built-in — taxes fixed, cannot be deleted" />}
                            </span>
                          </td>
                          <td className="max-w-[14rem] truncate px-3 py-2 text-slate-700" title={row.name}>{row.name}</td>
                          <td className="px-3 py-2">
                            <span className={`inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold ${CATEGORY_BADGE[row.vat_category] ?? 'bg-slate-100 text-slate-600'}`}>
                              {row.vat_category_label}
                            </span>
                          </td>
                          <td className="px-3 py-2"><ComponentsSummary components={row.components} /></td>
                          <td className="px-3 py-2">
                            {row.is_active ? (
                              <span className="inline-flex rounded px-2 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                            ) : (
                              <span className="inline-flex rounded px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Off</span>
                            )}
                          </td>
                          <td className="sticky right-0 bg-white px-3 py-2">
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
                                <DeleteBtn onClick={() => handleDelete(row.id, `tax code ${row.code}`)} disabled={deleteMutation.isPending} />
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
              <Receipt size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Tax Code' : 'New Tax Code'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_taxes') || can('edit_taxes') ? (
            <TaxCodeForm
              key={editId ?? 'create'}
              editId={editId}
              categories={categories}
              taxOptions={taxOptions}
              onDone={() => setEditId(null)}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage tax codes.</div>
          )}
        </div>
      </div>
    </div>
  )
}
