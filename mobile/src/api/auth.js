import api from './client'

/** Sanctum token login — returns { token, user_id, user_name, user_email, active_modules, roles, permissions }. */
export const login = (email, password) =>
  api.post('/api/login', { email, password }).then((r) => r.data)

/** Lightweight user list (sales person picker). */
export const getUsersAll = () =>
  api.get('/api/users/all').then((r) => r.data.data ?? r.data)
