import api from './axios'

/**
 * Active variants of a product for document-line pickers:
 * [{ id, sku, barcode, variant_label, attribute_id, values, stock, selling_price }].
 * Stock is for the given store/location, or all stores.
 */
export const getProductVariants = (productId, { storeId = null, locationId = null, includeInactive = false } = {}) =>
  api.get(`/api/v1/products/${productId}/variants`, {
    params: {
      ...(storeId ? { store_id: storeId } : {}),
      ...(locationId ? { location_id: locationId } : {}),
      ...(includeInactive ? { include_inactive: 1 } : {}),
    },
  }).then((r) => r.data.data)

/** Find variants by SKU / barcode (scan) or label / product name. */
export const searchVariants = (q) =>
  api.get('/api/v1/product-variants/search', { params: { q } }).then((r) => r.data.data)

/** Variant-wise balances: [{ variant_id, sku, variant_label, attribute_id, store_id, location_id, quantity }]. */
export const getVariantStock = (productId, { storeId = null, locationId = null } = {}) =>
  api.get('/api/v1/stock/by-variant', {
    params: {
      product_id: productId,
      ...(storeId ? { store_id: storeId } : {}),
      ...(locationId ? { location_id: locationId } : {}),
    },
  }).then((r) => r.data.data)
