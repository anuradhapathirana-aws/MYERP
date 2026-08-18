import api from './axios'

export const getSupplierAttachments = (supplierId) =>
  api.get(`/api/v1/supplier-masters/${supplierId}/attachments`).then((r) => r.data.data ?? [])

export const uploadSupplierAttachments = (supplierId, files) => {
  const formData = new FormData()
  files.forEach((file) => formData.append('files[]', file))
  return api.post(`/api/v1/supplier-masters/${supplierId}/attachments`, formData, {
    headers: { 'Content-Type': undefined },
  }).then((r) => r.data)
}

export const deleteSupplierAttachment = (supplierId, attachmentId) =>
  api.delete(`/api/v1/supplier-masters/${supplierId}/attachments/${attachmentId}`)
