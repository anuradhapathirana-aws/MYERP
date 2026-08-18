import { useState } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowLeftRight,
  BarChart2,
  BookOpen,
  Box,
  Building2,
  Bus,
  Car,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Clock,
  CreditCard,
  DollarSign,
  FileText,
  FlaskConical,
  FolderTree,
  LayoutDashboard,
  Layers,
  ListTree,
  Lock,
  LogOut,
  MapPin,
  Package,
  PackageCheck,
  QrCode,
  Receipt,
  Ruler,
  Search,
  Settings2,
  Shield,
  ShoppingBag,
  ShoppingCart,
  SlidersHorizontal,
  Tag,
  TrendingUp,
  Truck,
  UserCog,
  Users,
  UsersRound,
  Store,
  Wallet,
  Warehouse,
  X,
} from 'lucide-react'
import UpgradeModal from './UpgradeModal'

// ── Nav definition ─────────────────────────────────────────────────────────
// moduleKey  → guarded by active_modules in localStorage
// roleGuard  → guarded by user_roles in localStorage (array: any match grants access)
// children   → renders as a section header with always-visible child links
const NAV_ITEMS = [
  {
    label: 'Dashboard',
    to: '/dashboard',
    icon: LayoutDashboard,
  },
  {
    label: 'Inventory',
    icon: Package,
    moduleKey: 'inventory',
    children: [
      { label: 'Purchase Requests', to: '/inventory/purchase-requests',    icon: ClipboardList, permissionGuard: 'view_purchase_requests' },
      { label: 'Purchase Orders',   to: '/inventory/purchase-orders',      icon: ShoppingBag,   permissionGuard: 'view_purchase_orders' },
      { label: 'GRN',               to: '/inventory/goods-received-notes', icon: PackageCheck,  permissionGuard: 'view_grns' },
      { label: 'Print Piece Labels', to: '/inventory/piece-labels',        icon: QrCode,        permissionGuard: 'view_grns' },
      { label: 'Supplier Payments', to: '/inventory/supplier-payments',    icon: DollarSign,    permissionGuard: 'view_supplier_payments' },
      { label: 'Supplier Credit Notes', to: '/inventory/supplier-credit-notes', icon: CreditCard, permissionGuard: 'view_supplier_credit_notes' },
      { label: 'Costings',          to: '/inventory/costings',             icon: Receipt,       permissionGuard: 'view_costings' },
      { label: 'Sales Orders',      to: '/inventory/sales-orders',         icon: ShoppingCart,  permissionGuard: 'view_sales_orders' },
      { label: 'Delivery Orders',   to: '/inventory/delivery-orders',      icon: Truck,         permissionGuard: 'view_delivery_orders' },
      { label: 'Invoices',          to: '/inventory/invoices',             icon: FileText,      permissionGuard: 'view_invoices' },
      { label: 'Customer Receipts', to: '/inventory/customer-receipts',    icon: DollarSign,    permissionGuard: 'view_customer_receipts' },
      { label: 'Customer Credit Notes', to: '/inventory/customer-credit-notes', icon: CreditCard, permissionGuard: 'view_customer_credit_notes' },
      {
        label: 'Reports',
        icon: BarChart2,
        isSubgroup: true,
        children: [
          {
            label: 'Sales Report',
            icon: TrendingUp,
            isSubgroup: true,
            children: [
              { label: 'Sales By Item Summary',        to: '/inventory/reports/sales-by-item-summary',        icon: ShoppingCart, permissionGuard: 'view_reports' },
              { label: 'Sales By Item Detail Summary', to: '/inventory/reports/sales-by-item-detail-summary', icon: ListTree,      permissionGuard: 'view_reports' },
              { label: 'Sales By Customer Details',    to: '/inventory/reports/sales-by-customer-details',    icon: Users,         permissionGuard: 'view_reports' },
              { label: 'Outstanding Summary',          to: '/inventory/reports/outstanding-summary',          icon: Receipt,       permissionGuard: 'view_reports' },
              { label: 'Sales Summary',                to: '/inventory/reports/sales-summary',                icon: Wallet,        permissionGuard: 'view_reports' },
            ],
          },
          {
            label: 'Stock Report',
            icon: BarChart2,
            isSubgroup: true,
            children: [
              { label: 'Item Search Report', to: '/inventory/reports/item-search',        icon: Search,         permissionGuard: 'view_reports' },
              { label: 'Stock Levels',       to: '/inventory/reports/stock-levels',       icon: BarChart2,      permissionGuard: 'view_reports', disabled: true },
              { label: 'Stock Movements',    to: '/inventory/reports/stock-movements',    icon: ArrowLeftRight, permissionGuard: 'view_reports', disabled: true },
              { label: 'Bin Card',           to: '/inventory/reports/bin-card',           icon: BookOpen,       permissionGuard: 'view_reports' },
              { label: 'Movement Summary',   to: '/inventory/reports/movement-summary',   icon: TrendingUp,     permissionGuard: 'view_reports' },
              { label: 'Low Stock Alert',    to: '/inventory/reports/low-stock',          icon: AlertTriangle,  permissionGuard: 'view_reports', disabled: true },
              { label: 'Stock Valuation',    to: '/inventory/reports/stock-valuation',    icon: DollarSign,     permissionGuard: 'view_reports', disabled: true },
              { label: 'Batch / Expiry',     to: '/inventory/reports/batch-expiry',       icon: FlaskConical,   permissionGuard: 'view_reports', disabled: true },
              { label: 'Purchase Requests',  to: '/inventory/reports/purchase-requests',  icon: ClipboardList,  permissionGuard: 'view_reports', disabled: true },
              { label: 'Purchase Orders',    to: '/inventory/reports/purchase-orders',    icon: ShoppingBag,    permissionGuard: 'view_reports', disabled: true },
              { label: 'Outstanding POs',    to: '/inventory/reports/outstanding-pos',    icon: Clock,          permissionGuard: 'view_reports', disabled: true },
              { label: 'GRN Report',         to: '/inventory/reports/grn',               icon: PackageCheck,   permissionGuard: 'view_reports', disabled: true },
              { label: 'Supplier Wise GRN Details', to: '/inventory/reports/supplier-wise-grn-details', icon: Truck, permissionGuard: 'view_reports' },
              { label: 'Supplier Summary',   to: '/inventory/reports/supplier-summary',   icon: Users,          permissionGuard: 'view_reports', disabled: true },
              { label: 'Landed Costs',       to: '/inventory/reports/landed-costs',       icon: Receipt,        permissionGuard: 'view_reports', disabled: true },
            ],
          },
        ],
      },
      {
        label: 'Configuration',
        icon: Settings2,
        isSubgroup: true,
        children: [
          { label: 'Industries',       to: '/inventory/industries',       icon: Building2,         permissionGuard: 'view_industries' },
          { label: 'Companies',        to: '/inventory/companies',        icon: Building2,         permissionGuard: 'view_companies' },
          { label: 'Locations',        to: '/inventory/locations',        icon: MapPin,            permissionGuard: 'view_locations' },
          { label: 'Stores',           to: '/inventory/stores',           icon: Store,             permissionGuard: 'view_stores' },
          { label: 'Store Types',      to: '/inventory/store-types',      icon: Warehouse,         permissionGuard: 'view_store_types' },
          { label: 'Customers',        to: '/inventory/customers',        icon: UsersRound,        permissionGuard: 'view_customer_masters' },
          { label: 'Suppliers',        to: '/inventory/suppliers',        icon: Truck,             permissionGuard: 'view_supplier_masters' },
          { label: 'Unit Categories',  to: '/inventory/unit-categories',  icon: Tag,               permissionGuard: 'view_unit_categories' },
          { label: 'Unit Types',       to: '/inventory/unit-types',       icon: Ruler,             permissionGuard: 'view_unit_types' },
          { label: 'Unit Conversions', to: '/inventory/unit-conversions', icon: ArrowLeftRight,    permissionGuard: 'view_unit_conversions' },
          { label: 'Attribute Types',  to: '/inventory/attribute-types',  icon: Layers,            permissionGuard: 'view_attribute_types' },
          { label: 'Attributes',       to: '/inventory/attributes',       icon: SlidersHorizontal, permissionGuard: 'view_attributes' },
          { label: 'Drivers',          to: '/inventory/drivers',          icon: Car,               permissionGuard: 'view_drivers' },
          { label: 'Vehicles',         to: '/inventory/vehicles',         icon: Bus,               permissionGuard: 'view_vehicle_masters' },
          { label: 'Categories',       to: '/inventory/categories',       icon: FolderTree,        permissionGuard: 'view_categories' },
          { label: 'Products',         to: '/inventory/products',         icon: Box,               permissionGuard: 'view_products' },
          { label: 'Sales Channels',   to: '/inventory/sales-channels',   icon: ShoppingCart,      permissionGuard: 'view_sales_channels' },
          { label: 'Payment Modes',    to: '/inventory/payment-modes',    icon: CreditCard,        permissionGuard: 'view_payment_modes' },
        ],
      },
    ],
  },
  {
    label: 'Finance',
    icon: DollarSign,
    moduleKey: 'finance',
    children: [],
  },
  {
    label: 'HR',
    icon: Users,
    moduleKey: 'hr',
    children: [],
  },
  {
    label: 'Team Management',
    to: '/admin/users',
    icon: UserCog,
    permGuard: 'view_users',
  },
  {
    label: 'Roles & Permissions',
    to: '/admin/roles',
    icon: Shield,
    permGuard: 'manage_roles',
  },
]

// ── Recursive nav helpers ────────────────────────────────────────────────────
// A subgroup can itself contain subgroups (Reports → Sales Report → pages), so
// visibility, flattening (collapsed sidebar) and indentation all need to recurse
// rather than assume a fixed two-level shape.

function isNavItemVisible(node, isSuperAdmin, userPermissions) {
  if (node.isSubgroup) {
    return (node.children ?? []).some((c) => isNavItemVisible(c, isSuperAdmin, userPermissions))
  }
  return !node.permissionGuard || isSuperAdmin || userPermissions.includes(node.permissionGuard)
}

function flattenLeaves(items) {
  return (items ?? []).flatMap((child) => (child.isSubgroup ? flattenLeaves(child.children) : [child]))
}

// Left padding grows with nesting depth. Kept as literal class names (not built from
// a template string) so Tailwind's JIT scanner can find and generate them.
const NAV_INDENT_PL = ['pl-5', 'pl-9', 'pl-[52px]', 'pl-[68px]']
const navIndentPl = (depth) => NAV_INDENT_PL[Math.min(depth, NAV_INDENT_PL.length - 1)]

/** Renders one nesting level of subgroup/leaf items; recurses into child subgroups. */
function renderNavItems({ items, parentKey, depth, isSuperAdmin, userPermissions, openSubgroups, toggleSubgroup }) {
  return (items ?? [])
    .filter((item) => isNavItemVisible(item, isSuperAdmin, userPermissions))
    .map((item) => {
      if (item.isSubgroup) {
        const { label: sg, icon: SGIcon, children: sgChildren } = item
        const sgKey  = `${parentKey}__${sg}`
        const isOpen = !!openSubgroups[sgKey]
        return (
          <div key={sgKey}>
            <button
              type="button"
              onClick={() => toggleSubgroup(sgKey)}
              className={`flex w-full items-center gap-2 rounded-lg ${navIndentPl(depth)} pr-3 py-2 text-sm text-slate-400 hover:bg-slate-800 hover:text-white transition-colors duration-150`}
            >
              {SGIcon && <SGIcon size={16} className="shrink-0" />}
              <span className="flex-1 text-left">{sg}</span>
              <ChevronDown
                size={14}
                className={['shrink-0 transition-transform duration-200', isOpen ? 'rotate-180' : ''].join(' ')}
              />
            </button>
            {isOpen && renderNavItems({
              items: sgChildren, parentKey: sgKey, depth: depth + 1,
              isSuperAdmin, userPermissions, openSubgroups, toggleSubgroup,
            })}
          </div>
        )
      }

      // Depth-0 leaves sit directly under a module (e.g. "Purchase Requests") and read
      // slightly larger than leaves nested inside a subgroup — same as before this
      // renderer had to handle arbitrary nesting.
      const { label: cl, to: ct, icon: CI, disabled } = item
      const leafPy       = depth === 0 ? 'py-2' : 'py-1.5'
      const leafIconSize = depth === 0 ? 16 : 14

      if (disabled) {
        return (
          <div
            key={cl}
            title="Coming soon"
            className={`flex cursor-not-allowed items-center gap-3 rounded-lg ${navIndentPl(depth)} pr-3 ${leafPy} text-sm text-slate-600`}
          >
            {CI ? <CI size={leafIconSize} className="shrink-0" /> : <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-60" />}
            <span>{cl}</span>
          </div>
        )
      }
      return (
        <NavLink
          key={cl}
          to={ct}
          className={({ isActive }) =>
            [
              `flex items-center gap-3 rounded-lg ${navIndentPl(depth)} pr-3 ${leafPy} text-sm transition-colors duration-150`,
              isActive ? 'bg-indigo-600/20 text-indigo-400 font-medium' : 'text-slate-400 hover:bg-slate-800 hover:text-white',
            ].join(' ')
          }
        >
          {CI ? <CI size={leafIconSize} className="shrink-0" /> : <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-60" />}
          <span>{cl}</span>
        </NavLink>
      )
    })
}

function readUserPermissions() {
  try {
    const parsed = JSON.parse(localStorage.getItem('user_permissions') ?? '[]')
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function readActiveModules() {
  try {
    const parsed = JSON.parse(localStorage.getItem('active_modules') ?? '[]')
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function readUserRoles() {
  try {
    const parsed = JSON.parse(localStorage.getItem('user_roles') ?? '[]')
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

export default function Sidebar({ collapsed, onToggleCollapse, mobileOpen, onClose }) {
  const activeModules   = readActiveModules()
  const userRoles       = readUserRoles()
  const userPermissions = readUserPermissions()
  const isSuperAdmin    = userRoles.includes('super_admin')
  const navigate        = useNavigate()

  const [lockedModule, setLockedModule]       = useState(null)
  const [openSubgroups, setOpenSubgroups]     = useState({})

  const toggleSubgroup = (key) =>
    setOpenSubgroups((prev) => ({ ...prev, [key]: !prev[key] }))

  const userName    = localStorage.getItem('user_name')  || ''
  const userEmail   = localStorage.getItem('user_email') || ''
  const displayName = userName || userEmail || 'User'
  const initials    = userName
    ? userName.split(/\s+/).map((w) => w[0]?.toUpperCase() ?? '').slice(0, 2).join('')
    : (userEmail[0]?.toUpperCase() ?? 'U')

  const handleLogout = () => {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('user_name')
    localStorage.removeItem('user_email')
    localStorage.removeItem('active_modules')
    localStorage.removeItem('user_roles')
    localStorage.removeItem('user_permissions')
    navigate('/login')
  }

  return (
    <>
      <aside
        className={[
          'fixed inset-y-0 left-0 z-30 flex flex-col bg-slate-900 transition-all duration-300 ease-in-out',
          'lg:static lg:shrink-0',
          collapsed ? 'w-64 lg:w-16' : 'w-64',
          mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        ].join(' ')}
      >
        {/* ── Header ── */}
        <div
          className={[
            'flex h-16 shrink-0 items-center border-b border-slate-700/60',
            collapsed ? 'justify-center' : 'justify-between px-4',
          ].join(' ')}
        >
          {!collapsed && (
            <span className="text-base font-bold uppercase tracking-widest text-white">
              ERP
            </span>
          )}

          <button
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-700 hover:text-white lg:hidden"
            aria-label="Close sidebar"
          >
            <X size={18} />
          </button>

          <button
            onClick={onToggleCollapse}
            className="hidden rounded-lg p-1.5 text-slate-400 hover:bg-slate-700 hover:text-white lg:block"
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            {collapsed ? <ChevronRight size={18} /> : <ChevronLeft size={18} />}
          </button>
        </div>

        {/* ── Navigation ── */}
        <nav className="flex-1 overflow-y-auto px-2 py-4 space-y-1">
          {NAV_ITEMS.map((item) => {
            const { label, to, icon: Icon, moduleKey, roleGuard, permGuard, children } = item

            // ── Permission-gated standalone link (e.g. Team Management, Roles) ──
            if (permGuard) {
              const hasAccess = isSuperAdmin || userPermissions.includes(permGuard)
              if (!hasAccess) return null

              return (
                <NavLink
                  key={label}
                  to={to}
                  title={collapsed ? label : undefined}
                  className={({ isActive }) =>
                    [
                      'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150',
                      collapsed ? 'justify-center' : '',
                      isActive
                        ? 'bg-indigo-600 text-white'
                        : 'text-slate-400 hover:bg-slate-800 hover:text-white',
                    ].join(' ')
                  }
                >
                  <Icon size={20} className="shrink-0" />
                  {!collapsed && <span>{label}</span>}
                </NavLink>
              )
            }

            // ── Role-gated standalone link ──
            if (roleGuard) {
              const hasAccess = roleGuard.some((r) => userRoles.includes(r))
              if (!hasAccess) return null

              return (
                <NavLink
                  key={label}
                  to={to}
                  title={collapsed ? label : undefined}
                  className={({ isActive }) =>
                    [
                      'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150',
                      collapsed ? 'justify-center' : '',
                      isActive
                        ? 'bg-indigo-600 text-white'
                        : 'text-slate-400 hover:bg-slate-800 hover:text-white',
                    ].join(' ')
                  }
                >
                  <Icon size={20} className="shrink-0" />
                  {!collapsed && <span>{label}</span>}
                </NavLink>
              )
            }

            // ── Standard standalone link (no guard) ──
            if (!moduleKey) {
              return (
                <NavLink
                  key={label}
                  to={to}
                  title={collapsed ? label : undefined}
                  className={({ isActive }) =>
                    [
                      'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors duration-150',
                      collapsed ? 'justify-center' : '',
                      isActive
                        ? 'bg-indigo-600 text-white'
                        : 'text-slate-400 hover:bg-slate-800 hover:text-white',
                    ].join(' ')
                  }
                >
                  <Icon size={20} className="shrink-0" />
                  {!collapsed && <span>{label}</span>}
                </NavLink>
              )
            }

            const isUnlocked = activeModules.includes(moduleKey)

            // ── Locked module ──
            if (!isUnlocked) {
              return (
                <div key={label}>
                  <button
                    type="button"
                    title={collapsed ? `${label} (locked)` : undefined}
                    onClick={() => setLockedModule(item)}
                    className={[
                      'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-xs font-semibold uppercase tracking-wider',
                      'text-slate-600 hover:text-slate-400 transition-colors duration-150',
                      collapsed ? 'justify-center' : '',
                    ].join(' ')}
                  >
                    <Icon size={18} className="shrink-0" />
                    {!collapsed && (
                      <>
                        <span className="flex-1 text-left">{label}</span>
                        <Lock size={12} className="shrink-0" />
                      </>
                    )}
                  </button>
                </div>
              )
            }

            // ── Unlocked module — section header + always-visible children ──
            return (
              <div key={label} className="pt-2 first:pt-0">
                {collapsed ? (
                  <button
                    type="button"
                    title={label}
                    onClick={() => children?.[0] && navigate(children[0].to)}
                    className="flex w-full justify-center rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-800 hover:text-slate-300 transition-colors duration-150"
                  >
                    <Icon size={18} className="shrink-0" />
                  </button>
                ) : (
                  <div className="flex items-center gap-2 px-3 pb-1">
                    <Icon size={14} className="shrink-0 text-slate-500" />
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                      {label}
                    </span>
                  </div>
                )}

                {/* Children — always visible when expanded (subgroups may nest, e.g. Reports → Sales Report → pages) */}
                {!collapsed && renderNavItems({
                  items: children, parentKey: label, depth: 0,
                  isSuperAdmin, userPermissions, openSubgroups, toggleSubgroup,
                })}

                {/* Collapsed: show each leaf icon (flatten all nesting levels) */}
                {collapsed && flattenLeaves(children)
                  .filter((leaf) => isNavItemVisible(leaf, isSuperAdmin, userPermissions))
                  .map(({ label: cl, to: ct, icon: CI, disabled }) => (
                  disabled ? (
                    <div
                      key={cl}
                      title={`${cl} (coming soon)`}
                      className="flex w-full cursor-not-allowed justify-center rounded-lg px-3 py-2 text-slate-700"
                    >
                      {CI
                        ? <CI size={16} className="shrink-0" />
                        : <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />
                      }
                    </div>
                  ) : (
                    <NavLink
                      key={cl}
                      to={ct}
                      title={cl}
                      className={({ isActive }) =>
                        [
                          'flex w-full justify-center rounded-lg px-3 py-2 transition-colors duration-150',
                          isActive
                            ? 'text-indigo-400'
                            : 'text-slate-500 hover:bg-slate-800 hover:text-white',
                        ].join(' ')
                      }
                    >
                      {CI
                        ? <CI size={16} className="shrink-0" />
                        : <span className="h-1.5 w-1.5 rounded-full bg-current opacity-60" />
                      }
                    </NavLink>
                  )
                ))}
              </div>
            )
          })}
        </nav>

        {/* ── Footer: user info + sign out ── */}
        <div className="shrink-0 border-t border-slate-700/60">
          {collapsed ? (
            <div className="flex flex-col items-center gap-2 py-3">
              <span className="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">
                {initials}
              </span>
              <button
                onClick={handleLogout}
                title="Sign out"
                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-700 hover:text-red-400 transition-colors"
              >
                <LogOut size={16} />
              </button>
            </div>
          ) : (
            <div className="flex items-center gap-2 px-3 py-3">
              <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">
                {initials}
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-white">{displayName}</p>
                {userEmail && (
                  <p className="truncate text-[11px] text-slate-400">{userEmail}</p>
                )}
              </div>
              <button
                onClick={handleLogout}
                title="Sign out"
                className="shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-slate-700 hover:text-red-400 transition-colors"
              >
                <LogOut size={16} />
              </button>
            </div>
          )}
        </div>
      </aside>

      <UpgradeModal module={lockedModule} onClose={() => setLockedModule(null)} />
    </>
  )
}
