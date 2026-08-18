import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CalendarDays, CheckCircle2, Layers, MapPin, Package, RefreshCw, Save, Scissors, Truck } from 'lucide-react'
import {
  createDeliveryOrder,
  getDeliveryOrder,
  getDoSourceSalesOrder,
  getNextDoNo,
  updateDeliveryOrder,
} from '../../api/deliveryOrders'
import { getSalesOrders } from '../../api/salesOrders'
import { getAllLocations } from '../../api/locations'
import { getAllStores } from '../../api/stores'
import Breadcrumb from '../../components/Breadcrumb'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { showError, showSuccess } from '../../utils/alerts'

const INPUT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20'
const INPUT_ERR_CLS =
  'block w-full rounded border border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-1 focus:ring-red-500/20'
const INPUT_RO_CLS =
  'block w-full rounded border border-slate-200 bg-slate-100 px-2 py-1 text-xs font-mono text-slate-500 outline-none cursor-not-allowed'
const SELECT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20 cursor-pointer'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'text-[10px] text-red-500 leading-tight'

// Fixed dispatch modes — free selection, no master lookup for now.
const DELIVERY_MODES = ['Own Vehicle', 'Courier', 'Third-Party', 'Customer Pickup']

const fmt = (n, d = 2) => Number(n).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d })

/** Read-only snapshot value pulled from the linked sales order. */
function ReadOnlyField({ label, value }) {
  return (
    <div>
      <label className={LABEL_CLS}>{label}</label>
      <div className="flex h-[26px] items-center rounded border border-slate-200 bg-slate-100 px-2 text-xs text-slate-600 truncate" title={value || ''}>
        {value || <span className="italic text-slate-300">—</span>}
      </div>
    </div>
  )
}

function SectionHeader({ icon: Icon, title, colorClass, extra }) {
  return (
    <div className={`flex items-center justify-between gap-1.5 px-3 py-1.5 border-b rounded-t-lg ${colorClass}`}>
      <div className="flex items-center gap-1.5">
        {Icon && <Icon size={12} />}
        <h2 className="text-xs font-bold">{title}</h2>
      </div>
      {extra}
    </div>
  )
}

function RollCheckbox({ checked, onChange, disabled }) {
  return (
    <span
      onClick={() => !disabled && onChange(!checked)}
      className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border-2 transition-all ${disabled ? 'border-slate-200 bg-slate-100 cursor-not-allowed' : checked ? 'border-indigo-500 bg-indigo-500 cursor-pointer' : 'border-slate-300 bg-white cursor-pointer hover:border-indigo-400'}`}
    >
      {checked && (
        <svg className="h-2 w-2 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3.5}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
        </svg>
      )}
    </span>
  )
}

/**
 * One tickable roll. The picker has to recognise the physical roll from this card alone,
 * so it carries what it holds vs. what this sale takes off it (they differ on a cut roll),
 * where it is stored, and where it came from.
 *
 * Rolls are weighed in the STOCKING UOM, but the order was placed — and must be issued and
 * invoiced — in the SELLING UOM chosen on the sales order. Ships therefore carries both:
 * the storeman counts in one unit and the customer is owed the other, and nobody should be
 * left dividing 152.50 Kg by 0.9144 in their head on the loading bay.
 */
function RollCard({ piece, checked, onToggle, baseUom, sellingUom, factor = 1 }) {
  const holds = parseFloat(piece.weight) || 0
  const ships = piece.taken_quantity != null ? (parseFloat(piece.taken_quantity) || 0) : holds
  const cut   = Boolean(piece.is_cut)

  // Only worth the ink when the two units actually differ.
  const showSelling  = Boolean(sellingUom) && sellingUom !== baseUom && factor > 0
  const shipsSelling = showSelling ? ships / factor : null

  const where = [piece.store, piece.location].filter(Boolean).join(' · ')
  const trace = [piece.batch_no && `Batch ${piece.batch_no}`, piece.grn_no].filter(Boolean).join(' · ')

  return (
    <div
      onClick={onToggle}
      title={`${piece.piece_code} — ships ${fmt(ships)} ${baseUom}${showSelling ? ` (= ${fmt(shipsSelling, 4)} ${sellingUom} to issue)` : ''} of ${fmt(holds)} ${baseUom}`}
      className={`cursor-pointer rounded border px-2 py-1.5 transition-colors ${
        checked
          ? 'border-indigo-300 bg-indigo-50/70'
          : 'border-slate-200 bg-slate-50/60 hover:border-indigo-200 hover:bg-white'
      }`}
    >
      <div className="flex items-center gap-1.5">
        <RollCheckbox checked={checked} onChange={onToggle} />
        <span className="truncate font-mono text-[11px] font-bold text-slate-700">{piece.piece_code}</span>
        <span className="shrink-0 rounded bg-slate-100 px-1 py-px text-[9px] font-semibold text-slate-500">
          Roll {piece.roll_no || '—'}
        </span>
        {cut && (
          <span className="ml-auto flex shrink-0 items-center gap-0.5 rounded bg-amber-100 px-1 py-px text-[9px] font-bold text-amber-700" title="Only part of this roll is sold — a remnant label will be printed on confirm">
            <Scissors size={8} /> CUT
          </span>
        )}
      </div>

      <div className="mt-1 grid grid-cols-2 gap-1 border-t border-dashed border-slate-200 pt-1">
        <div>
          <div className="text-[8px] font-bold uppercase tracking-wider text-slate-400">Holds</div>
          <div className="text-[11px] tabular-nums text-slate-500">
            {fmt(holds)} <span className="text-[9px] font-semibold">{baseUom}</span>
          </div>
        </div>
        <div>
          <div className={`text-[8px] font-bold uppercase tracking-wider ${cut ? 'text-amber-500' : 'text-slate-400'}`}>Ships</div>
          {/* Stocking UOM and selling UOM sit on one line: the storeman reads the weight he
              can verify on the scale, and the amount to issue against the order, together. */}
          <div className="flex flex-wrap items-baseline gap-x-1">
            <span className={`text-[11px] font-bold tabular-nums ${cut ? 'text-amber-600' : 'text-slate-700'}`}>
              {fmt(ships)} <span className="text-[9px] font-semibold">{baseUom}</span>
            </span>
            {showSelling && (
              <span
                className="text-[10px] font-bold tabular-nums text-indigo-600"
                title={`Issue this much in the selling UOM — 1 ${sellingUom} = ${fmt(factor, 4)} ${baseUom}`}
              >
                = {fmt(shipsSelling)} <span className="text-[9px] font-semibold">{sellingUom}</span>
              </span>
            )}
          </div>
        </div>
      </div>

      {where && (
        <div className="mt-1 flex items-center gap-1 truncate text-[9px] text-slate-500" title={where}>
          <MapPin size={9} className="shrink-0 text-slate-400" /> {where}
        </div>
      )}
      {trace && (
        <div className="flex items-center gap-1 truncate text-[9px] text-slate-400" title={trace}>
          <Layers size={9} className="shrink-0" /> {trace}
        </div>
      )}
    </div>
  )
}

const EMPTY_FORM = {
  document_date:      new Date().toISOString().slice(0, 10),
  delivery_date:      new Date().toISOString().slice(0, 10),
  delivery_mode:      '',
  delivery_vehicle:   '',
  responsible_person: '',
  store_id:           '',
  location_id:        '',
  delivery_address:   '',
  remarks:            '',
}

export default function DeliveryOrderFormPage() {
  const { id }    = useParams()
  const isEdit    = Boolean(id)
  const navigate  = useNavigate()
  const [search]  = useSearchParams()
  const soFromUrl = search.get('so')

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Delivery Orders', to: '/inventory/delivery-orders' },
    { label: isEdit ? 'Edit Delivery Order' : 'New Delivery Order' },
  ]

  const [soId, setSoId]       = useState(soFromUrl ?? '')
  const [form, setForm]       = useState({ ...EMPTY_FORM })
  const [errors, setErrors]   = useState({})
  // Per so_item_id: { pieceIds: Set<number>, quantity: string }
  const [selection, setSelection] = useState({})
  // Edit mode: this DO's own pieces per so_item (merged back into availability)
  const [ownPieces, setOwnPieces] = useState({})

  /* ── Reference data ───────────────────────────────────────── */
  const { data: confirmedSOs } = useQuery({
    queryKey: ['sales-orders-confirmed'],
    queryFn:  () => getSalesOrders(1, { status: 'confirmed' }),
    staleTime: 60 * 1000,
  })
  const soOptions = (confirmedSOs?.data ?? []).map((so) => ({
    value: String(so.id),
    label: `${so.so_no} — ${so.customer?.name ?? ''}`,
  }))

  const { data: locations = [] } = useQuery({ queryKey: ['locations-all'], queryFn: getAllLocations, staleTime: 5 * 60 * 1000 })
  const { data: stores    = [] } = useQuery({ queryKey: ['stores-all'],    queryFn: getAllStores,    staleTime: 5 * 60 * 1000 })

  const filteredStores = useMemo(
    () => form.location_id
      ? stores.filter((s) => String(s.location_id) === String(form.location_id))
      : stores,
    [stores, form.location_id],
  )

  const { data: nextDoNo, isLoading: loadingDoNo } = useQuery({
    queryKey: ['next-do-no'],
    queryFn:  getNextDoNo,
    enabled:  !isEdit,
    staleTime: 0,
  })

  /* ── Recall SO ────────────────────────────────────────────── */
  const { data: soSource, isLoading: loadingSource, isError: sourceError, error: sourceErrorObj } = useQuery({
    queryKey: ['do-from-so', soId],
    queryFn:  () => getDoSourceSalesOrder(soId),
    enabled:  Boolean(soId),
    retry:    false,
  })

  useEffect(() => {
    if (!soSource || isEdit) return
    setForm((f) => ({ ...f, delivery_address: f.delivery_address || soSource.sales_order.delivery_address || '' }))
    setSelection({})
  }, [soSource, isEdit])

  /* ── Edit-mode hydration ──────────────────────────────────── */
  const { data: existingDo, isLoading: loadingDo } = useQuery({
    queryKey: ['delivery-order', id],
    queryFn:  () => getDeliveryOrder(id),
    enabled:  isEdit,
  })

  useEffect(() => {
    if (!existingDo?.data) return
    const doc = existingDo.data
    setSoId(String(doc.so_id))
    setForm({
      document_date:      doc.document_date ?? EMPTY_FORM.document_date,
      delivery_date:      doc.delivery_date ?? EMPTY_FORM.delivery_date,
      delivery_mode:      doc.delivery_mode ?? '',
      delivery_vehicle:   doc.delivery_vehicle ?? '',
      responsible_person: doc.responsible_person ?? '',
      store_id:           doc.store_id != null ? String(doc.store_id) : '',
      location_id:        doc.location_id != null ? String(doc.location_id) : '',
      delivery_address:   doc.delivery_address ?? '',
      remarks:            doc.remarks ?? '',
    })

    const nextSelection = {}
    const nextOwn       = {}
    for (const item of doc.items ?? []) {
      if (item.is_scanned) {
        nextSelection[item.so_item_id] = { pieceIds: new Set(item.pieces.map((p) => p.piece_id)), quantity: '' }
        // This DO's own rolls are excluded from the availability feed (they're "taken"),
        // so they are merged back in — with the same shape, or the cards lose their detail.
        nextOwn[item.so_item_id] = item.pieces.map((p) => ({
          piece_id:       p.piece_id,
          piece_code:     p.piece_code,
          roll_no:        p.roll_no ?? '',
          weight:         p.weight,
          taken_quantity: p.taken_quantity,
          is_cut:         p.is_cut,
          store:          p.store,
          location:       p.location,
          batch_no:       p.batch_no,
          grn_no:         p.grn_no,
        }))
      } else {
        nextSelection[item.so_item_id] = { pieceIds: new Set(), quantity: String(item.quantity) }
      }
    }
    setSelection(nextSelection)
    setOwnPieces(nextOwn)
  }, [existingDo])

  /* ── Selection helpers ────────────────────────────────────── */
  const getSel = (soItemId) => selection[soItemId] ?? { pieceIds: new Set(), quantity: '' }

  const togglePiece = (soItemId, pieceId) => {
    setSelection((prev) => {
      const sel  = prev[soItemId] ?? { pieceIds: new Set(), quantity: '' }
      const next = new Set(sel.pieceIds)
      if (next.has(pieceId)) next.delete(pieceId)
      else next.add(pieceId)
      return { ...prev, [soItemId]: { ...sel, pieceIds: next } }
    })
    clearFieldError('items')
  }

  const setQty = (soItemId, value) => {
    setSelection((prev) => ({
      ...prev,
      [soItemId]: { ...(prev[soItemId] ?? { pieceIds: new Set() }), quantity: value },
    }))
    clearFieldError('items')
  }

  const clearFieldError = (field) => {
    if (errors[field]) setErrors((prev) => ({ ...prev, [field]: undefined }))
  }

  const setField = (field) => (e) => {
    setForm((f) => ({ ...f, [field]: e.target.value }))
    clearFieldError(field)
  }

  /* ── Availability per line (edit: merge own pieces back) ──── */
  const linesWithAvailability = useMemo(() => {
    if (!soSource) return []
    return soSource.items.map((item) => {
      const own       = ownPieces[item.so_item_id] ?? []
      const fetched   = item.available_pieces ?? []
      const merged    = [
        ...own.filter((p) => !fetched.some((f) => f.piece_id === p.piece_id)),
        ...fetched,
      ]
      return { ...item, available_pieces: merged }
    })
  }, [soSource, ownPieces])

  /* ── Per-line shipped quantity ────────────────────────────────
   * Rolls are weighed in the stocking UOM (Kg) and each carries taken_quantity — the
   * slice this sale takes off it, which is less than its weight when the roll is cut.
   * The saved line quantity is that sum rebased into the UOM the customer ordered in,
   * exactly as DeliveryOrderService::syncItems() computes it. Summing piece.weight
   * instead would over-report every cut roll and quote it in the wrong unit. */
  const shippedOf = (line) => {
    const sel = getSel(line.so_item_id)
    if (!line.is_scanned) {
      const qty = parseFloat(sel.quantity) || 0
      return { lineQty: qty, baseQty: 0, rolls: 0 }
    }
    const picked  = line.available_pieces.filter((p) => sel.pieceIds.has(p.piece_id))
    const baseQty = picked.reduce((s, p) => s + (parseFloat(p.taken_quantity) || 0), 0)
    const factor  = parseFloat(line.conversion_factor) || 1
    return { lineQty: baseQty / factor, baseQty, rolls: picked.length }
  }

  /* ── Totals ───────────────────────────────────────────────────
   * Lines can be sold in different UOMs (Yard here, Kg there), so a single cross-line
   * quantity sum would be meaningless — count lines and rolls instead, and let each
   * line carry its own quantity in its own unit. */
  const totals = useMemo(() => {
    let lines = 0
    let rolls = 0
    for (const line of linesWithAvailability) {
      const { lineQty, rolls: r } = shippedOf(line)
      if (lineQty > 0 || r > 0) lines += 1
      rolls += r
    }
    return { lines, rolls }
  }, [linesWithAvailability, selection])

  const hasManualQty = linesWithAvailability.some(
    (line) => !line.is_scanned && (parseFloat(getSel(line.so_item_id).quantity) || 0) > 0,
  )

  /* ── Save ─────────────────────────────────────────────────── */
  const saveMutation = useMutation({
    mutationFn: (payload) => isEdit ? updateDeliveryOrder(id, payload) : createDeliveryOrder(payload),
    onSuccess: () => {
      showSuccess(isEdit ? 'Delivery order updated.' : 'Delivery order created.')
      navigate('/inventory/delivery-orders')
    },
    onError: (e) => {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save delivery order.')
    },
  })

  const handleSubmit = () => {
    const clientErrors = {}

    if (!soId) clientErrors.so_id = ['Select a sales order to deliver.']
    if (!form.document_date) clientErrors.document_date = ['Date is required.']
    if (!form.delivery_date) clientErrors.delivery_date = ['Delivery date is required.']
    if (!form.delivery_address.trim()) clientErrors.delivery_address = ['Delivery address is required.']

    const items = linesWithAvailability
      .map((line) => {
        const sel = getSel(line.so_item_id)
        if (line.is_scanned) {
          const pieceIds = [...sel.pieceIds]
          return pieceIds.length > 0 ? { so_item_id: line.so_item_id, piece_ids: pieceIds } : null
        }
        const qty = parseFloat(sel.quantity) || 0
        return qty > 0 ? { so_item_id: line.so_item_id, quantity: qty } : null
      })
      .filter(Boolean)

    if (items.length === 0)
      clientErrors.items = ['Select at least one roll or quantity to deliver.']

    if (hasManualQty && (!form.store_id || !form.location_id))
      clientErrors.store_id = ['Select the store and location manual lines ship from.']

    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors)
      showError('Please complete the highlighted fields.')
      return
    }

    setErrors({})
    saveMutation.mutate({
      so_id:              parseInt(soId),
      document_date:      form.document_date || null,
      delivery_date:      form.delivery_date,
      delivery_mode:      form.delivery_mode || null,
      delivery_vehicle:   form.delivery_vehicle.trim() || null,
      responsible_person: form.responsible_person.trim() || null,
      store_id:           form.store_id    ? parseInt(form.store_id)    : null,
      location_id:        form.location_id ? parseInt(form.location_id) : null,
      delivery_address:   form.delivery_address.trim() || null,
      remarks:            form.remarks.trim() || null,
      items,
    })
  }

  const err = (f) => errors[f]?.[0]

  // Read-only header snapshot pulled from the linked sales order.
  const soSnap = soSource?.sales_order

  if (isEdit && loadingDo) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <RefreshCw size={14} className="animate-spin" /> Loading…
        </div>
      </div>
    )
  }

  return (
    <div className="w-full">
      {/* Page header + summary tiles */}
      <div className="mb-2 flex items-start justify-between gap-2">
        <div>
          <h1 className="text-base font-bold leading-none text-slate-800">
            {isEdit ? 'Edit Delivery Order' : 'New Delivery Order'}
          </h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <div className="flex gap-2">
          <div className="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-1.5 text-center">
            <div className="text-sm font-black leading-tight text-indigo-700 tabular-nums">{totals.lines}</div>
            <div className="text-[9px] font-bold uppercase tracking-wider text-indigo-400">Lines</div>
          </div>
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-1.5 text-center">
            <div className="text-sm font-black leading-tight text-slate-700 tabular-nums">{totals.rolls}</div>
            <div className="text-[9px] font-bold uppercase tracking-wider text-slate-400">Rolls</div>
          </div>
        </div>
      </div>

      <div className="flex flex-col gap-2">

        {/* ── Delivery Details ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader icon={CalendarDays} title="Delivery Details" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100" />
          <div className="space-y-2 p-3">
            <div className="grid grid-cols-2 gap-x-3 gap-y-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
              {/* Document meta */}
              <div>
                <label className={LABEL_CLS}>Date <span className="text-red-500 normal-case font-bold">*</span></label>
                <input type="date" className={err('document_date') ? INPUT_ERR_CLS : INPUT_CLS} value={form.document_date} onChange={setField('document_date')} />
                {err('document_date') && <p className={ERR_CLS}>{err('document_date')}</p>}
              </div>
              <ReadOnlyField label="Transaction Date" value={soSnap?.transaction_date} />
              <div>
                <label className={LABEL_CLS}>Delivery Order Number</label>
                <input
                  readOnly
                  className={INPUT_RO_CLS}
                  value={isEdit ? (existingDo?.data?.do_no ?? '') : (loadingDoNo ? 'Generating…' : (nextDoNo ?? ''))}
                  title={isEdit ? '' : 'Preview — the final number is assigned at save time'}
                />
              </div>
              <div>
                <label className={LABEL_CLS}>Sales Order <span className="text-red-500 normal-case font-bold">*</span></label>
                {isEdit ? (
                  <input readOnly className={INPUT_RO_CLS} value={existingDo?.data?.sales_order?.so_no ?? ''} />
                ) : (
                  <FilterSearchSelect
                    value={soId}
                    onChange={(val) => { setSoId(val); clearFieldError('so_id') }}
                    options={soOptions}
                    placeholder="Select confirmed SO…"
                  />
                )}
                {err('so_id') && <p className={ERR_CLS}>{err('so_id')}</p>}
              </div>

              {/* Sales-order snapshot (read-only) */}
              <ReadOnlyField label="Customer Type" value={soSnap?.customer_type} />
              <ReadOnlyField label="Customer Name" value={soSnap?.customer?.name} />
              <ReadOnlyField label="Sales Person" value={soSnap?.sales_person} />
              <ReadOnlyField label="Order Taken By" value={soSnap?.order_taken_by} />
              <ReadOnlyField label="Order Source" value={soSnap?.order_source} />

              {/* Dispatch details */}
              <div>
                <label className={LABEL_CLS}>Delivery Date <span className="text-red-500 normal-case font-bold">*</span></label>
                <input type="date" className={err('delivery_date') ? INPUT_ERR_CLS : INPUT_CLS} value={form.delivery_date} onChange={setField('delivery_date')} />
                {err('delivery_date') && <p className={ERR_CLS}>{err('delivery_date')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Delivery Mode</label>
                <select className={SELECT_CLS} value={form.delivery_mode} onChange={setField('delivery_mode')}>
                  <option value="">— Select —</option>
                  {DELIVERY_MODES.map((m) => <option key={m} value={m}>{m}</option>)}
                </select>
              </div>
              <div>
                <label className={LABEL_CLS}>Delivery Vehicle &amp; Number</label>
                <input className={INPUT_CLS} placeholder="e.g. Lorry — WP CAB 1234" value={form.delivery_vehicle} onChange={setField('delivery_vehicle')} />
              </div>
              <div>
                <label className={LABEL_CLS}>Responsible Person</label>
                <input className={INPUT_CLS} placeholder="Person in charge" value={form.responsible_person} onChange={setField('responsible_person')} />
              </div>
              <div className="col-span-2">
                <label className={LABEL_CLS}>Delivery Address <span className="text-red-500 normal-case font-bold">*</span></label>
                <input className={err('delivery_address') ? INPUT_ERR_CLS : INPUT_CLS} placeholder="Delivery address" value={form.delivery_address} onChange={setField('delivery_address')} />
                {err('delivery_address') && <p className={ERR_CLS}>{err('delivery_address')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Remarks</label>
                <input className={INPUT_CLS} placeholder="Notes…" value={form.remarks} onChange={setField('remarks')} />
              </div>
              {hasManualQty && (
                <>
                  <div>
                    <label className={LABEL_CLS}>Ship-From Location <span className="text-red-500 normal-case font-bold">*</span></label>
                    <select className={SELECT_CLS} value={form.location_id} onChange={(e) => setForm((f) => ({ ...f, location_id: e.target.value, store_id: '' }))}>
                      <option value="">— Select —</option>
                      {locations.map((l) => <option key={l.id} value={l.id}>{l.location_name ?? l.name}</option>)}
                    </select>
                    {err('store_id') && <p className={ERR_CLS}>{err('store_id')}</p>}
                  </div>
                  <div>
                    <label className={LABEL_CLS}>Ship-From Store <span className="text-red-500 normal-case font-bold">*</span></label>
                    <select className={SELECT_CLS} value={form.store_id} onChange={(e) => setForm((f) => ({ ...f, store_id: e.target.value }))}>
                      <option value="">— Select —</option>
                      {filteredStores.map((s) => <option key={s.id} value={s.id}>{s.store_name}</option>)}
                    </select>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>

        {/* ── Lines to deliver ── */}
        <div className="flex flex-col rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader
            icon={Package}
            title={soSource ? `Deliver from ${soSource.sales_order.so_no} — ${soSource.sales_order.customer?.name ?? ''}` : 'Select a Sales Order'}
            colorClass="text-violet-700 bg-violet-50 border-violet-100"
          />

          {!soId && (
            <div className="py-12 text-center text-sm text-slate-400">
              Select a confirmed sales order above to load its undelivered lines.
            </div>
          )}
          {soId && loadingSource && (
            <div className="flex items-center justify-center gap-2 py-12 text-sm text-slate-400">
              <RefreshCw size={13} className="animate-spin" /> Loading sales order…
            </div>
          )}
          {soId && sourceError && (
            <div className="py-12 text-center text-sm text-red-500">
              {sourceErrorObj?.response?.data?.message ?? 'Failed to load the sales order.'}
            </div>
          )}

          {soSource && (
            <div className="divide-y divide-slate-100">
              {linesWithAvailability.map((line) => {
                const sel     = getSel(line.so_item_id)
                const isDone  = line.fully_delivered
                const lineUom = line.unit?.symbol ?? line.unit?.name ?? ''
                const baseUom = line.base_unit?.symbol ?? line.base_unit?.name ?? lineUom
                const factor  = parseFloat(line.conversion_factor) || 1
                // The rolls are weighed in one unit and the order was placed in another —
                // say so, or the picker cannot reconcile "300 Yard" with a 152.50 Kg roll.
                const showBase  = line.is_scanned && baseUom && baseUom !== lineUom
                const shipped   = shippedOf(line)
                const cutCount  = line.is_scanned
                  ? line.available_pieces.filter((p) => sel.pieceIds.has(p.piece_id) && p.is_cut).length
                  : 0

                return (
                  <div key={line.so_item_id} className={`px-3 py-2 ${isDone ? 'opacity-45' : ''}`}>
                    <div className="mb-1.5 flex flex-wrap items-center gap-2">
                      <span className="font-mono text-[10px] text-indigo-400">{line.product?.product_code}</span>
                      <span className="text-xs font-bold text-slate-700">{line.product?.name}</span>
                      {line.attribute && (
                        <span className="rounded-full bg-slate-100 px-1.5 py-px text-[9px] font-semibold text-slate-500">{line.attribute.name}</span>
                      )}
                      <span className="text-[10px] text-slate-400">
                        Ordered <b className="font-semibold text-slate-500">{fmt(line.quantity)} {lineUom}</b>
                        {' · '}Delivered <b className="font-semibold text-slate-500">{fmt(line.quantity_delivered)} {lineUom}</b>
                        {' · '}Remaining <b className="font-semibold text-indigo-500">{fmt(line.remaining)} {lineUom}</b>
                      </span>
                      {/* The unit chosen on the sales order — what this line is issued and
                          invoiced in, whatever unit the rolls happen to be weighed in. */}
                      {lineUom && (
                        <span
                          className="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-px text-[9px] font-bold text-emerald-700"
                          title="The UOM this line was sold in on the sales order — issue and invoice in this unit"
                        >
                          Selling UOM: {lineUom}
                        </span>
                      )}
                      {showBase && (
                        <span
                          className="rounded bg-slate-100 px-1.5 py-px text-[9px] font-semibold text-slate-500"
                          title={`Rolls are weighed in ${baseUom}; this line was ordered in ${lineUom}`}
                        >
                          Rolls in {baseUom} · 1 {lineUom} = {fmt(factor, 4)} {baseUom}
                        </span>
                      )}
                      {isDone && (
                        <span className="flex items-center gap-1 rounded-full bg-green-100 px-1.5 py-px text-[9px] font-bold text-green-700">
                          <CheckCircle2 size={9} /> Fully delivered
                        </span>
                      )}
                      {shipped.lineQty > 0 && (
                        <span className="ml-auto rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-700 tabular-nums">
                          Shipping {fmt(shipped.lineQty)} {lineUom}
                          {showBase && (
                            <span className="ml-1 font-semibold text-indigo-400">({fmt(shipped.baseQty)} {baseUom})</span>
                          )}
                        </span>
                      )}
                    </div>

                    {line.is_scanned ? (
                      line.available_pieces.length === 0 ? (
                        !isDone && <p className="text-[11px] italic text-slate-400">All allocated rolls of this line are already on another delivery order.</p>
                      ) : (
                        <>
                          {cutCount > 0 && (
                            <p className="mb-1 flex items-center gap-1 text-[10px] font-semibold text-amber-600">
                              <Scissors size={10} />
                              {cutCount === 1
                                ? '1 selected roll is cut — 1 remnant label to print on confirm.'
                                : `${cutCount} selected rolls are cut — ${cutCount} remnant labels to print on confirm.`}
                            </p>
                          )}
                          <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                            {line.available_pieces.map((piece) => (
                              <RollCard
                                key={piece.piece_id}
                                piece={piece}
                                baseUom={baseUom}
                                sellingUom={lineUom}
                                factor={factor}
                                checked={sel.pieceIds.has(piece.piece_id)}
                                onToggle={() => togglePiece(line.so_item_id, piece.piece_id)}
                              />
                            ))}
                          </div>
                        </>
                      )
                    ) : (
                      !isDone && (
                        <div className="flex items-center gap-2">
                          <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                            Deliver Qty{lineUom && ` (${lineUom})`}
                          </label>
                          <div className="relative">
                            <input
                              type="number" min="0" max={line.remaining} step="0.0001" placeholder="0"
                              value={sel.quantity}
                              onChange={(e) => setQty(line.so_item_id, e.target.value)}
                              className="block w-32 rounded border border-slate-200 bg-slate-50 py-0.5 pl-1.5 pr-9 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white"
                            />
                            {lineUom && (
                              <span className="pointer-events-none absolute inset-y-0 right-1.5 flex items-center text-[9px] font-semibold text-slate-400">
                                {lineUom}
                              </span>
                            )}
                          </div>
                          <span className="text-[10px] text-slate-400">
                            of {fmt(line.remaining)} {lineUom} remaining
                          </span>
                        </div>
                      )
                    )}
                  </div>
                )
              })}
            </div>
          )}

          {err('items') && (
            <div className="border-t border-red-100 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600">
              {err('items')}
            </div>
          )}

          {/* ── Action bar ── */}
          <div className="flex items-center justify-end gap-2 border-t border-slate-100 bg-white px-3 py-2">
            <button
              type="button"
              disabled={saveMutation.isPending || !soSource}
              onClick={handleSubmit}
              className="flex items-center gap-1 rounded bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-indigo-700 disabled:opacity-60 active:scale-95"
            >
              {saveMutation.isPending
                ? <><RefreshCw size={11} className="animate-spin" /> Saving…</>
                : <><Save size={11} /> {isEdit ? 'Update Delivery Order' : 'Create Delivery Order'}</>}
            </button>
          </div>
        </div>

        <div className="flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-700">
          <Truck size={12} className="shrink-0" />
          Stock is deducted only when the delivery order is <b>confirmed</b> from its view page — saving here creates a draft.
        </div>

      </div>
    </div>
  )
}
