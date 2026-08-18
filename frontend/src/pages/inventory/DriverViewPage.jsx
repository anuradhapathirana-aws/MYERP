import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Car, Edit2, FileText, MapPin, Phone, ShieldCheck, Trash2 } from 'lucide-react'
import { deleteDriver, getDriver } from '../../api/drivers'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'

const STATUS_COLORS = {
  active:    'bg-green-50 text-green-700',
  inactive:  'bg-slate-100 text-slate-500',
  suspended: 'bg-red-50 text-red-700',
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

function SectionCard({ icon: Icon, title, colorClass, children }) {
  return (
    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className={`flex items-center gap-1.5 px-3 py-2 border-b ${colorClass}`}>
        {Icon && <Icon size={13} />}
        <h2 className="text-xs font-bold">{title}</h2>
      </div>
      <div className="px-3 py-1">{children}</div>
    </section>
  )
}

function fmt(v) {
  return v !== null && v !== undefined && v !== '' ? v : null
}

function fmtDate(v) {
  if (!v) return null
  return new Date(v).toLocaleDateString()
}

export default function DriverViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['driver', id],
    queryFn:  () => getDriver(id),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteDriver(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['drivers'] })
      queryClient.invalidateQueries({ queryKey: ['drivers-all'] })
      showSuccess('Driver deleted.')
      navigate('/inventory/drivers')
    },
    onError: () => showError('Failed to delete. The driver may be in use.'),
  })

  const crumbs = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Drivers',   to: '/inventory/drivers' },
    { label: 'View Driver' },
  ]

  if (isLoading) return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  if (isError)   return <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load driver.</div>

  const d = data?.data

  const handleDelete = async () => {
    const ok = await confirmDelete(d?.full_name)
    if (ok) deleteMutation.mutate()
  }

  const isExpired = d?.license_expiry_date && new Date(d.license_expiry_date) < new Date()

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-2 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{d?.full_name}</h1>
          <Breadcrumb crumbs={crumbs} />
          <div className="mt-0.5 flex items-center gap-3 text-xs text-slate-500">
            {d?.driver_code && (
              <span className="font-mono">{d.driver_code}</span>
            )}
            {d?.status && (
              <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${STATUS_COLORS[d.status] ?? 'bg-slate-100 text-slate-600'}`}>
                {d.status}
              </span>
            )}
            {isExpired && (
              <span className="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700">
                Licence Expired
              </span>
            )}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          <Link
            to={`/inventory/drivers/${id}/edit`}
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

        {/* ── LEFT ── */}
        <div className="space-y-2">

          <SectionCard icon={Car} title="Identity" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100">
            <div className="divide-y divide-slate-50">
              <Row label="Driver Code"   value={fmt(d?.driver_code)} mono />
              <Row label="First Name"    value={fmt(d?.first_name)} />
              <Row label="Last Name"     value={fmt(d?.last_name)} />
              <Row label="NIC Number"    value={fmt(d?.nic_number)} mono />
              <Row label="Date of Birth" value={fmtDate(d?.date_of_birth)} />
            </div>
          </SectionCard>

          <SectionCard icon={ShieldCheck} title="Licence" colorClass="text-amber-700 bg-amber-50 border-amber-100">
            <div className="divide-y divide-slate-50">
              <Row label="Licence Number" value={fmt(d?.license_number)} mono />
              <Row label="Licence Type"   value={fmt(d?.license_type)} />
              <Row
                label="Expiry Date"
                value={
                  d?.license_expiry_date
                    ? <span className={isExpired ? 'font-semibold text-red-600' : ''}>
                        {fmtDate(d.license_expiry_date)}{isExpired ? ' (Expired)' : ''}
                      </span>
                    : null
                }
              />
            </div>
          </SectionCard>

          <SectionCard icon={Phone} title="Contact" colorClass="text-sky-700 bg-sky-50 border-sky-100">
            <div className="divide-y divide-slate-50">
              <Row label="Phone" value={fmt(d?.phone)} />
              <Row label="Email" value={fmt(d?.email)} />
            </div>
          </SectionCard>

        </div>

        {/* ── RIGHT ── */}
        <div className="space-y-2">

          <SectionCard icon={MapPin} title="Address" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100">
            <div className="divide-y divide-slate-50">
              <Row label="Address Line 1" value={fmt(d?.address_line1)} />
              <Row label="Address Line 2" value={fmt(d?.address_line2)} />
              <Row label="City"           value={fmt(d?.city)} />
              <Row label="State"          value={fmt(d?.state)} />
              <Row label="Country"        value={fmt(d?.country)} />
              <Row label="Postal Code"    value={fmt(d?.postal_code)} />
            </div>
          </SectionCard>

          <SectionCard icon={FileText} title="Employment" colorClass="text-violet-700 bg-violet-50 border-violet-100">
            <div className="divide-y divide-slate-50">
              <Row label="Hired Date" value={fmtDate(d?.hired_date)} />
              <Row label="Status"     value={fmt(d?.status)} />
              <Row label="Notes"      value={fmt(d?.notes)} />
            </div>
          </SectionCard>

          <SectionCard icon={FileText} title="Record Info" colorClass="text-slate-600 bg-slate-50 border-slate-100">
            <div className="divide-y divide-slate-50">
              <Row label="Created At" value={d?.created_at ? new Date(d.created_at).toLocaleString() : null} />
              <Row label="Updated At" value={d?.updated_at ? new Date(d.updated_at).toLocaleString() : null} />
            </div>
          </SectionCard>

        </div>
      </div>

      <div className="mt-2">
        <Link to="/inventory/drivers" className="text-xs font-medium text-slate-500 hover:text-slate-800">
          ← Back to Drivers
        </Link>
      </div>
    </div>
  )
}
