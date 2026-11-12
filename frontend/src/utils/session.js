// The signed-in user's profile, kept in localStorage so the menu draws instantly,
// and refreshed from GET /api/me on every app load (see Sidebar), so a module a
// super admin switches on or off shows after a page refresh, without a new login.

const KEYS = ['auth_token', 'user_id', 'user_name', 'user_email', 'active_modules', 'is_super_admin', 'user_roles', 'user_permissions']

/** Store what /api/login or /api/me returned (the token only comes from login). */
export function saveProfile(data) {
  if (!data) return
  if (data.token) localStorage.setItem('auth_token', data.token)
  localStorage.setItem('user_id', String(data.user_id ?? ''))
  localStorage.setItem('user_name', data.user_name ?? '')
  localStorage.setItem('user_email', data.user_email ?? '')
  localStorage.setItem('active_modules', JSON.stringify(data.active_modules ?? []))
  localStorage.setItem('is_super_admin', data.is_super_admin ? '1' : '0')
  localStorage.setItem('user_roles', JSON.stringify(data.roles ?? []))
  localStorage.setItem('user_permissions', JSON.stringify(data.permissions ?? []))
}

export function clearSession() {
  KEYS.forEach((k) => localStorage.removeItem(k))
}

function readList(key) {
  try {
    const parsed = JSON.parse(localStorage.getItem(key) ?? '[]')
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

/** The profile as last stored: the fallback while /api/me is loading. */
export function readProfile() {
  return {
    user_name:      localStorage.getItem('user_name') || '',
    user_email:     localStorage.getItem('user_email') || '',
    active_modules: readList('active_modules'),
    is_super_admin: localStorage.getItem('is_super_admin') === '1',
    roles:          readList('user_roles'),
    permissions:    readList('user_permissions'),
  }
}
