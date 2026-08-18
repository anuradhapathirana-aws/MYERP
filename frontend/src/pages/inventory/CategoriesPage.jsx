import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, Edit2, Eye, ExternalLink, FolderTree, Save, X } from 'lucide-react'
import { DeleteBtn } from '../../components/ui/ActionButtons'
import { createCategory, deleteCategory, getAllCategories, getCategories, getCategory, updateCategory } from '../../api/categories'
import { getAllIndustries } from '../../api/industries'
import { getAllCompanies } from '../../api/companies'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import TreeSelect from '../../components/TreeSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'
import Pagination from '../../components/ui/Pagination'

const INITIAL_FILTERS = { search: '', product_service_type: '', parent_category_id: '', industry_id: '', company_id: '' }

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Categories' },
]

const TYPE_BADGE = {
  product: 'bg-blue-50 text-blue-700',
  service: 'bg-violet-50 text-violet-700',
}

// ── Tree helpers ─────────────────────────────────────────────────────────────
function buildTree(flat, parentId = null, excludeId = null) {
  return flat
    .filter((c) => (c.parent_category_id ?? null) === parentId && c.id !== excludeId)
    .map((c) => ({ ...c, children: buildTree(flat, c.id, excludeId) }))
}

function findName(nodes, id) {
  for (const node of nodes) {
    if (node.id === id) return node.category_name
    const found = findName(node.children, id)
    if (found) return found
  }
  return null
}

// ── TreeNode ─────────────────────────────────────────────────────────────────
function TreeNode({ node, selectedId, onSelect, depth = 0 }) {
  const hasChildren = node.children.length > 0
  const isSelected  = node.id === selectedId
  const [open, setOpen] = useState(true)

  return (
    <div>
      <div
        className={[
          'flex cursor-pointer items-center gap-1 rounded px-2 py-1 text-xs transition-colors',
          isSelected ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100',
        ].join(' ')}
        style={{ paddingLeft: `${8 + depth * 16}px` }}
      >
        {hasChildren ? (
          <button
            type="button"
            onClick={(e) => { e.stopPropagation(); setOpen((v) => !v) }}
            className={['flex shrink-0 items-center rounded p-0.5 transition-colors', isSelected ? 'hover:bg-indigo-500' : 'hover:bg-slate-200'].join(' ')}
          >
            {open ? <ChevronDown size={11} strokeWidth={2.5} /> : <ChevronRight size={11} strokeWidth={2.5} />}
          </button>
        ) : (
          <span className="w-4 shrink-0" />
        )}
        <span className="flex-1 select-none truncate" onClick={() => onSelect(node.id)}>
          {node.category_name}
        </span>
      </div>
      {hasChildren && open && node.children.map((child) => (
        <TreeNode key={child.id} node={child} selectedId={selectedId} onSelect={onSelect} depth={depth + 1} />
      ))}
    </div>
  )
}

// ── CategoryTreeSelect ───────────────────────────────────────────────────────
function CategoryTreeSelect({ tree, value, onChange, hasError }) {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)

  const selectedName = value ? findName(tree, Number(value)) : null

  useEffect(() => {
    if (!open) return
    const handler = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const handleSelect = (id) => { onChange(id); setOpen(false) }
  const handleClear  = (e) => { e.stopPropagation(); onChange(''); setOpen(false) }

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={[
          'flex w-full items-center justify-between rounded-md border-2 px-2 py-1 text-xs outline-none transition-all',
          hasError
            ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:ring-2 focus:ring-red-500/15'
            : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15',
        ].join(' ')}
      >
        <span className={selectedName ? 'text-slate-800' : 'text-slate-400'}>
          {selectedName ?? '— None (top-level) —'}
        </span>
        <div className="flex items-center gap-1">
          {value && (
            <span onClick={handleClear} className="rounded px-0.5 text-slate-400 hover:text-slate-700" title="Clear">✕</span>
          )}
          <ChevronDown size={12} strokeWidth={2.5} className={`shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`} />
        </div>
      </button>

      {open && (
        <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
          <div
            onClick={() => handleSelect('')}
            className={['cursor-pointer px-3 py-1.5 text-xs transition-colors', !value ? 'bg-indigo-600 text-white' : 'italic text-slate-500 hover:bg-slate-50'].join(' ')}
          >
            — None (top-level) —
          </div>
          <div className="max-h-56 overflow-y-auto border-t border-slate-100 py-1">
            {tree.length === 0
              ? <p className="px-3 py-2 text-xs text-slate-400">No categories available.</p>
              : tree.map((node) => (
                <TreeNode key={node.id} node={node} selectedId={value ? Number(value) : null} onSelect={handleSelect} depth={0} />
              ))
            }
          </div>
        </div>
      )}
    </div>
  )
}

// ── Form helpers ─────────────────────────────────────────────────────────────
const EMPTY_FORM = {
  category_name:        '',
  product_service_type: 'product',
  parent_category_id:   '',
  reference_name:       '',
  industry_id:          '',
  company_id:           '',
}

function validate(field, value) {
  if (field === 'category_name') {
    if (!String(value).trim()) return 'Category name is required.'
    if (String(value).length > 100) return 'Max 100 characters.'
  }
  if (field === 'reference_name' && String(value).length > 100) return 'Max 100 characters.'
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'
const fieldCls = (errors, touched, name) => errors[name] && touched[name] ? inputErr : inputBase

function FieldError({ errors, touched, name }) {
  if (!errors[name] || !touched[name]) return null
  return <p className={ERR_CLS}>{errors[name]}</p>
}

// ── Read-only detail panel ────────────────────────────────────────────────────
function CategoryDetail({ viewId, onEdit, onClose }) {
  const { isLoading, data } = useQuery({
    queryKey: ['category', viewId],
    queryFn:  () => getCategory(viewId),
    enabled:  Boolean(viewId),
  })

  const cat = data?.data

  const DetailRow = ({ label, children }) => (
    <div className="flex flex-col gap-0.5">
      <span className="text-[10px] font-medium uppercase tracking-wider text-slate-400">{label}</span>
      <span className="text-xs text-slate-800">{children}</span>
    </div>
  )

  if (isLoading) {
    return <div className="flex items-center justify-center py-8 text-xs text-slate-400">Loading…</div>
  }

  if (!cat) return null

  return (
    <div className="flex flex-col gap-2 p-2.5">
      <DetailRow label="Category Name">
        <span className="font-semibold">{cat.category_name}</span>
      </DetailRow>

      <DetailRow label="Type">
        <span className={`inline-block rounded px-1.5 py-0.5 text-[11px] font-medium capitalize ${TYPE_BADGE[cat.product_service_type] ?? ''}`}>
          {cat.product_service_type}
        </span>
      </DetailRow>

      <DetailRow label="Parent Category">
        {cat.parent_category_name
          ? <span className="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700">{cat.parent_category_name}</span>
          : <span className="italic text-slate-400">None (top-level)</span>}
      </DetailRow>

      <DetailRow label="Reference Name">
        {cat.reference_name ?? <span className="italic text-slate-400">—</span>}
      </DetailRow>

      <DetailRow label="Industry">
        {cat.industry_name ?? <span className="italic text-slate-400">—</span>}
      </DetailRow>

      <DetailRow label="Company">
        {cat.company_name ?? <span className="italic text-slate-400">—</span>}
      </DetailRow>

      <DetailRow label="Created">
        {new Date(cat.created_at).toLocaleString()}
      </DetailRow>

      <div className="flex items-center justify-between border-t border-slate-100 pt-3">
        <Link
          to={`/inventory/categories/${viewId}`}
          className="flex items-center gap-1 text-[10px] text-indigo-600 hover:underline"
        >
          <ExternalLink size={11} /> Full details
        </Link>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={onClose}
            className="flex items-center gap-1 rounded px-2.5 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100"
          >
            <X size={12} /> Close
          </button>
          <button
            type="button"
            onClick={onEdit}
            className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Edit2 size={12} /> Edit
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Inline form panel ─────────────────────────────────────────────────────────
function CategoryForm({ editId, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { data: allCategories = [] } = useQuery({ queryKey: ['categories-all'], queryFn: getAllCategories })
  const { data: allIndustries = [] } = useQuery({ queryKey: ['industries-all'],  queryFn: getAllIndustries })
  const { data: allCompanies  = [] } = useQuery({ queryKey: ['companies-all'],   queryFn: getAllCompanies })

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['category', editId],
    queryFn:  () => getCategory(editId),
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
        category_name:        c.category_name        ?? '',
        product_service_type: c.product_service_type ?? 'product',
        parent_category_id:   c.parent_category_id   ?? '',
        reference_name:       c.reference_name       ?? '',
        industry_id:          c.industry_id           ?? '',
        company_id:           c.company_id            ?? '',
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) nameRef.current?.focus()
  }, [isFetching, editId])

  const categoryTree = useMemo(
    () => buildTree(allCategories, null, isEditing ? Number(editId) : null),
    [allCategories, isEditing, editId],
  )

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
      isEditing ? updateCategory(editId, payload) : createCategory(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      queryClient.invalidateQueries({ queryKey: ['categories-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['category', editId] })
      showSuccess(isEditing ? 'Category updated.' : 'Category created.')
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
    const fields    = ['category_name', 'reference_name']
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f])]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(fields.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      category_name:        form.category_name.trim(),
      product_service_type: form.product_service_type,
      parent_category_id:   form.parent_category_id || null,
      reference_name:       form.reference_name.trim() || null,
      industry_id:          form.industry_id || null,
      company_id:           form.company_id  || null,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-8 text-xs text-slate-400">Loading…</div>
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">

      {/* Category Name */}
      <div>
        <label className={LABEL_CLS}>
          Category Name <span className="text-red-500">*</span>
        </label>
        <input
          ref={nameRef}
          name="category_name"
          type="text"
          value={form.category_name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Electronics"
          maxLength={100}
          autoComplete="off"
          className={fieldCls(errors, touched, 'category_name')}
        />
        <FieldError errors={errors} touched={touched} name="category_name" />
      </div>

      {/* Type */}
      <div>
        <label className={LABEL_CLS}>
          Type <span className="text-red-500">*</span>
        </label>
        <div className="flex items-center gap-4 rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1">
          {[{ value: 'product', label: 'Product' }, { value: 'service', label: 'Service' }].map(({ value, label }) => (
            <label key={value} className="flex cursor-pointer items-center gap-1.5 select-none">
              <input
                type="radio"
                name="product_service_type"
                value={value}
                checked={form.product_service_type === value}
                onChange={handleChange}
                className="h-3.5 w-3.5 accent-indigo-600"
              />
              <span className="text-xs text-slate-700">{label}</span>
            </label>
          ))}
        </div>
      </div>

      {/* Reference Name */}
      <div>
        <label className={LABEL_CLS}>
          Reference Name <span className="text-[10px] font-normal text-slate-400">(optional)</span>
        </label>
        <input
          name="reference_name"
          type="text"
          value={form.reference_name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="Internal code or alias"
          maxLength={100}
          autoComplete="off"
          className={fieldCls(errors, touched, 'reference_name')}
        />
        <FieldError errors={errors} touched={touched} name="reference_name" />
      </div>

      {/* Parent Category */}
      <div>
        <label className={LABEL_CLS}>
          Parent Category <span className="text-[10px] font-normal text-slate-400">(optional)</span>
        </label>
        <CategoryTreeSelect
          tree={categoryTree}
          value={form.parent_category_id}
          onChange={(val) => setForm((prev) => ({ ...prev, parent_category_id: val }))}
          hasError={Boolean(errors.parent_category_id && touched.parent_category_id)}
        />
        <p className="mt-0.5 text-[10px] text-slate-400">Leave empty to create a top-level category.</p>
      </div>

      {/* Industry */}
      <div>
        <label className={LABEL_CLS}>
          Industry <span className="text-[10px] font-normal text-slate-400">(optional)</span>
        </label>
        <select name="industry_id" value={form.industry_id} onChange={handleChange} onBlur={handleBlur} className={fieldCls(errors, touched, 'industry_id')}>
          <option value="">— Select industry —</option>
          {allIndustries.map((ind) => <option key={ind.id} value={ind.id}>{ind.name}</option>)}
        </select>
      </div>

      {/* Company */}
      <div>
        <label className={LABEL_CLS}>
          Company <span className="text-[10px] font-normal text-slate-400">(optional)</span>
        </label>
        <select name="company_id" value={form.company_id} onChange={handleChange} onBlur={handleBlur} className={fieldCls(errors, touched, 'company_id')}>
          <option value="">— Select company —</option>
          {allCompanies.map((co) => <option key={co.id} value={co.id}>{co.name}</option>)}
        </select>
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
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Category'}
        </button>
      </div>
    </form>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function CategoriesPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const [viewId, setViewId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const resetPage = () => setPage(1)

  const handleView = (id) => { setViewId(id); setEditId(null) }
  const handleEdit = (id) => { setEditId(id); setViewId(null) }

  const { data, isLoading, isError } = useQuery({
    queryKey: ['categories', page, applied],
    queryFn:  () => getCategories(page, applied),
    placeholderData: (prev) => prev,
  })

  const { data: allCategoriesFlat = [] } = useQuery({
    queryKey: ['categories-all'],
    queryFn:  getAllCategories,
    staleTime: 5 * 60 * 1000,
  })

  const { data: allIndustries = [] } = useQuery({
    queryKey: ['industries-all'],
    queryFn:  getAllIndustries,
    staleTime: 5 * 60 * 1000,
  })

  const { data: allCompanies = [] } = useQuery({
    queryKey: ['companies-all'],
    queryFn:  getAllCompanies,
    staleTime: 5 * 60 * 1000,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCategory,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      queryClient.invalidateQueries({ queryKey: ['categories-all'] })
      showSuccess('Category deleted.')
    },
    onError: () => showError('Failed to delete. The category may be in use.'),
  })

  const handleDelete = async (id, name) => {
    const ok = await confirmDelete(name)
    if (ok) {
      if (editId === id) setEditId(null)
      if (viewId === id) setViewId(null)
      deleteMutation.mutate(id)
    }
  }

  const handleDone = () => setEditId(null)

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div>
        <h1 className="text-xl font-bold leading-none text-slate-800">Categories</h1>
        <Breadcrumb crumbs={CRUMBS} />
      </div>

      {/* ── Filter Panel ── */}
      <TableFilter
        open={open}
        onToggle={toggle}
        onApply={() => apply(resetPage)}
        onClear={() => clear(resetPage)}
        activeCount={activeCount}
      >
        <FilterField label="Search">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Category or reference name…"
            value={draft.search}
            onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Type">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.product_service_type}
            onChange={(e) => setDraft((d) => ({ ...d, product_service_type: e.target.value }))}
          >
            <option value="">All types</option>
            <option value="product">Product</option>
            <option value="service">Service</option>
          </select>
        </FilterField>

        <FilterField label="Parent Category">
          <TreeSelect
            name="parent_category_id"
            value={draft.parent_category_id}
            onChange={(e) => setDraft((d) => ({ ...d, parent_category_id: e.target.value }))}
            items={allCategoriesFlat}
            parentField="parent_category_id"
            labelField="category_name"
            placeholder="All categories"
            emptyText="No categories available."
          />
        </FilterField>

        <FilterField label="Industry">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.industry_id}
            onChange={(e) => setDraft((d) => ({ ...d, industry_id: e.target.value }))}
          >
            <option value="">All industries</option>
            {allIndustries.map((ind) => <option key={ind.id} value={ind.id}>{ind.name}</option>)}
          </select>
        </FilterField>

        <FilterField label="Company">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.company_id}
            onChange={(e) => setDraft((d) => ({ ...d, company_id: e.target.value }))}
          >
            <option value="">All companies</option>
            {allCompanies.map((co) => <option key={co.id} value={co.id}>{co.name}</option>)}
          </select>
        </FilterField>
      </TableFilter>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">

        {/* ── LEFT: Table ─────────────────────────────────────────────── */}
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          {isLoading && (
            <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
          )}
          {isError && (
            <div className="flex items-center justify-center py-14 text-sm text-red-500">
              Failed to load categories.
            </div>
          )}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Category Name</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Parent</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Reference</th>
                      <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                      <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-400">
                          {activeCount > 0
                            ? 'No categories match the current filters.'
                            : 'No categories yet. Use the form to create the first one.'}
                        </td>
                      </tr>
                    ) : (
                      rows.map((cat, i) => (
                        <tr
                          key={cat.id}
                          className={`transition-colors hover:bg-slate-50 ${editId === cat.id ? 'bg-indigo-50/60' : viewId === cat.id ? 'bg-sky-50/60' : ''}`}
                        >
                          <td className="px-3 py-2 text-slate-400">
                            {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                          </td>
                          <td className="px-3 py-2 font-medium text-slate-800">
                            <Link to={`/inventory/categories/${cat.id}`} className="hover:text-indigo-600 hover:underline">
                              {cat.category_name}
                            </Link>
                          </td>
                          <td className="px-3 py-2 text-slate-500">
                            {cat.parent_category_name
                              ? <span className="rounded bg-slate-100 px-1.5 py-0.5 text-slate-600">{cat.parent_category_name}</span>
                              : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2">
                            <span className={`rounded px-1.5 py-0.5 text-[11px] font-medium capitalize ${TYPE_BADGE[cat.product_service_type] ?? ''}`}>
                              {cat.product_service_type}
                            </span>
                          </td>
                          <td className="px-3 py-2 text-slate-500">
                            {cat.reference_name
                              ? <span className="block max-w-25 truncate" title={cat.reference_name}>{cat.reference_name}</span>
                              : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                            {new Date(cat.created_at).toLocaleDateString()}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              <button
                                type="button"
                                title="View"
                                onClick={() => handleView(cat.id)}
                                className={`inline-flex items-center justify-center rounded-lg p-1.5 transition-colors ${viewId === cat.id ? 'bg-blue-100 text-blue-700' : 'bg-blue-50 text-blue-600 hover:bg-blue-100'}`}
                              >
                                <Eye size={14} strokeWidth={2} />
                              </button>
                              {can('edit_categories') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => handleEdit(cat.id)}
                                  className={`inline-flex items-center justify-center rounded-lg p-1.5 transition-colors ${editId === cat.id ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-50 text-amber-600 hover:bg-amber-100'}`}
                                >
                                  <Edit2 size={14} strokeWidth={2} />
                                </button>
                              )}
                              {can('delete_categories') && (
                                <DeleteBtn
                                  onClick={() => handleDelete(cat.id, cat.category_name)}
                                  disabled={deleteMutation.isPending}
                                />
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

        {/* ── RIGHT: Detail / Form panel ──────────────────────────────── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm self-start">
          <div className="flex items-center justify-between border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <FolderTree size={13} />
              <h2 className="text-xs font-bold">
                {editId ? 'Edit Category' : viewId ? 'Category Details' : 'New Category'}
              </h2>
            </div>
            {editId && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-600">
                <Edit2 size={10} /> Editing
              </span>
            )}
            {viewId && !editId && (
              <span className="flex items-center gap-1 rounded bg-sky-50 px-2 py-0.5 text-[10px] font-medium text-sky-600">
                <Eye size={10} /> Viewing
              </span>
            )}
          </div>

          {viewId && !editId ? (
            <CategoryDetail
              key={viewId}
              viewId={viewId}
              onEdit={() => handleEdit(viewId)}
              onClose={() => setViewId(null)}
            />
          ) : can('create_categories') || can('edit_categories') ? (
            <CategoryForm
              key={editId ?? 'create'}
              editId={editId}
              onDone={handleDone}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">
              You don't have permission to manage categories.
            </div>
          )}
        </div>

      </div>
    </div>
  )
}
