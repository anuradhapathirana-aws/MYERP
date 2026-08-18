import api from './axios'

const BASE = '/api/roles'

export const getRoles = () =>
  api.get(BASE).then((r) => r.data)

export const getRole = (id) =>
  api.get(`${BASE}/${id}/permissions`).then((r) => r.data)

export const createRole = (payload) =>
  api.post(BASE, payload).then((r) => r.data)

export const updateRole = (id, payload) =>
  api.put(`${BASE}/${id}`, payload).then((r) => r.data)

export const deleteRole = (id) =>
  api.delete(`${BASE}/${id}`)

export const syncRolePermissions = (id, permissions) =>
  api.put(`${BASE}/${id}/permissions`, { permissions }).then((r) => r.data)
