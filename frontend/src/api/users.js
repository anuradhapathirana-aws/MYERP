import api from './axios'

/** Lightweight user lookup for document dropdowns (sales person, order taken by) */
export const getUsersAll = () =>
  api.get('/api/users/all').then((r) => r.data.data)

export const getUsers = (page = 1) =>
  api.get('/api/users', { params: { page } }).then((r) => r.data)

export const createUser = (payload) =>
  api.post('/api/users', payload).then((r) => r.data)

export const updateUser = (id, payload) =>
  api.put(`/api/users/${id}`, payload).then((r) => r.data)

export const deleteUser = (id) =>
  api.delete(`/api/users/${id}`)

