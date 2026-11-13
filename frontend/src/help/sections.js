import {
  BarChart3, BookA, Boxes, Database, Headset, Landmark, LifeBuoy, Rocket,
  Settings2, ShieldCheck, ShoppingBag, ShoppingCart, Workflow,
} from 'lucide-react'

/**
 * The User Manual's chapters, in reading order.
 *
 * module → which content file holds the chapter and which module must be
 *          switched on for it to show ('core' always shows; 'master' shows when
 *          any module is on, like the Master Data menu).
 */
export const SECTIONS = [
  { id: 'getting-started', title: 'Getting Started',       icon: Rocket,       module: 'core',      description: 'Sign in, find your way around, and the rules every screen follows.' },
  { id: 'flows',           title: 'Business Flows',        icon: Workflow,     module: 'core',      description: 'The big picture: how documents connect from purchase to payment and from order to cash.' },
  { id: 'purchasing',      title: 'Purchasing',            icon: ShoppingBag,  module: 'inventory', description: 'Purchase requests, orders, goods received, costing, supplier returns and payments.' },
  { id: 'stock',           title: 'Stock',                 icon: Boxes,        module: 'inventory', description: 'Stock reconciliation, units, batches and rolls.' },
  { id: 'sales',           title: 'Sales',                 icon: ShoppingCart, module: 'inventory', description: 'Proformas, sales orders, deliveries, invoices, receipts and customer returns.' },
  { id: 'inv-config',      title: 'Inventory Setup',       icon: Settings2,    module: 'inventory', description: 'Products, stores, customers, units, attributes, drivers and vehicles.' },
  { id: 'master-data',     title: 'Master Data',           icon: Database,     module: 'master',    description: 'Companies, locations, suppliers, banks, payment modes and taxes.' },
  { id: 'finance',         title: 'Finance',               icon: Landmark,     module: 'finance',   description: 'Chart of accounts, journals, bills, bank deposits and reconciliation.' },
  { id: 'reports',         title: 'Reports',               icon: BarChart3,    module: 'inventory', description: 'What each report shows and when to use it.' },
  { id: 'admin',           title: 'Administration',        icon: ShieldCheck,  module: 'core',      description: 'Users, roles & permissions and system settings.' },
  { id: 'troubleshooting', title: 'Troubleshooting & FAQ', icon: LifeBuoy,     module: 'core',      description: 'Common messages, what they mean and how to fix them.' },
  { id: 'glossary',        title: 'Glossary',              icon: BookA,        module: 'core',      description: 'Short meanings of the terms and abbreviations used in the system.' },
  { id: 'support',         title: 'Contact Support',       icon: Headset,      module: 'core',      description: 'When and how to contact your IT support company.' },
]

export const sectionById = (id) => SECTIONS.find((s) => s.id === id)

/** Whether a chapter belongs to a module this installation has switched on. */
export function isSectionVisible(section, activeModules) {
  if (section.module === 'core') return true
  if (section.module === 'master') return activeModules.length > 0
  return activeModules.includes(section.module)
}
