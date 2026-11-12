import api from './axios'

/** The signed-in user's modules, roles and permissions (refreshes the menu). */
export const getMe = () =>
  api.get('/api/me').then((r) => r.data?.data)

/** System Settings > Modules (super admin): every module, its state and the data it holds. */
export const getModules = () =>
  api.get('/api/system/modules').then((r) => r.data?.data ?? [])

/** Switch one module on or off for the whole installation. Data is never deleted. */
export const setModuleEnabled = (key, enabled) =>
  api.put(`/api/system/modules/${key}`, { enabled }).then((r) => r.data?.data)
