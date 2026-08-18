import axios from 'axios'

const BASE = '/api/v1/batches'
const auth = () => ({ Authorization: `Bearer ${localStorage.getItem('token')}` })

export const getBatches = (page = 1, filters = {}) =>
  axios.get(BASE, { headers: auth(), params: { page, ...filters } }).then((r) => r.data)

export const getBatch = (id) =>
  axios.get(`${BASE}/${id}`, { headers: auth() }).then((r) => r.data)

export const getNextBatchNo = (productId) =>
  axios
    .get(`${BASE}/next-batch-no`, { headers: auth(), params: { product_id: productId } })
    .then((r) => r.data.data.batch_no)

export const updateBatchStatus = (id, status) =>
  axios.patch(`${BASE}/${id}/status`, { status }, { headers: auth() }).then((r) => r.data)
