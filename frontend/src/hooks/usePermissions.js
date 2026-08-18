export function usePermissions() {
  let roles = []
  let permissions = []

  try { roles = JSON.parse(localStorage.getItem('user_roles') ?? '[]') } catch { roles = [] }
  try { permissions = JSON.parse(localStorage.getItem('user_permissions') ?? '[]') } catch { permissions = [] }

  const isSuperAdmin = Array.isArray(roles) && roles.includes('super_admin')

  const can = (permission) =>
    isSuperAdmin || (Array.isArray(permissions) && permissions.includes(permission))

  return { can, isSuperAdmin }
}
