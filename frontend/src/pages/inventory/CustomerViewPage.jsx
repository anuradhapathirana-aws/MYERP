import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Trash2, FileText, Download } from 'lucide-react'
import { deleteCustomer, getCustomer } from '../../api/customers'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'

const TYPE_COLORS = {
  Trade:     'bg-blue-50 text-blue-700',
  Retail:    'bg-green-50 text-green-700',
  Wholesale: 'bg-amber-50 text-amber-700',
  Corporate: 'bg-indigo-50 text-indigo-700',
}

function Row({ label, value, mono }) {
  const empty = value === null || value === undefined || value === ''
  return (
    <div className="flex items-start gap-2 py-1">
      <span className="w-44 shrink-0 text-xs text-slate-500">{label}</span>
      <span className={`text-xs ${mono ? 'font-mono text-slate-600' : 'text-slate-800'} ${empty ? 'italic text-slate-400' : ''}`}>
        {empty ? '—' : value}
      </span>
    </div>
  )
}

function formatFileSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}


function SectionCard({ title, children }) {
  return (
    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <div className="border-b border-slate-100 bg-slate-50 px-3 py-1.5">
        <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</h2>
      </div>
      <div className="px-4 py-1">{children}</div>
    </section>
  )
}

export default function CustomerViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['customer', id],
    queryFn:  () => getCustomer(id),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteCustomer(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      queryClient.invalidateQueries({ queryKey: ['customers-all'] })
      showSuccess('Customer deleted.')
      navigate('/inventory/customers')
    },
    onError: () => showError('Failed to delete. The customer may be in use.'),
  })

  const crumbs = [
    { label: 'Inventory',  to: '/inventory/products' },
    { label: 'Customers',  to: '/inventory/customers' },
    { label: 'View Customer' },
  ]

  if (isLoading) return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  if (isError)   return <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load customer.</div>

  const c = data?.data
  const fmt = (v) => (v != null && v !== '' ? v : null)

  const handleDelete = async () => {
    const ok = await confirmDelete(c?.customer_name)
    if (ok) deleteMutation.mutate()
  }

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-3 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">
            {c?.title ? `${c.title} ` : ''}{c?.customer_name}
          </h1>
          <Breadcrumb crumbs={crumbs} />
          <div className="mt-0.5 flex items-center gap-3 text-xs text-slate-500">
            {c?.customer_code && (
              <span className="font-mono">{c.customer_code}</span>
            )}
            {c?.customer_type && (
              <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${TYPE_COLORS[c.customer_type] ?? 'bg-slate-100 text-slate-600'}`}>
                {c.customer_type}
              </span>
            )}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          <Link
            to={`/inventory/customers/${id}/edit`}
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

      <div className="grid grid-cols-1 gap-2.5 lg:grid-cols-2">

        {/* ── LEFT ── */}
        <div className="space-y-2.5">

          <SectionCard title="General">
            <div className="divide-y divide-slate-50">
              <Row label="Customer Code"              value={fmt(c?.customer_code)} mono />
              <Row label="Reference No."              value={fmt(c?.reference_no)} mono />
              <Row label="Title"                      value={fmt(c?.title)} />
              <Row label="Customer Type"              value={fmt(c?.customer_type)} />
              <Row label="Customer Name"              value={fmt(c?.customer_name)} />
              <Row label="NIC / Passport / DL"        value={fmt(c?.nic_passport_driving_licence)} mono />
              <Row label="BR Number"                  value={fmt(c?.br_no)} mono />
              <Row label="Customer TIN"               value={fmt(c?.customer_tin)} mono />
            </div>
          </SectionCard>

          <SectionCard title="Contact">
            <div className="divide-y divide-slate-50">
              <Row label="Mobile"     value={fmt(c?.customer_mobile)} />
              <Row label="Land Line"  value={fmt(c?.customer_land_line)} />
              <Row label="Email"      value={fmt(c?.customer_email)} />
              <Row label="Fax"        value={fmt(c?.customer_fax)} />
            </div>
          </SectionCard>

          <SectionCard title="Billing Address">
            <div className="divide-y divide-slate-50">
              <Row label="Address Line 1"   value={fmt(c?.billing_address_line1)} />
              <Row label="Address Line 2"   value={fmt(c?.billing_address_line2)} />
              <Row label="Address Line 3"   value={fmt(c?.billing_address_line3)} />
              <Row label="City"             value={fmt(c?.billing_city)} />
              <Row label="Zip / Postal"     value={fmt(c?.billing_zip_postal)} />
              <Row label="State / Province" value={fmt(c?.billing_state_province)} />
              <Row label="Country"          value={fmt(c?.billing_country)} />
            </div>
          </SectionCard>

        </div>

        {/* ── RIGHT ── */}
        <div className="space-y-2.5">

          <SectionCard title="Shipping Address">
            <div className="divide-y divide-slate-50">
              <Row label="Address Line 1"   value={fmt(c?.shipping_address_line1)} />
              <Row label="Address Line 2"   value={fmt(c?.shipping_address_line2)} />
              <Row label="Address Line 3"   value={fmt(c?.shipping_address_line3)} />
              <Row label="City"             value={fmt(c?.shipping_city)} />
              <Row label="Zip / Postal"     value={fmt(c?.shipping_zip_postal)} />
              <Row label="State / Province" value={fmt(c?.shipping_state_province)} />
              <Row label="Country"          value={fmt(c?.shipping_country)} />
            </div>
          </SectionCard>

          <SectionCard title="Sales Team">
            <div className="divide-y divide-slate-50">
              <Row label="Sale Manager"    value={fmt(c?.sale_manager)} />
              <Row label="Sales Executive" value={fmt(c?.sales_executive)} />
              <Row label="Sales Person"    value={fmt(c?.sales_person)} />
            </div>
          </SectionCard>

          {/* Attachments */}
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div className="border-b border-slate-100 bg-slate-50 px-3 py-1.5">
              <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                Attachments {Array.isArray(c?.attachments) && c.attachments.length > 0 && (
                  <span className="ml-1 text-slate-400">({c.attachments.length})</span>
                )}
              </h2>
            </div>
            <div className="px-3 py-2">
              {Array.isArray(c?.attachments) && c.attachments.length > 0 ? (
                <div className="space-y-1">
                  {c.attachments.map((att) => {
                    const isImg = att.mime_type?.startsWith('image/')
                    return (
                      <a
                        key={att.id}
                        href={att.url}
                        target="_blank"
                        rel="noreferrer"
                        className="group flex items-center gap-2 rounded border border-slate-100 bg-slate-50 px-2 py-1.5 transition hover:border-indigo-200 hover:bg-indigo-50"
                      >
                        {isImg ? (
                          <img
                            src={att.url}
                            alt={att.file_name}
                            className="h-9 w-9 shrink-0 rounded object-cover border border-slate-200 group-hover:border-indigo-200"
                          />
                        ) : (
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded border border-slate-200 bg-white">
                            <FileText size={16} className="text-red-400" />
                          </div>
                        )}
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-[11px] font-medium text-slate-700 group-hover:text-indigo-700">{att.file_name}</p>
                          <p className="text-[10px] text-slate-400">{formatFileSize(att.file_size)}</p>
                        </div>
                        <Download size={12} strokeWidth={2} className="shrink-0 text-slate-300 group-hover:text-indigo-500" />
                      </a>
                    )
                  })}
                </div>
              ) : (
                <p className="py-2 text-center text-[11px] text-slate-300">No attachments.</p>
              )}
            </div>
          </div>

          <SectionCard title="Record Info">
            <div className="divide-y divide-slate-50">
              <Row label="Created At" value={c?.created_at ? new Date(c.created_at).toLocaleString() : null} />
              <Row label="Updated At" value={c?.updated_at ? new Date(c.updated_at).toLocaleString() : null} />
            </div>
          </SectionCard>

        </div>
      </div>

      <div className="mt-3">
        <Link to="/inventory/customers" className="text-xs font-medium text-slate-500 hover:text-slate-800">
          ← Back to Customers
        </Link>
      </div>
    </div>
  )
}
