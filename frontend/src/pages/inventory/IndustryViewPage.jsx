import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Trash2 } from 'lucide-react'
import { deleteIndustry, getIndustry } from '../../api/industries'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'

function Row({ label, value }) {
  const empty = value === null || value === undefined || value === ''
  return (
    <div className="flex items-start gap-2 py-1">
      <span className="w-36 shrink-0 text-xs text-slate-500">{label}</span>
      <span className={`text-xs text-slate-800 ${empty ? 'italic text-slate-400' : ''}`}>
        {empty ? '—' : value}
      </span>
    </div>
  )
}

export default function IndustryViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['industry', id],
    queryFn:  () => getIndustry(id),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteIndustry(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['industries'] })
      queryClient.invalidateQueries({ queryKey: ['industries-all'] })
      showSuccess('Industry deleted.')
      navigate('/inventory/industries')
    },
    onError: () => showError('Failed to delete. The industry may be in use.'),
  })

  const crumbs = [
    { label: 'Inventory',  to: '/inventory/products' },
    { label: 'Industries', to: '/inventory/industries' },
    { label: 'View Industry' },
  ]

  if (isLoading) return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  if (isError)   return <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load industry.</div>

  const industry = data?.data

  const handleDelete = async () => {
    const ok = await confirmDelete(industry?.name)
    if (ok) deleteMutation.mutate()
  }

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-3 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{industry?.name}</h1>
          <Breadcrumb crumbs={crumbs} />
        </div>
        <div className="flex items-center gap-1.5">
          <Link
            to={`/inventory/industries/${id}/edit`}
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

      <div className="space-y-2.5">

        {/* Details */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <div className="border-b border-slate-100 bg-slate-50 px-3 py-1.5">
            <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Industry Details</h2>
          </div>
          <div className="divide-y divide-slate-50 px-4 py-1">
            <Row label="Industry Name" value={industry?.name} />
          </div>
          {industry?.description && (
            <div className="border-t border-slate-50 px-4 py-2">
              <span className="text-xs text-slate-500">Description</span>
              <p className="mt-0.5 text-xs text-slate-700">{industry.description}</p>
            </div>
          )}
        </section>

        {/* Record Info */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <div className="border-b border-slate-100 bg-slate-50 px-3 py-1.5">
            <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Record Info</h2>
          </div>
          <div className="divide-y divide-slate-50 px-4 py-1">
            <Row label="Created At" value={industry?.created_at ? new Date(industry.created_at).toLocaleString() : null} />
            <Row label="Updated At" value={industry?.updated_at ? new Date(industry.updated_at).toLocaleString() : null} />
          </div>
        </section>

      </div>

      <div className="mt-3">
        <Link to="/inventory/industries" className="text-xs font-medium text-slate-500 hover:text-slate-800">
          ← Back to Industries
        </Link>
      </div>
    </div>
  )
}
