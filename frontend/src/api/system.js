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

/** The IT support contact shown in the User Manual (every signed-in user). */
export const getSupportContact = () =>
  api.get('/api/system/support-contact').then((r) => r.data?.data ?? {})

/** Amount decimals: { decimals, min, max, locked, lock_reason } (every signed-in user). */
export const getAmountPrecision = () =>
  api.get('/api/system/amount-precision').then((r) => r.data?.data)

/** System Settings > Number Format (super admin). Refused once documents exist. */
export const updateAmountPrecision = (decimals) =>
  api.put('/api/system/amount-precision', { decimals }).then((r) => r.data?.data)

/** System Settings > Support Contact (super admin). */
export const updateSupportContact = (payload) =>
  api.put('/api/system/support-contact', payload).then((r) => r.data?.data)
