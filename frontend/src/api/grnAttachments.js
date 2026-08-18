import api from './axios'

export const getGrnAttachments = (grnId) =>
  api.get(`/api/v1/goods-received-notes/${grnId}/attachments`).then((r) => r.data.data ?? [])

export const uploadGrnAttachments = (grnId, files) => {
  const formData = new FormData()
  files.forEach((file) => formData.append('files[]', file))
  return api.post(`/api/v1/goods-received-notes/${grnId}/attachments`, formData, {
    headers: { 'Content-Type': undefined },
  }).then((r) => r.data)
}

export const deleteGrnAttachment = (grnId, attachmentId) =>
  api.delete(`/api/v1/goods-received-notes/${grnId}/attachments/${attachmentId}`)
