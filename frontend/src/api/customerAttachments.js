import api from './axios'

export const getCustomerAttachments = (customerId) =>
  api.get(`/api/v1/customer-masters/${customerId}/attachments`).then((r) => r.data.data ?? [])

export const uploadCustomerAttachments = (customerId, files) => {
  const formData = new FormData()
  files.forEach((file) => formData.append('files[]', file))
  return api.post(`/api/v1/customer-masters/${customerId}/attachments`, formData, {
    headers: { 'Content-Type': undefined },
  }).then((r) => r.data)
}

export const deleteCustomerAttachment = (customerId, attachmentId) =>
  api.delete(`/api/v1/customer-masters/${customerId}/attachments/${attachmentId}`)
