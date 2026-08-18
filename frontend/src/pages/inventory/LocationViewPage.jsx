import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Edit2, MapPin } from 'lucide-react'
import { getLocation } from '../../api/locations'
import Breadcrumb from '../../components/Breadcrumb'

function Field({ label, value }) {
  return (
    <div>
      <dt className="text-[11px] font-medium uppercase tracking-wider text-slate-400">{label}</dt>
      <dd className="mt-0.5 text-xs text-slate-800">{value ?? <span className="italic text-slate-300">—</span>}</dd>
    </div>
  )
}

function SectionHeader({ title }) {
  return (
    <div className="border-b border-slate-100 bg-slate-50 px-3 py-1.5">
      <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</h2>
    </div>
  )
}

function Card({ title, children }) {
  return (
    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <SectionHeader title={title} />
      <dl className="grid grid-cols-2 gap-3 p-3 md:grid-cols-3">{children}</dl>
    </div>
  )
}

export default function LocationViewPage() {
  const { id } = useParams()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['location', id],
    queryFn:  () => getLocation(id),
  })

  const loc = data?.data

  const crumbs = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Locations', to: '/inventory/locations' },
    { label: loc?.location_name ?? 'View Location' },
  ]

  if (isLoading) {
    return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  }
  if (isError || !loc) {
    return <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load location.</div>
  }

  return (
    <div className="w-full">
      <div className="mb-3 flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <MapPin size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">{loc.location_name}</h1>
            <Breadcrumb crumbs={crumbs} />
            <span className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-500">
              {loc.location_code}
            </span>
          </div>
          {loc.location_type && (
            <p className="mt-0.5 text-sm text-slate-500">{loc.location_type}</p>
          )}
        </div>
        <Link
          to={`/inventory/locations/${id}/edit`}
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Edit2 size={14} />
          Edit
        </Link>
      </div>
      <div className="space-y-3">

        {/* Basic Details */}
        <Card title="Basic Details">
          <Field label="Company"         value={loc.company?.name} />
          <Field label="Industry"        value={loc.industry?.name} />
          <Field label="Parent Location" value={loc.parent_location?.name} />
          <Field label="Location Type"   value={loc.location_type} />
          <Field label="Country"         value={loc.country} />
        </Card>

        {/* Addresses side by side */}
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
          <Card title="Location Address">
            <Field label="Street Address"  value={loc.loc_street_address} />
            <Field label="City"            value={loc.loc_city} />
            <Field label="State"           value={loc.loc_state} />
            <Field label="Country"         value={loc.loc_country} />
            <Field label="Postal/Zip Code" value={loc.loc_postal_zip_code} />
          </Card>
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <SectionHeader title="Billing Address" />
            {loc.billing_same_as_location ? (
              <p className="px-3 py-3 text-xs text-slate-400 italic">Same as location address</p>
            ) : (
              <dl className="grid grid-cols-2 gap-3 p-3 md:grid-cols-3">
                <Field label="Street Address"  value={loc.bill_street_address} />
                <Field label="City"            value={loc.bill_city} />
                <Field label="State"           value={loc.bill_state} />
                <Field label="Country"         value={loc.bill_country} />
                <Field label="Postal/Zip Code" value={loc.bill_postal_zip_code} />
              </dl>
            )}
          </div>
        </div>

        {/* Contact */}
        <Card title="Contact Info">
          <Field label="Company Email"        value={loc.company_email} />
          <Field label="Customer-facing Email" value={loc.customer_facing_email} />
          <Field label="Company Phone"        value={loc.company_phone} />
          <Field label="Mobile"               value={loc.mobile} />
          <Field label="Fax"                  value={loc.fax} />
          <Field label="Website"              value={loc.website
            ? <a href={loc.website} target="_blank" rel="noreferrer" className="text-indigo-600 hover:underline">{loc.website}</a>
            : null} />
          <Field label="Longitude"            value={loc.longitude} />
          <Field label="Latitude"             value={loc.latitude} />
        </Card>

        {/* Advanced Settings */}
        <Card title="Advanced Settings">
          <Field label="Date Format"    value={loc.date_format} />
          <Field label="Number Format"  value={loc.number_format} />
          <Field label="Time Format"    value={loc.time_format} />
          <Field label="Float Precision" value={loc.float_precision} />
          <Field label="Base Currency"  value={loc.base_currency} />
          <Field label="Time Zone"      value={loc.time_zone} />
          <Field label="Financial Year" value={loc.financial_year} />
          <Field label="Open Hours"     value={
            loc.open_hours_from && loc.open_hours_to
              ? `${loc.open_hours_from} – ${loc.open_hours_to}`
              : null
          } />
        </Card>

        {/* Modules & Stock */}
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
          <Card title="Available Modules">
            {(loc.available_modules ?? []).length === 0 ? (
              <dd className="col-span-3 text-xs italic text-slate-300">No modules assigned</dd>
            ) : (
              loc.available_modules.map((m) => (
                <span key={m} className="inline-flex items-center rounded bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                  {m}
                </span>
              ))
            )}
          </Card>
          <Card title="Inventory Settings">
            <Field label="Stock Releasing Method" value={loc.stock_releasing_method} />
          </Card>
        </div>

        <div className="flex justify-end gap-2 py-1">
          <Link
            to="/inventory/locations"
            className="rounded px-3 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100"
          >
            ← Back to Locations
          </Link>
        </div>

      </div>
    </div>
  )
}
