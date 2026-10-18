// Product variant helpers shared by the Product form and document line pickers.

export const PRICE_FIELDS = [
  { key: 'selling_price',   label: 'Selling' },
  { key: 'wholesale_price', label: 'Wholesale' },
  { key: 'min_price',       label: 'Min' },
  { key: 'max_price',       label: 'Max' },
]

/** Sorted attribute ids joined by '-' — same rule as the backend's combination_hash. */
export const variantKey = (ids) => [...ids].map(Number).sort((a, b) => a - b).join('-')

/** "Red / Floral / M": values ordered by attribute type id, then value id (backend order). */
export function variantLabel(ids, allAttributes) {
  return ids
    .map((id) => allAttributes.find((a) => String(a.id) === String(id)))
    .filter(Boolean)
    .sort((a, b) => (a.attribute_type_id - b.attribute_type_id) || (a.id - b.id))
    .map((a) => a.attribute_name)
    .join(' / ')
}

/** Form-state rows from the API's product.variants. */
export function variantsFromApi(variants = []) {
  return variants.map((v) => ({
    id:            v.id,
    attribute_ids: (v.attribute_ids ?? []).map(String),
    label:         v.variant_label ?? '',
    sku:           v.sku ?? '',
    barcode:       v.barcode ?? '',
    is_active:     Boolean(v.is_active),
    in_use:        Boolean(v.in_use),
    prices:        Object.fromEntries((v.prices ?? []).map((p) => [String(p.sales_channel_id), {
      selling_price:   p.selling_price   != null ? String(p.selling_price)   : '',
      wholesale_price: p.wholesale_price != null ? String(p.wholesale_price) : '',
      min_price:       p.min_price       != null ? String(p.min_price)       : '',
      max_price:       p.max_price       != null ? String(p.max_price)       : '',
    }])),
  }))
}

/** API payload rows from form state. */
export function variantsToPayload(rows) {
  const toNum = (v) => (v !== '' && v != null ? Number(v) : null)
  return rows.map((v) => ({
    ...(v.id ? { id: v.id } : { attribute_ids: v.attribute_ids.map(Number) }),
    sku:       v.sku.trim() || null,
    barcode:   v.barcode.trim() || null,
    is_active: v.is_active,
    prices:    Object.entries(v.prices)
      .map(([channelId, p]) => ({
        sales_channel_id: Number(channelId),
        selling_price:    toNum(p.selling_price),
        wholesale_price:  toNum(p.wholesale_price),
        min_price:        toNum(p.min_price),
        max_price:        toNum(p.max_price),
      }))
      .filter((p) => PRICE_FIELDS.some(({ key }) => p[key] !== null)),
  }))
}
