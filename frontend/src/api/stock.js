import api from './axios'

/** Total current stock for a product, summed across all locations/stores (or one store if given). */
export const getProductStock = (productId, storeId = null) =>
  api.get('/api/v1/stock/product', {
    params: { product_id: productId, ...(storeId ? { store_id: storeId } : {}) },
  }).then((r) => r.data.data)
