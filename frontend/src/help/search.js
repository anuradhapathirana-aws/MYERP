/**
 * Client-side search over the loaded topics. No library: the manual is a few
 * hundred KB of text at most, and a weighted token match is fast and predictable.
 *
 * Every query word must appear somewhere in the topic (AND). Matches in the
 * title count most, then keywords, the summary, and finally the body.
 */

const strip = (s) => String(s ?? '').replace(/\*\*|`/g, '')

const norm = (s) => strip(s).toLowerCase()

function bodyText(t) {
  const parts = [
    ...(t.intro ?? []),
    ...(t.before ?? []),
    ...(t.steps ?? []).map((s) => (typeof s === 'string' ? s : `${s.text} ${s.tip ?? ''}`)),
    t.example?.scenario,
    ...[].concat(t.example?.result ?? []),
    ...(t.example?.table?.rows ?? []).flat(),
    ...(t.howItWorks ?? []),
    ...(t.rules ?? []),
    ...(t.tips ?? []),
    ...(t.faq ?? []).flatMap((f) => [f.q, f.a]),
    ...(t.tables ?? []).flatMap((tb) => [tb.title, ...tb.head, ...tb.rows.flat()]),
    ...(t.statuses?.states ?? []).flatMap((s) => [s.name, s.meaning]),
    ...(t.flow?.steps ?? []).flatMap((s) => [s.label, s.sub]),
    t.where,
  ]
  return parts.filter(Boolean).map(strip)
}

/** Pre-compute lower-cased fields once per topic list. */
export function buildIndex(topics) {
  return topics.map((t) => {
    const body = bodyText(t)
    return {
      topic: t,
      title: norm(t.title),
      keywords: norm((t.keywords ?? []).join(' ')),
      summary: norm(t.summary),
      bodyLines: body,
      body: body.join(' \n ').toLowerCase(),
    }
  })
}

export const queryTerms = (q) =>
  norm(q).split(/[\s,]+/).map((w) => w.trim()).filter((w) => w.length > 1 || /\d/.test(w))

/**
 * @returns {Array<{topic: object, score: number, snippet: string}>}
 */
export function searchTopics(index, q, limit = 40) {
  const terms = queryTerms(q)
  if (terms.length === 0) return []

  const results = []
  for (const row of index) {
    let score = 0
    let all = true
    for (const term of terms) {
      let s = 0
      if (row.title.includes(term)) s += row.title.split(/\W+/).includes(term) ? 14 : 10
      if (row.keywords.includes(term)) s += 6
      if (row.summary.includes(term)) s += 3
      if (row.body.includes(term)) s += 1
      if (s === 0) { all = false; break }
      score += s
    }
    if (!all) continue
    // The whole phrase in the title ranks first.
    if (terms.length > 1 && row.title.includes(terms.join(' '))) score += 15

    results.push({ topic: row.topic, score, snippet: snippetFor(row, terms) })
  }

  return results.sort((a, b) => b.score - a.score).slice(0, limit)
}

/** The first body line mentioning a query word, trimmed around it; else the summary. */
function snippetFor(row, terms) {
  if (terms.some((t) => row.summary.includes(t))) return strip(row.topic.summary)
  const line = row.bodyLines.find((l) => terms.some((t) => l.toLowerCase().includes(t)))
  if (!line) return strip(row.topic.summary)
  const lower = line.toLowerCase()
  const at = Math.min(...terms.map((t) => lower.indexOf(t)).filter((i) => i >= 0))
  const start = Math.max(0, at - 60)
  return (start > 0 ? '… ' : '') + line.slice(start, start + 180) + (line.length > start + 180 ? ' …' : '')
}

/** Split text into [{text, hit}] parts for highlighting query words. */
export function highlightParts(text, terms) {
  if (!terms.length) return [{ text, hit: false }]
  const escaped = terms.map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
  const re = new RegExp(`(${escaped.join('|')})`, 'gi')
  const set = new Set(terms.map((t) => t.toLowerCase()))
  // split() with a capture group keeps the matched words as their own parts.
  return String(text).split(re).filter(Boolean).map((p) => ({ text: p, hit: set.has(p.toLowerCase()) }))
}
