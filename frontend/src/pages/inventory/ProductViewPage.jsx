import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart2, Calendar, Edit2, Package, Settings, ShoppingCart, Tag, Trash2 } from 'lucide-react'
import { deleteProduct, getProduct } from '../../api/products'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'

const BOOL_OPTIONS = [
  { key: 'lock_purchase',             label: 'Lock Purchase' },
  { key: 'allow_complimentary_items', label: 'Allow Complementary' },
  { key: 'free_issue',                label: 'Free Issue' },
  { key: 'allow_minus',               label: 'Allow Minus Stock' },
  { key: 'not_allow_direct_sale',     label: 'No Direct Sale' },
  { key: 'non_returnable',            label: 'Non-Returnable' },
  { key: 'is_empty',                  label: 'Is Empty' },
  { key: 'service_charge',            label: 'Service Charge' },
  { key: 'loyalty',                   label: 'Loyalty' },
  { key: 'is_batch',                  label: 'Batch Tracked' },
  { key: 'is_serial',                 label: 'Serial Tracked' },
]

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

function BoolBadge({ active, label }) {
  return (
    <div className={`flex items-center gap-1.5 rounded px-2 py-1 text-xs font-medium ${active ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-50 text-slate-400'}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-indigo-500' : 'bg-slate-300'}`} />
      {label}
    </div>
  )
}

function fmt(val, decimals = 2) {
  if (val === null || val === undefined) return '—'
  return Number(val).toFixed(decimals)
}

export default function ProductViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['product', id],
    queryFn:  () => getProduct(id),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteProduct(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      showSuccess('Product deleted.')
      navigate('/inventory/products')
    },
    onError: () => showError('Failed to delete. The product may be in use.'),
  })

  const crumbs = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Products',  to: '/inventory/products' },
    { label: 'View Product' },
  ]

  if (isLoading) return <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>
  if (isError)   return <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load product.</div>

  const p = data?.data

  const handleDelete = async () => {
    const ok = await confirmDelete(p?.name)
    if (ok) deleteMutation.mutate()
  }

  const costDetails = p?.cost_details ?? []

  return (
    <div className="w-full">
      <div className="mb-2 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">{p?.name}</h1>
          <Breadcrumb crumbs={crumbs} />
          <div className="mt-0.5 flex items-center gap-2">
            {p?.product_code && (
              <span className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-600">
                {p.product_code}
              </span>
            )}
            {p?.product_type && (
              <span className="rounded bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                {p.product_type}
              </span>
            )}
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/inventory/products/${id}/edit`}
            className="flex items-center gap-1.5 rounded-lg border-2 border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 transition-colors hover:bg-amber-100"
          >
            <Edit2 size={13} strokeWidth={2} />
            Edit Product
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

        {/* Basic Information */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
            <Package size={13} />
            <h2 className="text-xs font-bold">Basic Information</h2>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6 px-4 py-1">
            <div className="divide-y divide-slate-50">
              <Row label="Product Code"  value={p?.product_code}  mono />
              <Row label="Reference No"  value={p?.reference_no}  mono />
              <Row label="EAN / Barcode" value={p?.ean_13}        mono />
              <Row label="Product Name"  value={p?.name} />
              <Row label="Display Name"  value={p?.display_name} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Product Type"  value={p?.product_type} />
              <Row label="Category"      value={p?.category} />
              <Row label="Location"      value={p?.location} />
              <div className="py-1">
                <span className="text-xs text-slate-500">Description</span>
                <p className={`mt-0.5 text-xs ${!p?.description ? 'italic text-slate-400' : 'text-slate-700'}`}>
                  {p?.description ?? '—'}
                </p>
              </div>
            </div>
          </div>
        </section>

        {/* Suppliers */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-sky-100 bg-sky-50 px-3 py-2 text-sky-700">
            <Tag size={13} />
            <h2 className="text-xs font-bold">Suppliers</h2>
          </div>
          <div className="flex flex-wrap gap-1.5 px-4 py-2">
            {(p?.suppliers ?? []).length === 0 ? (
              <span className="text-xs italic text-slate-400">No suppliers linked.</span>
            ) : (
              (p.suppliers).map((s) => (
                <span key={s.id} className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                  {s.name}
                </span>
              ))
            )}
          </div>
        </section>

        {/* Stock & Reorder */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-amber-100 bg-amber-50 px-3 py-2 text-amber-700">
            <BarChart2 size={13} />
            <h2 className="text-xs font-bold">Stock &amp; Reorder Settings</h2>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6 px-4 py-1">
            <div className="divide-y divide-slate-50">
              <Row label="Reorder Level"  value={p?.reorder_level != null ? fmt(p.reorder_level, 4) : null} />
              <Row label="Reorder Qty"    value={p?.reorder_qty   != null ? fmt(p.reorder_qty, 4) : null} />
              <Row label="Reorder Period" value={p?.reorder_period} />
            </div>
            <div className="divide-y divide-slate-50">
              <Row label="Stock Method"  value={p?.stock_releasing_method} />
              <Row label="Tracking Type" value={p?.tracking_type} />
            </div>
          </div>
        </section>

        {/* Cost Details */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-violet-100 bg-violet-50 px-3 py-2 text-violet-700">
            <ShoppingCart size={13} />
            <h2 className="text-xs font-bold">Cost Details</h2>
          </div>
          {costDetails.length === 0 ? (
            <p className="px-4 py-3 text-xs italic text-slate-400">No cost details configured.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-100 bg-slate-50 text-left">
                    <th className="px-3 py-1.5 font-medium text-slate-500 whitespace-nowrap">Sales Channel</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 whitespace-nowrap">UOM</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">No. of Units</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Cost Price</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Margin %</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Selling Price</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Max Price</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Min Price</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Wholesale</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Sale Disc %</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Purch Disc %</th>
                    <th className="px-3 py-1.5 font-medium text-slate-500 text-right whitespace-nowrap">Avg Price</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {costDetails.map((c) => {
                    const avgPrice = (c.cost_price != null && c.selling_price != null)
                      ? fmt((c.cost_price + c.selling_price) / 2, 4)
                      : '—'
                    return (
                      <tr key={c.sales_channel_id} className="hover:bg-slate-50">
                        <td className="px-3 py-1.5 font-medium text-slate-800 whitespace-nowrap">{c.sales_channel_name}</td>
                        <td className="px-3 py-1.5 text-slate-600">{c.uom ?? '—'}</td>
                        <td className="px-3 py-1.5 text-right text-slate-700">{c.num_of_units    != null ? fmt(c.num_of_units, 4) : '—'}</td>
                        <td className="px-3 py-1.5 text-right text-slate-700">{c.cost_price      != null ? fmt(c.cost_price, 4) : '—'}</td>
                        <td className="px-3 py-1.5 text-right text-slate-700">{c.margin          != null ? fmt(c.margin, 2) : '—'}</td>
                        <td className="px-3 py-1.5 text-right font-medium text-slate-800">{c.selling_price  != null ? fmt(c.selling_price, 4) : '—'}</td>
                        <td className="px-3 py-1.5 text-right text-slate-700">{c.max_price       != null ? fmt(c.max_price, 4) : '—'}</td>
                        <td className="px-3 py-1.5 text-right text-slate-700">{c.min_price       != null ? fmt(c.min_price, 4) : '—'}</td>
                        <td className="px-3 py-1.5 text-right text-slate-700">{c.wholesale_price != null ? fmt(c.wholesale_price, 4) : '—'}</td>
                        <td className="px-3 py-1.5 text-right text-slate-700">{c.sale_privileges_discount       != null ? fmt(c.sale_privileges_discount, 2) : '—'}</td>
                        <td className="px-3 py-1.5 text-right text-slate-700">{c.purchasing_privileges_discount != null ? fmt(c.purchasing_privileges_discount, 2) : '—'}</td>
                        <td className="px-3 py-1.5 text-right font-medium text-indigo-700">{avgPrice}</td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>

        {/* Product Options */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-blue-100 bg-blue-50 px-3 py-2 text-blue-700">
            <Settings size={13} />
            <h2 className="text-xs font-bold">Product Options</h2>
          </div>
          <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2 p-3">
            {BOOL_OPTIONS.map(({ key, label }) => (
              <BoolBadge key={key} active={Boolean(p?.[key])} label={label} />
            ))}
          </div>
        </section>

        {/* Record Info */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-2 text-slate-600">
            <Calendar size={13} />
            <h2 className="text-xs font-bold">Record Info</h2>
          </div>
          <div className="divide-y divide-slate-50 px-4 py-1">
            <Row label="Created At" value={p?.created_at ? new Date(p.created_at).toLocaleString() : null} />
            <Row label="Updated At" value={p?.updated_at ? new Date(p.updated_at).toLocaleString() : null} />
          </div>
        </section>

      </div>
      <div className="mt-2">
        <Link to="/inventory/products" className="text-xs font-medium text-slate-500 hover:text-slate-800">
          ← Back to Products
        </Link>
      </div>
    </div>
  )
}
