import api from './axios'

const BASE = '/api/v1/finance-attachments'

/** Files on any Finance document. type: 'bill' (more types later). */
export const getAttachments = (type, id) =>
  api.get(`${BASE}/${type}/${id}`).then((r) => r.data?.data ?? [])

export const uploadAttachment = (type, id, file) => {
  const body = new FormData()
  body.append('file', file)
  return api.post(`${BASE}/${type}/${id}`, body, {
    headers: { 'Content-Type': 'multipart/form-data' },
  }).then((r) => r.data?.data)
}

export const deleteAttachment = (attachmentId) =>
  api.delete(`${BASE}/${attachmentId}`)

/** Streams through the API (permission-checked); saved with its original name. */
export const downloadAttachment = async (attachment) => {
  const res = await api.get(`${BASE}/${attachment.id}/download`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url
  a.download = attachment.original_name
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
