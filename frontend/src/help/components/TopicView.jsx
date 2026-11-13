import { Link } from 'react-router-dom'
import {
  AlertTriangle, ArrowLeft, ArrowRight, BookOpen, CheckCircle2, ClipboardCheck, Cog,
  ExternalLink, HelpCircle, Lightbulb, ListOrdered, MapPin, Shield, Sparkles, Table2,
} from 'lucide-react'
import FlowDiagram from './FlowDiagram'
import StatusDiagram from './StatusDiagram'
import ScreenSketch from './ScreenSketch'
import SupportCard from './SupportCard'
import RichText from './RichText'

function Section({ id, icon: Icon, title, children, tone = 'text-indigo-600' }) {
  return (
    <section id={id} className="scroll-mt-3 break-inside-avoid-page">
      <h3 className="mb-1.5 flex items-center gap-1.5 text-sm font-bold text-slate-800">
        <Icon size={15} className={tone} /> {title}
      </h3>
      {children}
    </section>
  )
}

function Bullets({ items, terms, icon: Icon, iconCls }) {
  return (
    <ul className="space-y-1">
      {items.map((it, i) => (
        <li key={i} className="flex items-start gap-1.5 text-sm leading-relaxed text-slate-700">
          <Icon size={13} className={`mt-1 shrink-0 ${iconCls}`} />
          <span><RichText text={it} terms={terms} /></span>
        </li>
      ))}
    </ul>
  )
}

function DataTable({ head, rows, terms }) {
  return (
    <div className="overflow-x-auto rounded-lg border border-slate-200">
      <table className="w-full text-xs">
        <thead className="bg-slate-50">
          <tr>
            {head.map((h) => (
              <th key={h} className="whitespace-nowrap px-2 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={i} className="border-t border-slate-100 align-top even:bg-slate-50/50">
              {r.map((c, j) => (
                <td key={j} className={`px-2 py-1.5 text-slate-700 ${j === 0 ? 'font-medium text-slate-800' : ''}`}>
                  <RichText text={c} terms={terms} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/**
 * One manual topic. Renders only the parts the topic provides, always in the
 * same order, so every topic reads the same way.
 */
export default function TopicView({ topic: t, section, prev, next, related = [], terms = [] }) {
  return (
    <article className="space-y-4">
      <header className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <div className="text-[11px] font-semibold uppercase tracking-wider text-indigo-600">{section?.title}</div>
        <h2 className="mt-0.5 text-xl font-bold text-slate-900">{t.title}</h2>
        <p className="mt-1 text-sm leading-relaxed text-slate-600"><RichText text={t.summary} terms={terms} /></p>

        {(t.where || t.who || t.route) && (
          <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
            {t.where && (
              <span className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-slate-700">
                <MapPin size={12} className="text-slate-500" /> <span className="text-slate-500">Menu:</span> <b className="font-semibold">{t.where}</b>
              </span>
            )}
            {t.who && (
              <span className="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-1 text-amber-800">
                <Shield size={12} /> {t.who}
              </span>
            )}
            {t.route && (
              <Link to={t.route} className="no-print inline-flex items-center gap-1 rounded-md bg-indigo-600 px-2 py-1 font-semibold text-white hover:bg-indigo-700">
                Open this screen <ExternalLink size={12} />
              </Link>
            )}
          </div>
        )}
      </header>

      <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        {t.intro?.map((p, i) => (
          <p key={i} className="text-sm leading-relaxed text-slate-700"><RichText text={p} terms={terms} /></p>
        ))}

        {t.flow && (
          <Section id="diagram" icon={Sparkles} title="Diagram">
            <FlowDiagram spec={t.flow} />
          </Section>
        )}

        {t.before?.length > 0 && (
          <Section id="before" icon={ClipboardCheck} title="Before you start">
            <Bullets items={t.before} terms={terms} icon={CheckCircle2} iconCls="text-slate-400" />
          </Section>
        )}

        {(t.steps?.length > 0 || t.sketch) && (
          <Section id="steps" icon={ListOrdered} title="Step by step">
            <div className={t.sketch ? 'grid grid-cols-1 gap-3 xl:grid-cols-2' : ''}>
              {t.sketch && <div><ScreenSketch spec={t.sketch} /></div>}
              {t.steps?.length > 0 && (
                <ol className="space-y-1.5">
                  {t.steps.map((s, i) => {
                    const step = typeof s === 'string' ? { text: s } : s
                    return (
                      <li key={i} className="flex items-start gap-2">
                        <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">{i + 1}</span>
                        <div className="min-w-0 text-sm leading-relaxed text-slate-700">
                          {step.n && (
                            <span title="Marker on the screen sketch" className="mr-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-rose-600 align-[1px] text-[9px] font-bold text-white">
                              {step.n}
                            </span>
                          )}
                          <RichText text={step.text} terms={terms} />
                          {step.tip && (
                            <div className="mt-0.5 flex items-start gap-1 text-xs text-slate-500">
                              <Lightbulb size={12} className="mt-0.5 shrink-0 text-amber-500" />
                              <span><RichText text={step.tip} terms={terms} /></span>
                            </div>
                          )}
                        </div>
                      </li>
                    )
                  })}
                </ol>
              )}
            </div>
          </Section>
        )}

        {t.example && (
          <Section id="example" icon={BookOpen} title="Example" tone="text-green-600">
            <div className="rounded-lg border border-green-200 bg-green-50/50 p-2.5">
              <p className="text-sm leading-relaxed text-slate-700"><RichText text={t.example.scenario} terms={terms} /></p>
              {t.example.table && <div className="mt-2"><DataTable head={t.example.table.head} rows={t.example.table.rows} terms={terms} /></div>}
              {t.example.result && (
                <div className="mt-2 space-y-1">
                  {[].concat(t.example.result).map((r, i) => (
                    <p key={i} className="flex items-start gap-1.5 text-sm text-green-800">
                      <CheckCircle2 size={14} className="mt-0.5 shrink-0" />
                      <span><RichText text={r} terms={terms} /></span>
                    </p>
                  ))}
                </div>
              )}
            </div>
          </Section>
        )}

        {t.howItWorks?.length > 0 && (
          <Section id="how" icon={Cog} title="How it works in the system">
            <Bullets items={t.howItWorks} terms={terms} icon={ArrowRight} iconCls="text-indigo-400" />
          </Section>
        )}

        {t.statuses && (
          <Section id="statuses" icon={Sparkles} title="Statuses">
            <StatusDiagram spec={t.statuses} />
          </Section>
        )}

        {t.tables?.length > 0 && (
          <Section id="tables" icon={Table2} title="Reference">
            <div className="space-y-3">
              {t.tables.map((tb, i) => (
                <div key={i}>
                  {tb.title && <div className="mb-1 text-xs font-semibold text-slate-600">{tb.title}</div>}
                  <DataTable head={tb.head} rows={tb.rows} terms={terms} />
                </div>
              ))}
            </div>
          </Section>
        )}

        {t.rules?.length > 0 && (
          <Section id="rules" icon={AlertTriangle} title="Rules & limits" tone="text-amber-600">
            <div className="rounded-lg border border-amber-200 bg-amber-50/50 p-2.5">
              <Bullets items={t.rules} terms={terms} icon={AlertTriangle} iconCls="text-amber-500" />
            </div>
          </Section>
        )}

        {t.tips?.length > 0 && (
          <Section id="tips" icon={Lightbulb} title="Tips" tone="text-amber-500">
            <Bullets items={t.tips} terms={terms} icon={Lightbulb} iconCls="text-amber-400" />
          </Section>
        )}

        {t.faq?.length > 0 && (
          <Section id="faq" icon={HelpCircle} title="Common questions">
            <div className="divide-y divide-slate-100 rounded-lg border border-slate-200">
              {t.faq.map((f, i) => (
                <details key={i} className="group px-2.5 py-1.5" open={terms.length > 0}>
                  <summary className="cursor-pointer list-none text-sm font-semibold text-slate-800 marker:hidden">
                    <span className="mr-1 text-indigo-500 group-open:hidden">+</span>
                    <span className="mr-1 hidden text-indigo-500 group-open:inline">−</span>
                    <RichText text={f.q} terms={terms} />
                  </summary>
                  <p className="mt-1 text-sm leading-relaxed text-slate-600"><RichText text={f.a} terms={terms} /></p>
                </details>
              ))}
            </div>
          </Section>
        )}

        {t.showSupport && <div className="max-w-md"><SupportCard /></div>}
      </div>

      {related.length > 0 && (
        <div className="no-print rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
          <div className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">Related topics</div>
          <div className="flex flex-wrap gap-1.5">
            {related.map((r) => (
              <Link key={r.id} to={`/help/${r.id}`} className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-medium text-indigo-700 hover:border-indigo-300 hover:bg-indigo-50">
                {r.title}
              </Link>
            ))}
          </div>
        </div>
      )}

      <nav className="no-print grid grid-cols-2 gap-2">
        <div>
          {prev && (
            <Link to={`/help/${prev.id}`} className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-600 hover:border-indigo-300">
              <ArrowLeft size={13} /> <span className="truncate"><span className="text-slate-400">Previous:</span> {prev.title}</span>
            </Link>
          )}
        </div>
        <div>
          {next && (
            <Link to={`/help/${next.id}`} className="flex items-center justify-end gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-right text-xs text-slate-600 hover:border-indigo-300">
              <span className="truncate"><span className="text-slate-400">Next:</span> {next.title}</span> <ArrowRight size={13} />
            </Link>
          )}
        </div>
      </nav>
    </article>
  )
}
