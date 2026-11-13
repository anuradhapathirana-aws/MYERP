import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  BookOpen, ChevronDown, ChevronRight, FileSearch, Keyboard, Loader2, MousePointerClick,
  Printer, Search, X,
} from 'lucide-react'
import Breadcrumb from '../../components/Breadcrumb'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import { getMe } from '../../api/system'
import { readProfile } from '../../utils/session'
import { SECTIONS, isSectionVisible, sectionById } from '../../help/sections'
import { loadTopics } from '../../help/registry'
import { buildIndex, queryTerms, searchTopics } from '../../help/search'
import TopicView from '../../help/components/TopicView'
import { topicAnchors } from '../../help/anchors'
import SupportCard from '../../help/components/SupportCard'
import RichText from '../../help/components/RichText'

const SHORTCUTS = [
  { keys: ['/'],          desc: 'Search the manual' },
  { keys: ['Ctrl', 'K'],  desc: 'Search the manual' },
  { keys: ['↑', '↓'],     desc: 'Move through search results' },
  { keys: ['Enter'],      desc: 'Open the selected result' },
  { keys: ['Esc'],        desc: 'Clear the search' },
  { keys: ['Alt', '←'],   desc: 'Previous topic' },
  { keys: ['Alt', '→'],   desc: 'Next topic' },
]

const START_HERE = ['gs-welcome', 'gs-layout', 'gs-draft-confirm', 'flow-purchase-to-pay', 'flow-order-to-cash', 'ts-error-messages']

const scrollMainTop = () => document.querySelector('main')?.scrollTo({ top: 0 })

function printPage() {
  document.documentElement.classList.add('print-help')
  const done = () => {
    document.documentElement.classList.remove('print-help')
    window.removeEventListener('afterprint', done)
  }
  window.addEventListener('afterprint', done)
  window.print()
}

// ── Chapter navigation (left column) ─────────────────────────────────────────
function ChapterNav({ sections, bySection, activeSection, activeTopic }) {
  // What the user opened or closed; otherwise only the current chapter is open.
  const [toggled, setToggled] = useState({})
  const isOpenFor = (id) => toggled[id] ?? id === activeSection
  const toggle = (id) => setToggled((t) => ({ ...t, [id]: !isOpenFor(id) }))

  return (
    <nav className="space-y-0.5">
      <Link to="/help" className="mb-1 flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
        <BookOpen size={13} className="text-indigo-600" /> Manual Home
      </Link>
      {sections.map((s) => {
        const items = bySection.get(s.id) ?? []
        const isOpen = isOpenFor(s.id)
        const Icon = s.icon
        return (
          <div key={s.id}>
            <div className={`flex items-center rounded-md ${activeSection === s.id ? 'bg-indigo-50' : 'hover:bg-slate-100'}`}>
              <button type="button" onClick={() => toggle(s.id)} className="p-1 text-slate-400" aria-label={isOpen ? 'Collapse' : 'Expand'}>
                {isOpen ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
              </button>
              <Link to={`/help/section/${s.id}`} className="flex min-w-0 flex-1 items-center gap-1.5 py-1 pr-2 text-xs font-semibold text-slate-700">
                <Icon size={13} className="shrink-0 text-indigo-500" />
                <span className="truncate">{s.title}</span>
                <span className="ml-auto rounded bg-slate-100 px-1 text-[10px] font-medium text-slate-500">{items.length}</span>
              </Link>
            </div>
            {isOpen && (
              <ul className="mb-1 ml-5 border-l border-slate-200 pl-1.5">
                {items.map((t) => (
                  <li key={t.id}>
                    <Link
                      to={`/help/${t.id}`}
                      title={t.title}
                      className={`block truncate rounded px-1.5 py-0.5 text-xs ${
                        activeTopic === t.id ? 'bg-indigo-600 font-semibold text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                      }`}
                    >
                      {t.title}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )
      })}
    </nav>
  )
}

// ── Home ─────────────────────────────────────────────────────────────────────
function HomeView({ sections, bySection, byId }) {
  const startHere = START_HERE.map((id) => byId.get(id)).filter(Boolean)
  return (
    <div className="space-y-3">
      <div className="rounded-xl border border-indigo-200 bg-linear-to-r from-indigo-50 to-white p-3">
        <h2 className="text-lg font-bold text-slate-900">Welcome to the User Manual</h2>
        <p className="mt-1 text-sm text-slate-600">
          Every screen of the system is explained here: what it is for, how to use it step by step with an example,
          and what happens in the system when you save or confirm.
        </p>
        <div className="mt-2 grid grid-cols-1 gap-2 md:grid-cols-3">
          {[
            { icon: Search,            title: 'Search',          text: 'Type a screen name, a word or even an error message in the search box above.' },
            { icon: MousePointerClick, title: 'Browse',          text: 'Pick a chapter on the left or below. Topics are in the order you use them.' },
            { icon: FileSearch,        title: 'Open the screen', text: 'Each topic has an “Open this screen” button that takes you straight there.' },
          ].map(({ icon: Icon, title, text }) => (
            <div key={title} className="flex items-start gap-2 rounded-lg bg-white/80 p-2 ring-1 ring-indigo-100">
              <Icon size={16} className="mt-0.5 shrink-0 text-indigo-600" />
              <div>
                <div className="text-xs font-semibold text-slate-800">{title}</div>
                <div className="text-xs text-slate-600">{text}</div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {startHere.length > 0 && (
        <div className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
          <div className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">New here? Start with these</div>
          <div className="flex flex-wrap gap-1.5">
            {startHere.map((t) => (
              <Link key={t.id} to={`/help/${t.id}`} className="rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100">
                {t.title}
              </Link>
            ))}
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
        {sections.map((s) => {
          const items = bySection.get(s.id) ?? []
          const Icon = s.icon
          return (
            <div key={s.id} className="flex flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
              <Link to={`/help/section/${s.id}`} className="flex items-center gap-2">
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600"><Icon size={16} /></span>
                <div>
                  <div className="text-sm font-semibold text-slate-800 hover:text-indigo-700">{s.title}</div>
                  <div className="text-[11px] text-slate-400">{items.length} topic{items.length === 1 ? '' : 's'}</div>
                </div>
              </Link>
              <p className="mt-1.5 text-xs text-slate-500">{s.description}</p>
              <ul className="mt-1.5 space-y-0.5">
                {items.slice(0, 5).map((t) => (
                  <li key={t.id}>
                    <Link to={`/help/${t.id}`} className="flex items-center gap-1 text-xs text-indigo-700 hover:underline">
                      <ChevronRight size={11} className="text-slate-300" /> {t.title}
                    </Link>
                  </li>
                ))}
                {items.length > 5 && (
                  <li><Link to={`/help/section/${s.id}`} className="pl-4 text-[11px] font-semibold text-slate-500 hover:text-indigo-700">+ {items.length - 5} more…</Link></li>
                )}
                {items.length === 0 && <li className="text-[11px] italic text-slate-400">Coming soon.</li>}
              </ul>
            </div>
          )
        })}
      </div>
    </div>
  )
}

// ── Chapter page ─────────────────────────────────────────────────────────────
function SectionView({ section, items, full, onToggleFull, byId }) {
  const Icon = section.icon
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-start justify-between gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <div className="flex items-start gap-2">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600"><Icon size={18} /></span>
          <div>
            <h2 className="text-lg font-bold text-slate-900">{section.title}</h2>
            <p className="text-sm text-slate-600">{section.description}</p>
          </div>
        </div>
        <button type="button" onClick={onToggleFull} className="no-print rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50">
          {full ? 'Show topic list' : 'Show the whole chapter (for printing)'}
        </button>
      </div>

      {items.length === 0 && (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
          This chapter is being written and will be added soon.
        </div>
      )}

      {full
        ? items.map((t) => (
          <div key={t.id} className="break-before-page">
            <TopicView topic={t} section={section} related={(t.related ?? []).map((id) => byId.get(id)).filter(Boolean)} />
          </div>
        ))
        : (
          <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
            {items.map((t, i) => (
              <Link key={t.id} to={`/help/${t.id}`} className="group flex items-start gap-2 rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm hover:border-indigo-300">
                <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-500 group-hover:bg-indigo-600 group-hover:text-white">{i + 1}</span>
                <div className="min-w-0">
                  <div className="text-sm font-semibold text-slate-800 group-hover:text-indigo-700">{t.title}</div>
                  <div className="line-clamp-2 text-xs text-slate-500"><RichText text={t.summary} /></div>
                </div>
              </Link>
            ))}
          </div>
        )}
    </div>
  )
}

// ── Search results ───────────────────────────────────────────────────────────
function ResultsView({ q, results, terms, selected, onHover }) {
  return (
    <div className="space-y-2">
      <div className="text-xs text-slate-500">
        {results.length === 0
          ? <>No topics match <b className="text-slate-700">“{q}”</b>. Try fewer or different words, e.g. the screen name.</>
          : <>{results.length} topic{results.length === 1 ? '' : 's'} match <b className="text-slate-700">“{q}”</b></>}
      </div>
      {results.map((r, i) => {
        const section = sectionById(r.topic.section)
        return (
          <Link
            key={r.topic.id}
            id={`help-result-${i}`}
            to={`/help/${r.topic.id}?q=${encodeURIComponent(q)}`}
            onMouseEnter={() => onHover(i)}
            className={`block rounded-xl border bg-white p-2.5 shadow-sm ${i === selected ? 'border-indigo-400 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-indigo-300'}`}
          >
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-sm font-semibold text-indigo-700"><RichText text={r.topic.title} terms={terms} /></span>
              <span className="rounded bg-slate-100 px-1.5 py-px text-[10px] font-medium text-slate-500">{section?.title}</span>
            </div>
            <p className="mt-0.5 line-clamp-2 text-xs text-slate-600"><RichText text={r.snippet} terms={terms} /></p>
          </Link>
        )
      })}
      {results.length === 0 && <div className="max-w-md pt-2"><SupportCard /></div>}
    </div>
  )
}

// ── Page ─────────────────────────────────────────────────────────────────────
/**
 * The User Manual: a searchable, printable help center covering every screen
 * of the modules this installation has switched on.
 */
export default function HelpCenterPage() {
  const { topicId, sectionId } = useParams()
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const searchRef = useRef(null)
  const q = params.get('q') ?? ''
  const [selected, setSelected] = useState(0)
  const [fullChapter, setFullChapter] = useState(false)
  // The page remounts per URL (see App.jsx Lazy), so this resets on every topic.
  const [typed, setTyped] = useState(false)

  const { data: me } = useQuery({ queryKey: ['me'], queryFn: getMe, staleTime: 60_000 })
  const activeModules = useMemo(() => (me ?? readProfile()).active_modules ?? [], [me])
  const modulesKey = [...activeModules].sort().join(',')

  const { data: topics = [], isLoading, isError } = useQuery({
    queryKey: ['help-topics', modulesKey],
    queryFn: () => loadTopics(activeModules),
    staleTime: Infinity,
  })

  const sections = useMemo(() => SECTIONS.filter((s) => isSectionVisible(s, activeModules)), [activeModules])
  const byId = useMemo(() => new Map(topics.map((t) => [t.id, t])), [topics])
  const bySection = useMemo(() => {
    const m = new Map()
    topics.forEach((t) => m.set(t.section, [...(m.get(t.section) ?? []), t]))
    return m
  }, [topics])
  const index = useMemo(() => buildIndex(topics), [topics])

  const results = useMemo(() => (q.trim() ? searchTopics(index, q) : []), [index, q])
  const terms = useMemo(() => queryTerms(q), [q])

  const topic = topicId ? byId.get(topicId) : null
  const section = sectionById(topic?.section ?? sectionId)
  const pos = topic ? topics.indexOf(topic) : -1
  const prev = pos > 0 ? topics[pos - 1] : null
  const next = pos >= 0 && pos < topics.length - 1 ? topics[pos + 1] : null
  // A topic opened from the results keeps the search words highlighted;
  // typing a new search on any page shows the results list.
  const showResults = q.trim() !== '' && (!topic || typed)
  const searching = q.trim() !== '' && topic && !typed

  const setQuery = useCallback((value) => {
    setSelected(0)
    setTyped(true)
    setParams(value ? { q: value } : {}, { replace: true })
  }, [setParams])

  useEffect(() => { scrollMainTop() }, [topicId, sectionId])

  // Keyboard: / or Ctrl+K search, arrows/Enter through results, Esc clears, Alt+←/→ topics.
  useEffect(() => {
    const onKey = (e) => {
      const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)
      if ((e.key === '/' && !typing) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
        e.preventDefault()
        searchRef.current?.focus()
        searchRef.current?.select()
        return
      }
      if (e.altKey && e.key === 'ArrowLeft' && prev) { e.preventDefault(); navigate(`/help/${prev.id}`) }
      if (e.altKey && e.key === 'ArrowRight' && next) { e.preventDefault(); navigate(`/help/${next.id}`) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [prev, next, navigate])

  const onSearchKey = (e) => {
    if (e.key === 'Escape') { setQuery(''); e.currentTarget.blur(); return }
    if (!showResults || results.length === 0) return
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      const n = e.key === 'ArrowDown' ? Math.min(selected + 1, results.length - 1) : Math.max(selected - 1, 0)
      setSelected(n)
      document.getElementById(`help-result-${n}`)?.scrollIntoView({ block: 'nearest' })
    }
    if (e.key === 'Enter') {
      e.preventDefault()
      const r = results[selected]
      if (r) navigate(`/help/${r.topic.id}?q=${encodeURIComponent(q)}`)
    }
  }

  const crumbs = [{ label: 'User Manual', to: '/help' }]
  if (section && (topic || sectionId)) crumbs.push(topic ? { label: section.title, to: `/help/section/${section.id}` } : { label: section.title })
  if (topic) crumbs.push({ label: topic.title })
  if (crumbs.length === 1) crumbs[0] = { label: 'User Manual' }

  const anchors = topic ? topicAnchors(topic) : []

  let body
  if (isLoading) {
    body = <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-8 text-sm text-slate-400"><Loader2 size={16} className="animate-spin" /> Loading the manual…</div>
  } else if (isError) {
    body = <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">The manual could not be loaded. Refresh the page; if it keeps happening, contact support.</div>
  } else if (showResults) {
    body = <ResultsView q={q} results={results} terms={terms} selected={selected} onHover={setSelected} />
  } else if (topicId && !topic) {
    body = (
      <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        This topic is not available. It may belong to a module that is not switched on for your company.{' '}
        <Link to="/help" className="font-semibold underline">Go to the manual home</Link>.
      </div>
    )
  } else if (topic) {
    body = (
      <TopicView
        topic={topic}
        section={section}
        prev={prev}
        next={next}
        terms={searching ? terms : []}
        related={(topic.related ?? []).map((id) => byId.get(id)).filter(Boolean)}
      />
    )
  } else if (sectionId && section && sections.includes(section)) {
    body = <SectionView section={section} items={bySection.get(section.id) ?? []} full={fullChapter} onToggleFull={() => setFullChapter((f) => !f)} byId={byId} />
  } else {
    body = <HomeView sections={sections} bySection={bySection} byId={byId} />
  }

  return (
    <div className="help-print-root">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">User Manual</h1>
          <Breadcrumb crumbs={crumbs} />
        </div>
        <button
          type="button"
          onClick={printPage}
          className="no-print flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
          title="Print or save as PDF"
        >
          <Printer size={13} /> Print / PDF
        </button>
      </div>

      {/* Search */}
      <div className="no-print relative mt-2 max-w-3xl">
        <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input
          ref={searchRef}
          type="search"
          value={q}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={onSearchKey}
          placeholder="Search the manual… e.g. GRN, cheque return, “Payment is underfunded”"
          className="block w-full rounded-xl border-2 border-indigo-200 bg-white py-2 pl-9 pr-20 text-sm text-slate-800 placeholder-slate-400 shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/15"
          aria-label="Search the manual"
        />
        <div className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
          {q && (
            <button type="button" onClick={() => setQuery('')} className="rounded p-0.5 text-slate-400 hover:text-slate-700" aria-label="Clear search">
              <X size={14} />
            </button>
          )}
          <span className="hidden items-center gap-0.5 text-[10px] text-slate-400 sm:flex">
            <Keyboard size={11} /> <kbd className="rounded border border-slate-300 bg-slate-50 px-1 font-mono">/</kbd>
          </span>
        </div>
      </div>

      {/* Mobile chapter list */}
      <details className="no-print mt-2 rounded-xl border border-slate-200 bg-white p-2 lg:hidden">
        <summary className="cursor-pointer text-xs font-semibold text-slate-700">Chapters</summary>
        <div className="mt-1.5">
          <ChapterNav sections={sections} bySection={bySection} activeSection={section?.id} activeTopic={topic?.id} />
        </div>
      </details>

      <div className="help-layout mt-3 grid grid-cols-1 gap-3 lg:grid-cols-[240px_minmax(0,1fr)] xl:grid-cols-[250px_minmax(0,1fr)_220px]">
        <aside className="no-print hidden lg:block">
          <div className="sticky top-0 max-h-[calc(100vh-1.5rem)] overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm thin-scroll">
            <ChapterNav sections={sections} bySection={bySection} activeSection={section?.id} activeTopic={topic?.id} />
          </div>
        </aside>

        <div className="min-w-0">{body}</div>

        <aside className="no-print hidden xl:block">
          <div className="sticky top-0 space-y-3">
            {anchors.length > 0 && !showResults && (
              <div className="rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm">
                <div className="mb-1 text-[11px] font-bold uppercase tracking-wider text-slate-500">On this page</div>
                <ul className="space-y-0.5">
                  {anchors.map((a) => (
                    <li key={a.id}>
                      <button
                        type="button"
                        onClick={() => document.getElementById(a.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
                        className="text-left text-xs text-slate-600 hover:text-indigo-700"
                      >
                        {a.label}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <SupportCard compact={anchors.length > 0} />
          </div>
        </aside>
      </div>

      <div className="no-print">
        <KeyboardShortcuts shortcuts={SHORTCUTS} />
      </div>
    </div>
  )
}
