import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useParams } from 'react-router-dom'
import { ArrowLeft, Check, Clipboard, Download, ExternalLink, FileSpreadsheet, LoaderCircle, X } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { useToast } from '../context/ToastContext'
import { copyText } from '../utils/clipboard'

const contextSections = ['facts', 'products', 'claims', 'audience_signals', 'positioning', 'value_propositions']
const recommendationSections = [
  ['Coverage gaps', 'gaps'],
  ['Content opportunities', 'content_opportunities'],
  ['Test recommendations', 'test_recommendations'],
  ['Repetition and concentration', 'repetition_and_concentration'],
  ['Long-running patterns', 'long_running_patterns'],
]

export default function AdLibraryAuditReportPage() {
  const { id } = useParams()
  const { features } = useAuth()
  const toast = useToast()
  const [audit, setAudit] = useState(null)
  const [creatives, setCreatives] = useState([])
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [sheetsOpen, setSheetsOpen] = useState(false)
  const [sheets, setSheets] = useState(null)
  const [sheetsLoading, setSheetsLoading] = useState(false)
  const [copiedTab, setCopiedTab] = useState('')

  useEffect(() => {
    let active = true
    Promise.all([
      api.getAdLibraryAudit(id),
      api.getAdLibraryAuditCreatives(id, { status: 'complete', per_page: 100 }),
    ]).then(([auditResponse, creativeResponse]) => {
      if (!active) return
      setAudit(auditResponse.data)
      setCreatives(creativeResponse.data?.data || [])
    }).catch(requestError => {
      if (active) setError(requestError.response?.data?.message || 'Unable to load the final report.')
    }).finally(() => active && setLoading(false))
    return () => { active = false }
  }, [id])

  const products = useMemo(() => [...new Set(creatives.map(item => item.product).filter(Boolean))], [creatives])
  const angles = useMemo(() => [...new Set(creatives.map(item => item.angle).filter(Boolean))], [creatives])
  const formats = useMemo(() => distribution(creatives, 'format'), [creatives])

  if (!features?.ad_library_audits) return <Navigate to="/ad-library" replace />
  if (loading) return <div className="audit-loading"><LoaderCircle className="spin"/> Loading final report…</div>
  if (error || !audit) return <main className="audit-report"><Link className="audit-back" to={`/ad-library/audits/${id}`}><ArrowLeft size={14}/> Back to audit</Link><div className="audit-error" role="alert">{error || 'Report not found.'}</div></main>

  const insights = audit.insights || {}
  const summary = audit.creative_summary || {}
  const openSheets = async () => {
    setSheetsOpen(true)
    if (sheets) return
    setSheetsLoading(true)
    try {
      const { data } = await api.getAdLibraryAuditReportSheets(id)
      setSheets(data)
    } catch (requestError) {
      toast.error(requestError.response?.data?.message || 'Unable to prepare the Google Sheets export.')
    } finally {
      setSheetsLoading(false)
    }
  }
  const copyTab = async tab => {
    await copyText(tab.tsv)
    setCopiedTab(tab.name)
    toast.success(`${tab.name} copied. Paste it into the matching Google Sheets tab.`)
  }

  return <main className="audit-report">
    <header className="audit-report-header">
      <div><Link className="audit-back" to={`/ad-library/audits/${id}?stage=insights`}><ArrowLeft size={14}/> Back to audit</Link><p className="audit-kicker">Final content audit report</p><h1>{audit.company_name}</h1><p>{audit.name} · {relationshipLabel(audit.company_type)} · completed {formatDate(audit.completed_at)}</p></div>
      <div className="audit-header-actions"><a className="audit-button primary" href={api.getAdLibraryAuditReportXlsxUrl(id)}><Download size={14}/> Excel workbook</a><button className="audit-button secondary" onClick={openSheets}><FileSpreadsheet size={14}/> Google Sheets</button></div>
    </header>

    <nav className="audit-report-nav" aria-label="Report sections">
      <a href="#overview">Overview</a><a href="#audience">Audience</a><a href="#creative-audit">Creative audit</a><a href="#recommendations">Recommendations</a><a href="#evidence">Evidence</a>
    </nav>
    {audit.insights_stale && <div className="audit-warning" role="alert">This report is stale because the confirmed creative classifications changed. Regenerate insights before sharing or exporting it.</div>}

    <section className="audit-report-overview" id="overview">
      <div className="audit-report-summary"><span>Executive summary</span><h2>{insights.executive_summary || 'The final synthesis has not been generated yet.'}</h2><p>{insights.observations_disclaimer || 'This report describes observable content patterns, not campaign performance.'}</p></div>
      <dl className="audit-report-metrics"><div><dt>Creatives audited</dt><dd>{summary.complete || creatives.length}</dd></div><div><dt>Audience personas</dt><dd>{audit.personas?.length || 0}</dd></div><div><dt>Products observed</dt><dd>{products.length}</dd></div><div><dt>Angles observed</dt><dd>{angles.length}</dd></div></dl>
    </section>

    <section className="audit-report-section" id="audience">
      <ReportHeading eyebrow="01 · Foundation" title="Brand context and audience" copy="The public evidence and approved personas used to classify every creative."/>
      <div className="audit-report-context"><article><span>Brand summary</span><p>{audit.website_context?.summary || 'No brand summary was recorded.'}</p></article>{contextSections.map(section => <article key={section}><span>{humanize(section)}</span><ItemList items={audit.website_context?.[section]}/></article>)}</div>
      <div className="audit-report-personas">{safeArray(audit.personas).map(persona => <article key={persona.id}><header><i>{persona.name?.slice(0, 1) || '?'}</i><div><h3>{persona.name}</h3><p>{persona.description}</p></div><strong>{Math.round((persona.confidence || 0) * 100)}%</strong></header><div><PersonaField label="Problems" items={persona.problems}/><PersonaField label="Desired outcomes" items={persona.desired_outcomes}/><PersonaField label="Objections" items={persona.objections}/><PersonaField label="Purchase triggers" items={persona.purchase_triggers}/></div></article>)}</div>
    </section>

    <section className="audit-report-section" id="creative-audit">
      <ReportHeading eyebrow="02 · Creative system" title="Coverage and creative patterns" copy={`${creatives.length} completed creative classifications across ${products.length} products and ${angles.length} angles.`}/>
      <div className="audit-report-analysis"><div><h3>Persona coverage</h3>{safeArray(insights.persona_creative_coverage).map(row => <DataBar key={row.persona} label={row.persona} value={row.creative_count} max={Math.max(1, ...safeArray(insights.persona_creative_coverage).map(item => item.creative_count || 0))}/>)}</div><div><h3>Angle distribution</h3>{safeArray(insights.angle_distribution).map(row => <DataBar key={row.label} label={row.label} value={row.count} max={Math.max(1, ...safeArray(insights.angle_distribution).map(item => item.count || 0))}/>)}</div><div><h3>Format distribution</h3>{formats.map(row => <DataBar key={row.label} label={row.label} value={row.count} max={Math.max(1, ...formats.map(item => item.count || 0))}/>)}</div></div>
      <div className="audit-report-table-wrap"><table className="audit-report-table"><thead><tr><th>Creative</th><th>Audience</th><th>Product</th><th>Angle</th><th>Format</th><th>Type</th><th>Video script</th><th>Confidence</th></tr></thead><tbody>{creatives.map(creative => { const primary = creative.personas?.find(persona => persona.pivot?.role === 'primary'); return <tr key={creative.id}><td><a href={`https://www.facebook.com/ads/library/?id=${creative.library_ad_ids?.[0] || ''}`} target="_blank" rel="noreferrer">{creative.source_snapshot?.title || creative.source_snapshot?.link_title || `Ad ${creative.library_ad_ids?.[0] || creative.id}`} <ExternalLink size={11}/></a><small>{creative.source_snapshot?.media_type || 'Unknown media'} · {creative.library_ad_ids?.length || 1} grouped ads</small></td><td>{primary?.name || 'Unassigned'}</td><td>{creative.product || '—'}</td><td>{creative.angle || '—'}</td><td>{creative.format || '—'}</td><td>{creative.type || '—'}</td><td>{creative.source_snapshot?.media_type === 'video' ? creative.video_script ? <details className="audit-report-script"><summary>View script</summary><pre>{creative.video_script}</pre></details> : <small>{videoScriptLabel(creative.video_script_status)}</small> : '—'}</td><td>{Math.round((creative.confidence || 0) * 100)}%</td></tr> })}</tbody></table></div>
    </section>

    <section className="audit-report-section" id="recommendations">
      <ReportHeading eyebrow="03 · Action plan" title="Gaps, opportunities, and tests" copy="Prioritized observations drawn from the confirmed creative grid."/>
      <div className="audit-report-recommendations">{recommendationSections.map(([label, key]) => <article key={key}><span>{label}</span><ItemList items={insights[key]} numbered/></article>)}</div>
    </section>

    <section className="audit-report-section audit-report-evidence" id="evidence">
      <ReportHeading eyebrow="04 · Traceability" title="Sources and report scope" copy="Reference links used for brand context, plus the model and audit configuration."/>
      <div><dl><div><dt>Model</dt><dd>{audit.model}</dd></div><div><dt>Media depth</dt><dd>{humanize(audit.media_depth)}</dd></div><div><dt>Completed</dt><dd>{formatDate(audit.completed_at)}</dd></div><div><dt>Estimated AI cost</dt><dd>{audit.estimated_cost ? `$${audit.estimated_cost}` : 'Not recorded'}</dd></div></dl><div className="audit-report-sources">{safeArray(audit.context_sources).map((source, index) => <a key={source.url || index} href={source.url} target="_blank" rel="noreferrer"><span>{source.title || source.url}</span><ExternalLink size={13}/></a>)}</div></div>
    </section>

    <footer className="audit-report-footer"><span>{insights.observations_disclaimer || 'Content observations only.'}</span><strong>{audit.name}</strong></footer>

    {sheetsOpen && <div className="audit-dialog-backdrop" role="presentation"><section className="audit-sheets-dialog" role="dialog" aria-modal="true" aria-labelledby="sheets-title"><header><div><p className="audit-kicker">Google Sheets export</p><h2 id="sheets-title">Build the report across tabs</h2><p>Import the formatted workbook, or create a blank Sheet and paste each named tab below.</p></div><button aria-label="Close Google Sheets export" onClick={() => setSheetsOpen(false)}><X size={18}/></button></header><div className="audit-sheets-actions"><a className="audit-button primary" href={api.getAdLibraryAuditReportXlsxUrl(id)}><Download size={14}/> Download import-ready workbook</a><button className="audit-button secondary" onClick={() => window.open('https://sheets.new', '_blank', 'noopener,noreferrer')}><ExternalLink size={14}/> Open Google Sheets</button></div>{sheetsLoading ? <div className="audit-loading compact"><LoaderCircle className="spin"/> Preparing tabs…</div> : <div className="audit-sheets-tabs">{safeArray(sheets?.tabs).map(tab => <button key={tab.name} onClick={() => copyTab(tab)}><span><strong>{tab.name}</strong><small>{tab.rows?.length || 0} data rows</small></span>{copiedTab === tab.name ? <Check size={15}/> : <Clipboard size={15}/>}</button>)}</div>}<footer><span>Create tabs with these names, then paste into cell A1.</span><button className="audit-button secondary" onClick={() => setSheetsOpen(false)}>Done</button></footer></section></div>}
  </main>
}

function ReportHeading({ eyebrow, title, copy }) { return <header className="audit-report-section-heading"><span>{eyebrow}</span><h2>{title}</h2><p>{copy}</p></header> }
function ItemList({ items, numbered = false }) { const values = safeArray(items); if (!values.length) return <p className="audit-report-empty">No observations recorded.</p>; const Tag = numbered ? 'ol' : 'ul'; return <Tag>{values.map((item, index) => <li key={index}>{item}</li>)}</Tag> }
function PersonaField({ label, items }) { const values = safeArray(items); return <section><span>{label}</span><p>{values.length ? values.join(' · ') : 'Not recorded'}</p></section> }
function DataBar({ label, value = 0, max = 1 }) { return <div className="audit-report-bar"><span>{label}</span><i><b style={{ width: `${Math.max(4, (value / max) * 100)}%` }}/></i><strong>{value}</strong></div> }
function safeArray(value) { return Array.isArray(value) ? value : [] }
function distribution(items, key) { return Object.entries(items.reduce((counts, item) => { const label = item[key]; if (label) counts[label] = (counts[label] || 0) + 1; return counts }, {})).map(([label, count]) => ({ label, count })).sort((a, b) => b.count - a.count || a.label.localeCompare(b.label)) }
function humanize(value = '') { return String(value).replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()) }
function relationshipLabel(value = 'other') { return humanize(value) }
function formatDate(value) { return value ? new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : 'Not recorded' }
function videoScriptLabel(status) { return ({ no_speech: 'No speech detected', not_requested: 'Script not captured — Full media required', unavailable: 'Video unavailable', failed: 'Transcription failed' })[status] || 'Not captured' }
