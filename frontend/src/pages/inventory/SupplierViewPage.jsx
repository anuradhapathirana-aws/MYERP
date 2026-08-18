import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Paperclip, Trash2 } from 'lucide-react'
import { deleteSupplier, getSupplier } from '../../api/suppliers'
import { getSupplierAttachments } from '../../api/supplierAttachments'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'

function Row({ label, value, mono }) {
  const empty = value === null || value === undefined || value === ''
  return (
    <div className="flex items-start gap-2 py-1">
      <span className="w-40 shrink-0 text-xs text-slate-500">{label}</span>
      <span className={`text-xs ${mono ? 'font-mono text-slate-600' : 'text-slate-800'} ${empty ? 'italic text-slate-400' : ''}`}>
        {empty ? '—' : value}
      </span>
    </div>
  )
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

function formatBytes(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function isImageMime(mime) {
  return typeof mime === 'string' && mime.startsWith('image/')
}

function FileTypeIcon({ mime }) {
  const ext = mime?.split('/')[1]?.toUpperCase() ?? 'FILE'
  const colors = {
    PDF:  'bg-red-100 text-red-700',
    DOC:  'bg-blue-100 text-blue-700',
    DOCX: 'bg-blue-100 text-blue-700',
    XLS:  'bg-emerald-100 text-emerald-700',
    XLSX: 'bg-emerald-100 text-emerald-700',
    ZIP:  'bg-amber-100 text-amber-700',
    RAR:  'bg-amber-100 text-amber-700',
  }
  const cls = colors[ext] ?? 'bg-slate-100 text-slate-600'
  return (
    <div className={`flex h-10 w-10 items-center justify-center rounded text-[9px] font-bold ${cls}`}>
      {ext.slice(0, 4)}
    </div>
  )
}

export default function SupplierViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const [hoverPreview, setHoverPreview] = useState(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['supplier', id],
    queryFn:  () => getSupplier(id),
  })

  const { data: attachmentsData } = useQuery({
    queryKey: ['supplier-attachments', id],
    queryFn:  () => getSupplierAttachments(id),
    staleTime: 0,
  })
  const attachments = attachmentsData ?? []

  const deleteMutation = useMutation({
    mutationFn: () => deleteSupplier(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['suppliers'] })
      queryClient.invalidateQueries({ queryKey: ['suppliers-all'] })
      showSuccess('Supplier deleted.')
      navigate('/inventory/suppliers')
    },
    onError: () => showError('Failed to delete. The supplier may be in use.'),
  })

  const crumbs = [
    { label: 'Inventory',  to: '/inventory/products' },
    { label: 'Suppliers',  to: '/inventory/suppliers' },
    { label: 'View Supplier' },
  ]

  if (isLoading) return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  if (isError)   return <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load supplier.</div>

  const s = data?.data

  const handleDelete = async () => {
    const ok = await confirmDelete(s?.supplier_name)
    if (ok) deleteMutation.mutate()
  }

  const fmt = (v) => (v != null && v !== '' ? v : null)
  const fmtNum = (v) => (v != null ? Number(v).toLocaleString() : null)

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-3 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{s?.supplier_name}</h1>
          <Breadcrumb crumbs={crumbs} />
          <div className="mt-0.5 flex items-center gap-3 text-xs text-slate-500">
            {s?.supplier_code && (
              <span className="font-mono">{s.supplier_code}</span>
            )}
            {s?.supplier_type && (
              <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">
                {s.supplier_type}
              </span>
            )}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          <Link
            to={`/inventory/suppliers/${id}/edit`}
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

        {/* General */}
        <SectionCard title="General">
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <div className="divide-y divide-slate-50">
              <Row label="Supplier Code"     value={fmt(s?.supplier_code)} mono />
              <Row label="Reference No."     value={fmt(s?.reference_no)} mono />
              <Row label="Supplier Type"     value={fmt(s?.supplier_type)} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Supplier Name"     value={fmt(s?.supplier_name)} />
              <Row label="Check Writer Name" value={fmt(s?.check_writer_name)} />
            </div>
          </div>
        </SectionCard>

        {/* Contact */}
        <SectionCard title="Contact">
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <div className="divide-y divide-slate-50">
              <Row label="Mobile"    value={fmt(s?.mobile)} />
              <Row label="Land Line" value={fmt(s?.land_line)} />
              <Row label="Email"     value={fmt(s?.email)} />
              <Row label="WeChat"    value={fmt(s?.wechat)} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="WhatsApp" value={fmt(s?.whatsapp)} />
              <Row label="Fax"      value={fmt(s?.fax)} />
              <Row label="Website"  value={fmt(s?.website)} />
            </div>
          </div>
        </SectionCard>

        {/* Billing Address */}
        <SectionCard title="Billing Address">
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <div className="divide-y divide-slate-50">
              <Row label="Address Line 1"  value={fmt(s?.bil_address_line_1)} />
              <Row label="Address Line 2"  value={fmt(s?.bil_address_line_2)} />
              <Row label="Address Line 3"  value={fmt(s?.bil_address_line_3)} />
              <Row label="City"            value={fmt(s?.bil_city)} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Postal Code"     value={fmt(s?.bil_postal_code)} />
              <Row label="State/Province"  value={fmt(s?.bil_state_province)} />
              <Row label="Country"         value={fmt(s?.bil_country)} />
            </div>
          </div>
        </SectionCard>

        {/* Tax */}
        <SectionCard title="Tax">
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <div className="divide-y divide-slate-50">
              <Row label="Tax Type"               value={fmt(s?.tax_type)} />
              <Row label="Tax No."                value={fmt(s?.tax_no)} mono />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Tax Registration No."   value={fmt(s?.tax_regis_no)} mono />
            </div>
          </div>
        </SectionCard>

        {/* Financial Terms */}
        <SectionCard title="Financial Terms">
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <div className="divide-y divide-slate-50">
              <Row label="Credit Limit"       value={s?.credit_limit != null ? fmtNum(s.credit_limit) : null} />
              <Row label="Credit Period"      value={s?.credit_period != null ? `${s.credit_period} days` : null} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Privileges Discount" value={s?.privileges_discount != null ? `${s.privileges_discount}%` : null} />
            </div>
          </div>
        </SectionCard>

        {/* Banking */}
        <SectionCard title="Banking">
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <div className="divide-y divide-slate-50">
              <Row label="Bank Name"     value={fmt(s?.bank_name)} />
              <Row label="Branch"        value={fmt(s?.bank_branch)} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Account Holder" value={fmt(s?.bank_acc_holder_name)} />
              <Row label="Account No."    value={fmt(s?.bank_acc_no)} mono />
            </div>
          </div>
        </SectionCard>

        {/* Contact Person */}
        <SectionCard title="Contact Person">
          <div className="grid grid-cols-1 gap-x-6 md:grid-cols-2">
            <div className="divide-y divide-slate-50">
              <Row label="Name"        value={fmt(s?.contact_person_name)} />
              <Row label="Designation" value={fmt(s?.contact_person_designation)} />
              <Row label="Mobile"      value={fmt(s?.contact_person_mobile)} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Email" value={fmt(s?.contact_person_email)} />
              <Row label="Fax"   value={fmt(s?.contact_person_fax)} />
            </div>
          </div>
        </SectionCard>

        {/* Attachments */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <div className="flex items-center gap-1.5 border-b border-rose-100 bg-rose-50 px-3 py-1.5">
            <Paperclip size={13} className="text-rose-600" />
            <h2 className="text-xs font-semibold uppercase tracking-wider text-rose-700">Attachments</h2>
            {attachments.length > 0 && (
              <span className="rounded-full bg-rose-200 px-1.5 py-px text-[10px] font-semibold text-rose-800">
                {attachments.length}
              </span>
            )}
          </div>
          <div className="px-4 py-3">
            {attachments.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-5 text-slate-400">
                <Paperclip size={22} strokeWidth={1.5} className="mb-1 opacity-40" />
                <p className="text-xs">No attachments for this supplier.</p>
              </div>
            ) : (
              <div className="flex flex-wrap gap-2">
                {attachments.map((file) => (
                  <div
                    key={file.id}
                    className="relative flex flex-col items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1.5 w-22.5"
                  >
                    {isImageMime(file.mime_type) ? (
                      <a
                        href={file.url}
                        target="_blank"
                        rel="noreferrer"
                        onMouseEnter={() => setHoverPreview({ url: file.url, name: file.file_name })}
                        onMouseLeave={() => setHoverPreview(null)}
                      >
                        <img
                          src={file.url}
                          alt={file.file_name}
                          className="h-12 w-18.5 rounded object-cover border border-slate-200"
                        />
                      </a>
                    ) : (
                      <a href={file.url} target="_blank" rel="noreferrer">
                        <FileTypeIcon mime={file.mime_type} />
                      </a>
                    )}
                    <p className="w-full truncate text-center text-[9px] text-slate-600" title={file.file_name}>
                      {file.file_name}
                    </p>
                    {file.file_size && (
                      <p className="text-[9px] text-slate-400">{formatBytes(file.file_size)}</p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </section>

        {/* Record Info */}
        <SectionCard title="Record Info">
          <div className="divide-y divide-slate-50">
            <Row label="Created At" value={s?.created_at ? new Date(s.created_at).toLocaleString() : null} />
            <Row label="Updated At" value={s?.updated_at ? new Date(s.updated_at).toLocaleString() : null} />
          </div>
        </SectionCard>

      </div>

      <div className="mt-3">
        <Link to="/inventory/suppliers" className="text-xs font-medium text-slate-500 hover:text-slate-800">
          ← Back to Suppliers
        </Link>
      </div>

      {/* Image hover preview popup */}
      {hoverPreview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center pointer-events-none">
          <div className="rounded-xl border border-slate-300 bg-white p-2 shadow-2xl max-w-sm pointer-events-none">
            <img
              src={hoverPreview.url}
              alt={hoverPreview.name}
              className="max-h-64 max-w-xs rounded object-contain"
            />
            <p className="mt-1 truncate text-center text-[10px] text-slate-500">{hoverPreview.name}</p>
          </div>
        </div>
      )}
    </div>
  )
}
