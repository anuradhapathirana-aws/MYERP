import { useState } from 'react'
import { Layers, Lock, Plus, Trash2, Wand2 } from 'lucide-react'
import { confirmDelete, showWarning } from '../../utils/alerts'
import { PRICE_FIELDS, variantKey, variantLabel } from '../../utils/productVariants'

const INPUT_CLS = 'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const INPUT_ERR_CLS = 'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-1.5 py-0.5 text-xs text-slate-800 outline-none focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const SELECT_CLS = 'block w-full rounded-md border-2 border-slate-200 bg-white px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all cursor-pointer focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15'

const newVariant = (ids, allAttributes) => ({
  attribute_ids: ids,
  label:         variantLabel(ids, allAttributes),
  sku:           '',
  barcode:       '',
  is_active:     true,
  in_use:        false,
  prices:        {},
})

/**
 * Variants of a quantity-tracked product: combinations of the product's
 * attribute values (Colour × Design × Size …) with their own SKU, barcode and
 * price per sales channel. An empty price falls back to the product's price.
 *
 * Admin adds just the variants they sell ("+ Add": one value per type), or
 * generates every combination at once and unticks the unwanted ones.
 */
export default function ProductVariantsCard({ variants, onChange, productAttributes, attributeTypes, allAttributes, salesChannels, costDetails, errors = {} }) {
  const channelOptions = salesChannels.filter((c) =>
    costDetails.some((r) => String(r.sales_channel_id) === String(c.id))
  )
  const [channelId, setChannelId] = useState('')
  const activeChannelId = channelOptions.some((c) => String(c.id) === channelId)
    ? channelId
    : String(channelOptions[0]?.id ?? '')
  const defaultPrices = costDetails.find((r) => String(r.sales_channel_id) === activeChannelId) ?? {}

  const assignedIds = new Set(productAttributes.flatMap((r) => r.attribute_ids.map(String)))

  // Types the product has values for — Add Variant needs one value from each.
  const typeRows = productAttributes.filter((r) => r.attribute_type_id && r.attribute_ids.length > 0)
  const [pick, setPick] = useState({})

  const addVariant = () => {
    const ids = typeRows.map((r) => pick[r.attribute_type_id]).filter(Boolean)
    if (typeRows.length === 0) {
      showWarning('Add product attributes (e.g. Colour, Design, Size) with values first.')
      return
    }
    if (ids.length < typeRows.length) {
      showWarning('Pick one value for every attribute type.')
      return
    }

    const key = variantKey(ids)
    const idx = variants.findIndex((v) => variantKey(v.attribute_ids) === key)
    if (idx >= 0) {
      if (variants[idx].is_active) {
        showWarning(`"${variants[idx].label}" already exists.`)
      } else {
        update(idx, { is_active: true })
        showWarning(`"${variants[idx].label}" already existed — it was re-activated.`)
      }
      return
    }

    onChange([...variants, newVariant(ids, allAttributes)])
    setPick({})
  }

  const update = (idx, patch) => onChange(variants.map((v, i) => (i === idx ? { ...v, ...patch } : v)))

  const updatePrice = (idx, field, value) => {
    const v = variants[idx]
    const prev = v.prices[activeChannelId] ?? {}
    update(idx, { prices: { ...v.prices, [activeChannelId]: { ...prev, [field]: value } } })
  }

  const generate = () => {
    const groups = productAttributes
      .filter((r) => r.attribute_type_id && r.attribute_ids.length > 0)
      .map((r) => r.attribute_ids.map(String))

    if (groups.length === 0) {
      showWarning('Add product attributes (e.g. Colour, Design, Size) with values first.')
      return
    }

    const combos = groups.reduce((acc, values) => acc.flatMap((combo) => values.map((v) => [...combo, v])), [[]])
    const existing = new Set(variants.map((v) => variantKey(v.attribute_ids)))
    const added = combos
      .filter((ids) => !existing.has(variantKey(ids)))
      .map((ids) => newVariant(ids, allAttributes))

    if (added.length === 0) {
      showWarning('All combinations already exist.')
      return
    }
    onChange([...variants, ...added])
  }

  const remove = async (idx) => {
    const v = variants[idx]
    if (!(await confirmDelete(v.label || 'this variant'))) return
    if (v.in_use) {
      showWarning(`"${v.label}" is used by stock or documents — it was deactivated instead.`)
      update(idx, { is_active: false })
      return
    }
    onChange(variants.filter((_, i) => i !== idx))
  }

  const err = (idx, field) => errors[`variants.${idx}.${field}`]
  const activeCount = variants.filter((v) => v.is_active).length

  return (
    <section className={`overflow-hidden rounded-lg border bg-white ${errors.variants ? 'border-red-300' : 'border-slate-200'}`}>
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-teal-100 bg-teal-50 px-3 py-1.5 text-teal-700">
        <div className="flex items-center gap-1.5">
          <Layers size={13} />
          <h2 className="text-xs font-bold">Variants</h2>
          <span className="rounded-full bg-teal-100 px-1.5 text-[10px] font-semibold">{activeCount} active / {variants.length}</span>
        </div>
        <div className="flex items-center gap-2">
          {channelOptions.length > 0 && (
            <label className="flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
              Prices for
              <select
                value={activeChannelId}
                onChange={(e) => setChannelId(e.target.value)}
                className="rounded-md border-2 border-slate-200 bg-white px-1.5 py-0.5 text-xs font-normal normal-case text-slate-800 outline-none focus:border-indigo-500"
              >
                {channelOptions.map((c) => (
                  <option key={c.id} value={String(c.id)}>{c.sales_channel_name}</option>
                ))}
              </select>
            </label>
          )}
          <button
            type="button"
            onClick={generate}
            className="flex items-center gap-1 rounded bg-teal-600 px-2 py-1 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-teal-700"
          >
            <Wand2 size={12} /> Generate all
          </button>
        </div>
      </div>

      <div className="p-2">
        {errors.variants && <p className="mb-1 text-[10px] font-medium text-red-600">{errors.variants}</p>}

        {/* ── Add one variant: a value per attribute type ── */}
        {typeRows.length > 0 && (
          <div className="mb-2 flex flex-wrap items-end gap-1.5 rounded border border-dashed border-teal-200 bg-teal-50/40 p-1.5">
            {typeRows.map((r) => {
              const type = attributeTypes.find((t) => String(t.id) === String(r.attribute_type_id))
              const values = allAttributes.filter((a) => r.attribute_ids.includes(String(a.id)))
              return (
                <label key={r.attribute_type_id} className="w-36">
                  <span className="block text-[10px] font-bold uppercase tracking-wider text-slate-500">
                    {type?.attribute_type_name ?? 'Attribute'} <span className="text-red-500">*</span>
                  </span>
                  <select
                    value={pick[r.attribute_type_id] ?? ''}
                    onChange={(e) => setPick((prev) => ({ ...prev, [r.attribute_type_id]: e.target.value }))}
                    className={SELECT_CLS}
                  >
                    <option value="">— Select —</option>
                    {values.map((a) => (
                      <option key={a.id} value={String(a.id)}>{a.attribute_name}</option>
                    ))}
                  </select>
                </label>
              )
            })}
            <button
              type="button"
              onClick={addVariant}
              className="flex items-center gap-1 rounded bg-indigo-600 px-2 py-1 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700"
            >
              <Plus size={12} strokeWidth={2.5} /> Add Variant
            </button>
          </div>
        )}

        {variants.length === 0 ? (
          <p className="text-xs italic text-slate-400">
            No variants. Select attribute values above (Colour, Design, Size …), then <strong>Add Variant</strong> one by one or <strong>Generate all</strong> combinations.
            Without variants the product is stocked and priced as one item.
          </p>
        ) : (
          <div className="max-h-[420px] overflow-auto">
            <table className="w-full text-xs">
              <thead className="sticky top-0 z-10 bg-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                <tr>
                  <th className="w-10 px-1.5 py-1 text-center">Active</th>
                  <th className="px-1.5 py-1 text-left">Variant</th>
                  <th className="w-40 px-1.5 py-1 text-left">SKU</th>
                  <th className="w-32 px-1.5 py-1 text-left">Barcode</th>
                  {PRICE_FIELDS.map(({ key, label }) => (
                    <th key={key} className="w-24 px-1.5 py-1 text-right">{label}</th>
                  ))}
                  <th className="sticky right-0 w-8 bg-slate-100 px-1.5 py-1" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {variants.map((v, idx) => {
                  const unassigned = v.attribute_ids.some((id) => !assignedIds.has(String(id)))
                  const prices = v.prices[activeChannelId] ?? {}
                  return (
                    <tr key={v.id ?? `new-${variantKey(v.attribute_ids)}`} className={v.is_active ? 'hover:bg-slate-50' : 'bg-slate-50 text-slate-400'}>
                      <td className="px-1.5 py-1 text-center">
                        <input
                          type="checkbox"
                          checked={v.is_active}
                          onChange={() => update(idx, { is_active: !v.is_active })}
                          className="h-3.5 w-3.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                        />
                      </td>
                      <td className="px-1.5 py-1">
                        <div className="flex items-center gap-1">
                          {v.in_use && <Lock size={11} className="shrink-0 text-slate-400" title="Used by stock/documents — combination is locked" />}
                          <span className={`truncate font-medium ${unassigned && v.is_active ? 'text-red-600' : ''}`} title={v.label}>
                            {v.label || 'Default'}
                          </span>
                          {!v.id && <span className="rounded bg-emerald-100 px-1 text-[9px] font-bold text-emerald-700">NEW</span>}
                        </div>
                        {unassigned && v.is_active && <p className="text-[10px] text-red-500">Value removed from attributes — deactivate or re-add it.</p>}
                        {err(idx, 'attribute_ids') && <p className="text-[10px] text-red-500">{err(idx, 'attribute_ids')}</p>}
                      </td>
                      <td className="px-1.5 py-1">
                        <input
                          value={v.sku}
                          onChange={(e) => update(idx, { sku: e.target.value })}
                          placeholder="Auto"
                          maxLength={80}
                          className={err(idx, 'sku') ? INPUT_ERR_CLS : INPUT_CLS}
                        />
                        {err(idx, 'sku') && <p className="text-[10px] text-red-500">{err(idx, 'sku')}</p>}
                      </td>
                      <td className="px-1.5 py-1">
                        <input
                          value={v.barcode}
                          onChange={(e) => update(idx, { barcode: e.target.value })}
                          placeholder="—"
                          maxLength={80}
                          className={err(idx, 'barcode') ? INPUT_ERR_CLS : INPUT_CLS}
                        />
                        {err(idx, 'barcode') && <p className="text-[10px] text-red-500">{err(idx, 'barcode')}</p>}
                      </td>
                      {PRICE_FIELDS.map(({ key }) => (
                        <td key={key} className="px-1.5 py-1">
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={prices[key] ?? ''}
                            onChange={(e) => updatePrice(idx, key, e.target.value)}
                            disabled={!activeChannelId}
                            placeholder={defaultPrices[key] ? String(defaultPrices[key]) : '—'}
                            title="Empty = product price"
                            className={`${INPUT_CLS} text-right`}
                          />
                        </td>
                      ))}
                      <td className="sticky right-0 bg-white px-1.5 py-1 text-center">
                        <button
                          type="button"
                          onClick={() => remove(idx)}
                          title={v.in_use ? 'In use — will be deactivated' : 'Remove variant'}
                          className="rounded p-0.5 text-red-500 transition-colors hover:bg-red-100 hover:text-red-700"
                        >
                          <Trash2 size={13} />
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            <p className="mt-1 text-[10px] text-slate-400">
              Empty SKU is auto-generated (e.g. PRD-0001-RED-FLORAL-M). Empty prices use the product price for the channel (shown greyed).
            </p>
          </div>
        )}
      </div>
    </section>
  )
}
