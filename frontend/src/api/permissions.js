import api from './axios'

export const getPermissionsGrouped = () =>
  api.get('/api/permissions').then((r) => r.data)
