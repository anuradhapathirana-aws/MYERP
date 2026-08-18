import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Calendar, Edit2, Info, ShoppingBag, Trash2 } from 'lucide-react'
import { deleteSalesChannel, getSalesChannel } from '../../api/salesChannels'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'

const TYPE_BADGE = {
  'Wholesale':  'bg-violet-50 text-violet-700',
  'e-commerce': 'bg-sky-50 text-sky-700',
  'Retail':     'bg-emerald-50 text-emerald-700',
}

const STATUS_BADGE = {
  'Active':   'bg-green-50 text-green-700',
  'Inactive': 'bg-slate-100 text-slate-500',
}

function Row({ label, value, mono }) {
  const empty = value === null || value === undefined || value === ''
  return (
    <div className="flex items-start gap-2 py-1">
      <span className="w-36 shrink-0 text-xs text-slate-500">{label}</span>
      <span className={`text-xs ${mono ? 'font-mono text-slate-600' : 'text-slate-800'} ${empty ? 'italic text-slate-400' : ''}`}>
        {empty ? '—' : value}
      </span>
    </div>
  )
}

export default function SalesChannelViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sales-channel', id],
    queryFn:  () => getSalesChannel(id),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteSalesChannel(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sales-channels'] })
      queryClient.invalidateQueries({ queryKey: ['sales-channels-all'] })
      showSuccess('Sales channel deleted.')
      navigate('/inventory/sales-channels')
    },
    onError: () => showError('Failed to delete. The sales channel may be in use.'),
  })

  const crumbs = [
    { label: 'Inventory',      to: '/inventory/products' },
    { label: 'Sales Channels', to: '/inventory/sales-channels' },
    { label: 'View Channel' },
  ]

  if (isLoading) return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  if (isError)   return <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load sales channel.</div>

  const c = data?.data

  const handleDelete = async () => {
    const ok = await confirmDelete(c?.sales_channel_name)
    if (ok) deleteMutation.mutate()
  }

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-2 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{c?.sales_channel_name}</h1>
          <Breadcrumb crumbs={crumbs} />
          <div className="mt-0.5 flex items-center gap-2">
            {c?.type && (
              <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${TYPE_BADGE[c.type] ?? 'bg-slate-100 text-slate-500'}`}>
                {c.type}
              </span>
            )}
            {c?.status && (
              <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${STATUS_BADGE[c.status] ?? 'bg-slate-100 text-slate-400'}`}>
                {c.status}
              </span>
            )}
          </div>
        </div>
        <div className="flex items-center gap-1.5">
          <Link
            to={`/inventory/sales-channels/${id}/edit`}
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

      <div className="space-y-2">

        {/* Details */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
            <ShoppingBag size={13} />
            <h2 className="text-xs font-bold">Channel Details</h2>
          </div>
          <div className="grid grid-cols-1 gap-x-6 px-3 py-1 md:grid-cols-2">
            <div className="divide-y divide-slate-50">
              <Row label="Channel Type"  value={c?.type} />
              <Row label="Channel Name"  value={c?.sales_channel_name} />
              <Row label="Status"        value={c?.status} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Max Quantity"     value={c?.max_qty != null ? Number(c.max_qty).toLocaleString() : null} />
              <Row label="Applicable From"  value={c?.applicable_from} />
              <Row label="Applicable To"    value={c?.applicable_to} />
            </div>
          </div>
          {c?.description && (
            <div className="border-t border-slate-50 px-3 py-2">
              <span className="text-xs text-slate-500">Description</span>
              <p className="mt-0.5 text-xs text-slate-700">{c.description}</p>
            </div>
          )}
        </section>

        {/* Record Info */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-2 text-slate-600">
            <Calendar size={13} />
            <h2 className="text-xs font-bold">Record Info</h2>
          </div>
          <div className="divide-y divide-slate-50 px-3 py-1">
            <Row label="Created At" value={c?.created_at ? new Date(c.created_at).toLocaleString() : null} />
            <Row label="Updated At" value={c?.updated_at ? new Date(c.updated_at).toLocaleString() : null} />
          </div>
        </section>

      </div>

      <div className="mt-2">
        <Link to="/inventory/sales-channels" className="text-xs font-medium text-slate-500 hover:text-slate-800">
          ← Back to Sales Channels
        </Link>
      </div>
    </div>
  )
}
