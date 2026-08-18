import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Calendar, Edit2, FolderTree, Layers, Trash2 } from 'lucide-react'
import { deleteCategory, getCategory } from '../../api/categories'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'

const TYPE_BADGE = {
  product: 'bg-blue-50 text-blue-700',
  service: 'bg-violet-50 text-violet-700',
}

function Row({ label, value, badge }) {
  const empty = value === null || value === undefined || value === ''
  return (
    <div className="flex items-start gap-2 py-1">
      <span className="w-40 shrink-0 text-xs text-slate-500">{label}</span>
      {badge
        ? <span className={`rounded px-1.5 py-0.5 text-[11px] font-medium capitalize ${TYPE_BADGE[value] ?? ''}`}>{value}</span>
        : <span className={`text-xs text-slate-800 ${empty ? 'italic text-slate-400' : ''}`}>{empty ? '—' : value}</span>
      }
    </div>
  )
}

export default function CategoryViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['category', id],
    queryFn:  () => getCategory(id),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteCategory(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] })
      queryClient.invalidateQueries({ queryKey: ['categories-all'] })
      showSuccess('Category deleted.')
      navigate('/inventory/categories')
    },
    onError: () => showError('Failed to delete. The category may be in use.'),
  })

  const crumbs = [
    { label: 'Inventory',   to: '/inventory/products' },
    { label: 'Categories',  to: '/inventory/categories' },
    { label: 'View Category' },
  ]

  if (isLoading) return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  if (isError)   return <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load category.</div>

  const cat = data?.data

  const handleDelete = async () => {
    const ok = await confirmDelete(cat?.category_name)
    if (ok) deleteMutation.mutate()
  }

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-2 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{cat?.category_name}</h1>
          <Breadcrumb crumbs={crumbs} />
          {cat?.parent_category_name && (
            <p className="mt-0.5 text-xs text-slate-500">
              Under{' '}
              <Link
                to={`/inventory/categories/${cat.parent_category_id}`}
                className="font-medium text-indigo-600 hover:underline"
              >
                {cat.parent_category_name}
              </Link>
            </p>
          )}
        </div>
        <div className="flex items-center gap-1.5">
          <Link
            to={`/inventory/categories/${id}/edit`}
            className="flex items-center gap-1.5 rounded-lg border-2 border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 transition-colors hover:bg-amber-100"
          >
            <Edit2 size={13} strokeWidth={2} />
            Edit
          </Link>
          <button
            type="button"
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
            className="flex items-center gap-1.5 rounded-lg border-2 border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-600 transition-colors hover:bg-red-100 disabled:opacity-50"
          >
            <Trash2 size={13} strokeWidth={2} />
            Delete
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">

        {/* Category Details */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
            <FolderTree size={13} />
            <h2 className="text-xs font-bold">Category Details</h2>
          </div>
          <div className="divide-y divide-slate-50 px-3 py-1">
            <Row label="Category Name"   value={cat?.category_name} />
            <Row label="Type"            value={cat?.product_service_type} badge />
            <Row label="Reference Name"  value={cat?.reference_name} />
          </div>
        </section>

        {/* Hierarchy & Classification */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-emerald-100 bg-emerald-50 px-3 py-2 text-emerald-700">
            <Layers size={13} />
            <h2 className="text-xs font-bold">Hierarchy &amp; Classification</h2>
          </div>
          <div className="divide-y divide-slate-50 px-3 py-1">
            <Row label="Parent Category" value={cat?.parent_category_name} />
            <Row label="Industry"        value={cat?.industry_name} />
            <Row label="Company"         value={cat?.company_name} />
          </div>
        </section>

        {/* Record Info */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-2 text-slate-600">
            <Calendar size={13} />
            <h2 className="text-xs font-bold">Record Info</h2>
          </div>
          <div className="divide-y divide-slate-50 px-3 py-1">
            <Row label="Created At" value={cat?.created_at ? new Date(cat.created_at).toLocaleString() : null} />
            <Row label="Updated At" value={cat?.updated_at ? new Date(cat.updated_at).toLocaleString() : null} />
          </div>
        </section>

      </div>

      <div className="mt-2">
        <Link to="/inventory/categories" className="text-xs font-medium text-slate-500 hover:text-slate-800">
          ← Back to Categories
        </Link>
      </div>
    </div>
  )
}
