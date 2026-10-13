import api from './axios'

/** Total current stock for a product, summed across all locations/stores (or one store if given). */
export const getProductStock = (productId, storeId = null) =>
  api.get('/api/v1/stock/product', {
    params: { product_id: productId, ...(storeId ? { store_id: storeId } : {}) },
  }).then((r) => r.data.data)

/**
 * Colour-wise balances of a product (from the stock ledger), optionally for one
 * store/location: [{ attribute_id, color, store_id, location_id, quantity }].
 */
export const getColourStock = (productId, { storeId = null, locationId = null } = {}) =>
  api.get('/api/v1/stock/by-colour', {
    params: {
      product_id: productId,
      ...(storeId ? { store_id: storeId } : {}),
      ...(locationId ? { location_id: locationId } : {}),
    },
  }).then((r) => r.data.data)

/** Sum colour rows into { [attribute_id ?? 'none']: qty }. */
export const colourStockMap = (rows) => rows.reduce((acc, r) => {
  const key = r.attribute_id != null ? String(r.attribute_id) : 'none'
  acc[key] = (acc[key] ?? 0) + Number(r.quantity || 0)
  return acc
}, {})
