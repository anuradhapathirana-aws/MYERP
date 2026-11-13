import { SECTIONS, isSectionVisible } from './sections'

/**
 * Each content file is its own code-split chunk, loaded only when one of its
 * chapters is visible, so a Finance-off installation never downloads the
 * Finance chapters.
 *
 * A content file exports `topics`: an array of topic objects (see topicSchema.js).
 */
const LOADERS = {
  core:      () => import('./content/core.js'),
  flows:     () => import('./content/flows.js'),
  inventory: () => import('./content/inventory.js'),
  sales:     () => import('./content/sales.js'),
  master:    () => import('./content/masterData.js'),
  finance:   () => import('./content/finance.js'),
}

/** Which loader holds which chapters. */
const FILE_OF_SECTION = {
  'getting-started': 'core',
  flows:             'flows',
  purchasing:        'inventory',
  stock:             'inventory',
  sales:             'sales',
  'inv-config':      'inventory',
  reports:           'sales',
  'master-data':     'master',
  finance:           'finance',
  admin:             'core',
  troubleshooting:   'core',
  glossary:          'core',
  support:           'core',
}

/**
 * Load every topic the user can see, in chapter order.
 *
 * @param {string[]} activeModules
 * @returns {Promise<Array<object>>}
 */
export async function loadTopics(activeModules) {
  const visible = SECTIONS.filter((s) => isSectionVisible(s, activeModules))
  const files = [...new Set(visible.map((s) => FILE_OF_SECTION[s.id]))]

  const loaded = await Promise.all(files.map((f) => LOADERS[f]().then((m) => m.topics ?? [])))
  const all = loaded.flat()

  const order = new Map(visible.map((s, i) => [s.id, i]))
  return all
    .filter((t) => order.has(t.section) && (!t.module || activeModules.includes(t.module)))
    .map((t, i) => ({ ...t, _i: i }))
    .sort((a, b) => order.get(a.section) - order.get(b.section) || a._i - b._i)
}
