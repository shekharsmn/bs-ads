import { useCallback, useEffect, useState } from 'react'
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Check, ChevronDown, ChevronUp, Download, Eye, FileJson, LoaderCircle, Plus, RefreshCw, Sparkles, Trash2 } from 'lucide-react'
import { api } from '../api/client'
import AdCreativeModal from '../components/AdCreativeModal'
import { useAuth } from '../context/AuthContext'

const activeStatuses = new Set(['collecting_context', 'generating_personas', 'analyzing_creatives', 'generating_insights'])
const arrayFields = ['facts', 'products', 'claims', 'audience_signals', 'positioning', 'value_propositions']
const personaArrayFields = ['problems', 'desired_outcomes', 'motivations', 'purchase_triggers', 'objections', 'products', 'evidence']

export default function AdLibraryAuditPage() {
  const { id } = useParams()
  const { features } = useAuth()
  if (!features?.ad_library_audits) return <Navigate to="/ad-library" replace />
  return id ? <AuditWorkspace id={id} /> : <AuditHome />
}

function AuditHome() {
  const { permissions } = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const canManage = Boolean(permissions?.manage_ad_library)
  const [runs, setRuns] = useState([])
  const [audits, setAudits] = useState([])
  const [runId, setRunId] = useState(searchParams.get('run') || '')
  const [creatives, setCreatives] = useState([])
  const [selected, setSelected] = useState([])
  const [mediaDepth, setMediaDepth] = useState('visual')
  const [models, setModels] = useState([])
  const [modelKey, setModelKey] = useState('')
  const [name, setName] = useState('')
  const [websiteUrl, setWebsiteUrl] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    Promise.all([api.getAdLibraryRuns(), api.getAdLibraryAudits()]).then(([runResponse, auditResponse]) => {
      const usable = (runResponse.data.data || []).filter(run => ['complete', 'partial'].includes(run.status) && run.result_count > 0)
      setRuns(usable); setAudits(auditResponse.data.data || [])
      if (!usable.some(run => String(run.id) === String(runId))) setRunId(String(usable[0]?.id || ''))
    }).catch(() => setError('Unable to load audit setup data.'))
  }, [runId])
  useEffect(() => {
    api.getAdLibraryAuditModels(mediaDepth).then(({ data }) => { setModels(data.models || []); setModelKey(current => data.models?.some(model => model.key === current) ? current : (data.models?.[0]?.key || '')) }).catch(() => setModels([]))
  }, [mediaDepth])
  useEffect(() => {
    if (!runId) { setCreatives([]); setSelected([]); return }
    api.getAdLibraryResults(runId, { per_page: 100 }).then(({ data }) => { const rows = (data.data || []).slice(0, 50); setCreatives(rows); setSelected(rows.map(row => row.id)); const run = runs.find(item => String(item.id) === String(runId)); setName(`${run?.search?.company_name || 'Company'} content audit`); setWebsiteUrl(run?.search?.website_url || '') }).catch(() => setError('Unable to load grouped creatives.'))
  }, [runId, runs])

  const create = async event => {
    event.preventDefault(); setBusy(true); setError('')
    try {
      const response = await api.createAdLibraryAudit({ run_id: Number(runId), name, website_url: websiteUrl || null, media_depth: mediaDepth, model_key: modelKey, result_ids: selected })
      navigate(`/ad-library/audits/${response.data.id}`)
    } catch (requestError) { setError(apiError(requestError, 'Unable to create the audit.')) }
    finally { setBusy(false) }
  }

  const remove = async audit => {
    if (!window.confirm(`Delete “${audit.name}”?`)) return
    await api.deleteAdLibraryAudit(audit.id); setAudits(items => items.filter(item => item.id !== audit.id))
  }

  return <main className="audit-shell">
    <header className="audit-page-header"><div><p className="audit-kicker">Ad Library intelligence</p><h1>Content audits</h1><p>Turn grouped creatives into reviewed personas, classifications, and content opportunities.</p></div><Link className="audit-button secondary" to="/ad-library"><ArrowLeft size={15}/> Ad Library</Link></header>
    {error && <div className="audit-error">{error}</div>}
    {canManage && <form className="audit-panel audit-setup" onSubmit={create}>
      <div className="audit-section-heading"><div><span>New audit</span><small>One model is locked for all four stages.</small></div><span className="audit-count">{selected.length}/50 creatives</span></div>
      <div className="audit-form-grid">
        <Field label="Completed source run"><select required value={runId} onChange={event => setRunId(event.target.value)}><option value="">Select a run</option>{runs.map(run => <option key={run.id} value={run.id}>#{run.id} · {run.search?.company_name || 'Unknown company'} · {relationshipLabel(run.search?.company_type)} · {run.search?.name || 'Deleted search'} · {run.result_count} ads</option>)}</select></Field>
        <Field label="Audit name"><input required value={name} onChange={event => setName(event.target.value)} /></Field>
        <Field label="Website"><input type="url" placeholder="https://brand.com" value={websiteUrl} onChange={event => setWebsiteUrl(event.target.value)} /></Field>
        <Field label="Media depth"><select value={mediaDepth} onChange={event => setMediaDepth(event.target.value)}><option value="text_only">Text only</option><option value="visual">Visual</option><option value="full_media">Full media + audio</option></select></Field>
        <Field label="AI model"><select required value={modelKey} onChange={event => setModelKey(event.target.value)}><option value="">No configured model</option>{models.map(model => <option key={model.key} value={model.key}>{model.label} · {model.capabilities.join(', ')}</option>)}</select></Field>
      </div>
      {creatives.length > 0 && <div className="audit-creative-picker"><div className="audit-picker-toolbar"><strong>Select grouped creatives</strong><button type="button" onClick={() => setSelected(selected.length === creatives.length ? [] : creatives.map(row => row.id))}>{selected.length === creatives.length ? 'Clear all' : 'Select all'}</button></div>{creatives.map(row => <label key={row.id}><input type="checkbox" checked={selected.includes(row.id)} onChange={() => setSelected(values => values.includes(row.id) ? values.filter(id => id !== row.id) : values.length < 50 ? [...values, row.id] : values)} /><span className={`audit-media-tag ${row.media_type}`}>{row.media_type}</span><span>{short(row.title || row.link_title || row.body || `Ad ${row.library_ad_id}`, 80)}</span><small>{row.variant_count} ad{row.variant_count === 1 ? '' : 's'}</small></label>)}</div>}
      <button className="audit-button primary" disabled={busy || !modelKey || !selected.length}>{busy ? <LoaderCircle className="spin" size={15}/> : <Sparkles size={15}/>} Create and collect context</button>
    </form>}
    <section className="audit-panel"><div className="audit-section-heading"><span>Existing audits</span><span className="audit-count">{audits.length}</span></div>{audits.length === 0 ? <p className="audit-empty">No company audits yet.</p> : <div className="audit-list">{audits.map(audit => <div key={audit.id}><Link to={`/ad-library/audits/${audit.id}`}><strong>{audit.name}</strong><span>{labelStatus(audit.status)}</span><small>{audit.company_name} · {relationshipLabel(audit.company_type)} · {audit.creatives_count} creatives · {audit.personas_count} personas · {new Date(audit.created_at).toLocaleDateString()}</small></Link>{canManage && <button title="Delete audit" onClick={() => remove(audit)}><Trash2 size={14}/></button>}</div>)}</div>}</section>
  </main>
}

function AuditWorkspace({ id }) {
  const { permissions } = useAuth()
  const canManage = Boolean(permissions?.manage_ad_library)
  const [audit, setAudit] = useState(null)
  const [creatives, setCreatives] = useState([])
  const [context, setContext] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [filter, setFilter] = useState({ q: '', status: '', media_type: '', persona_id: '', review_state: '', errors: '', product: '', angle: '', format: '', type: '' })
  const [creativeModal, setCreativeModal] = useState(null)

  const load = useCallback(async () => {
    const [auditResponse, creativeResponse] = await Promise.all([api.getAdLibraryAudit(id), api.getAdLibraryAuditCreatives(id, { ...filter, per_page: 100 })])
    setAudit(auditResponse.data); setContext(auditResponse.data.website_context || {}); setCreatives(creativeResponse.data.data || [])
  }, [id, filter])
  useEffect(() => { load().catch(requestError => setError(apiError(requestError, 'Unable to load this audit.'))) }, [load])
  useEffect(() => {
    if (!audit || !activeStatuses.has(audit.status)) return undefined
    const timer = window.setInterval(() => load().catch(() => {}), 3000)
    return () => window.clearInterval(timer)
  }, [audit, load])

  const act = async (action, fallback) => {
    setBusy(true); setError('')
    try { await action(); await load() } catch (requestError) { setError(apiError(requestError, fallback)) } finally { setBusy(false) }
  }
  if (!audit) return <div className="audit-loading"><LoaderCircle className="spin"/> Loading audit…</div>
  const stage = stageNumber(audit.status, audit.failed_stage)

  return <main className="audit-shell wide">
    <header className="audit-page-header"><div><Link className="audit-back" to="/ad-library/audits"><ArrowLeft size={14}/> Audits</Link><h1>{audit.name}</h1><p>{audit.company_name} · {relationshipLabel(audit.company_type)} · {audit.model} · {audit.media_depth.replaceAll('_', ' ')}</p></div><div className="audit-header-actions"><Status status={audit.status}/><a className="audit-button secondary" href={api.getAdLibraryAuditCsvUrl(id)}><Download size={14}/> CSV</a><a className="audit-button secondary" href={api.getAdLibraryAuditJsonUrl(id)}><FileJson size={14}/> JSON</a></div></header>
    <div className="audit-steps">{['Context', 'Personas', 'Creative audit', 'Insights'].map((name, index) => <div key={name} className={index + 1 <= stage ? 'active' : ''}><span>{index + 1 < stage ? <Check size={14}/> : index + 1}</span><strong>{name}</strong></div>)}</div>
    {error && <div className="audit-error">{error}</div>}
    {audit.error_message && <div className="audit-warning">{audit.error_message}</div>}
    {activeStatuses.has(audit.status) && <div className="audit-progress"><LoaderCircle className="spin" size={16}/><span>{labelStatus(audit.status)}</span>{audit.status === 'analyzing_creatives' && <strong>{audit.progress_completed + audit.progress_failed}/{audit.progress_total}</strong>}</div>}

    <ContextStep audit={audit} context={context} setContext={setContext} canManage={canManage} busy={busy} act={act} />
    {stage >= 2 && <PersonasStep audit={audit} canManage={canManage} busy={busy} act={act} />}
    {stage >= 3 && <CreativesStep audit={audit} creatives={creatives} canManage={canManage} busy={busy} filter={filter} setFilter={setFilter} act={act} openCreative={setCreativeModal} />}
    {(stage >= 4 || audit.insights) && <InsightsStep audit={audit} canManage={canManage} busy={busy} act={act} />}
    <AdCreativeModal open={Boolean(creativeModal)} creative={creativeModal} onClose={() => setCreativeModal(null)} />
  </main>
}

function ContextStep({ audit, context, setContext, canManage, busy, act }) {
  const updateArray = (key, value) => setContext(current => ({ ...current, [key]: value.split('\n').map(item => item.trim()).filter(Boolean) }))
  return <section className="audit-panel"><div className="audit-section-heading"><div><span>1. Approved brand context</span><small>Review and correct extracted public facts before persona generation.</small></div>{audit.context_sources?.length > 0 && <span className="audit-count">{audit.context_sources.length} sources</span>}</div>
    <Field label="Brand summary"><textarea rows="4" value={context.summary || ''} disabled={!canManage} onChange={event => setContext(current => ({ ...current, summary: event.target.value }))}/></Field>
    <div className="audit-context-grid">{arrayFields.map(key => <Field key={key} label={key.replaceAll('_', ' ')}><textarea rows="4" value={(context[key] || []).join('\n')} disabled={!canManage} onChange={event => updateArray(key, event.target.value)} /></Field>)}</div>
    {audit.context_sources?.length > 0 && <details className="audit-sources"><summary>Source evidence</summary>{audit.context_sources.map(source => <a key={source.url} href={source.url} target="_blank" rel="noreferrer">{source.title || source.url}</a>)}</details>}
    {canManage && (['context_review', 'persona_review'].includes(audit.status) || (audit.status === 'failed' && audit.failed_stage === 'context')) && <div className="audit-actions"><button className="audit-button secondary" disabled={busy} onClick={() => act(() => api.collectAdLibraryAuditContext(audit.id), 'Unable to recollect context.')}><RefreshCw size={14}/> Recollect</button><button className="audit-button primary" disabled={busy} onClick={() => act(async () => { await api.updateAdLibraryAuditContext(audit.id, { website_context: context }); await api.generateAdLibraryAuditPersonas(audit.id) }, 'Unable to generate personas.')}><Sparkles size={14}/> Save context & suggest personas</button></div>}
  </section>
}

function PersonasStep({ audit, canManage, busy, act }) {
  const [drafts, setDrafts] = useState({})
  const personas = audit.personas || []
  const draft = persona => drafts[persona.id] || persona
  const update = (persona, key, value) => setDrafts(current => ({ ...current, [persona.id]: { ...draft(persona), [key]: value } }))
  const allowChange = () => !['analyzing_creatives', 'creative_review', 'generating_insights', 'complete'].includes(audit.status) || window.confirm('Changing personas will clear creative classifications and insights. Continue?')
  const save = persona => { if (!allowChange()) return; const value = draft(persona); const payload = { ...value }; personaArrayFields.forEach(key => { payload[key] = Array.isArray(value[key]) ? value[key] : String(value[key] || '').split('\n').filter(Boolean) }); return act(() => api.updateAdLibraryAuditPersona(audit.id, persona.id, payload), 'Unable to save persona.') }
  const move = (index, direction) => { const ordered = [...personas]; const target = index + direction; if (target < 0 || target >= ordered.length || !allowChange()) return; [ordered[index], ordered[target]] = [ordered[target], ordered[index]]; act(() => api.reorderAdLibraryAuditPersonas(audit.id, ordered.map(item => item.id)), 'Unable to reorder personas.') }
  return <section className="audit-panel"><div className="audit-section-heading"><div><span>2. Confirm personas</span><small>Edit the suggested segments. Reconfirming after analysis clears classifications.</small></div><span className="audit-count">{personas.length}/10</span></div>
    <div className="audit-personas">{personas.map((persona, index) => { const value = draft(persona); return <article key={persona.id}><div className="audit-persona-head"><input disabled={!canManage} value={value.name || ''} onChange={event => update(persona, 'name', event.target.value)}/><span>{Math.round((persona.confidence || 0) * 100)}% confidence</span>{canManage && <><button onClick={() => move(index, -1)}><ChevronUp size={14}/></button><button onClick={() => move(index, 1)}><ChevronDown size={14}/></button><button onClick={() => { if (allowChange()) act(() => api.deleteAdLibraryAuditPersona(audit.id, persona.id), 'Unable to delete persona.') }}><Trash2 size={14}/></button></>}</div><textarea disabled={!canManage} rows="2" value={value.description || ''} onChange={event => update(persona, 'description', event.target.value)}/><input disabled={!canManage} placeholder="Demographics or life stage" value={value.demographics || ''} onChange={event => update(persona, 'demographics', event.target.value)}/><div className="audit-persona-fields">{personaArrayFields.map(key => <Field key={key} label={key.replaceAll('_', ' ')}><textarea disabled={!canManage} rows="3" value={Array.isArray(value[key]) ? value[key].join('\n') : value[key] || ''} onChange={event => update(persona, key, event.target.value)}/></Field>)}</div>{canManage && <button className="audit-text-button" onClick={() => save(persona)}>Save persona</button>}</article> })}</div>
    {canManage && <div className="audit-actions">{audit.status === 'failed' && audit.failed_stage === 'personas' && <button className="audit-button secondary" disabled={busy} onClick={() => act(() => api.generateAdLibraryAuditPersonas(audit.id), 'Unable to retry persona suggestions.')}><RefreshCw size={14}/> Retry suggestions</button>}<button className="audit-button secondary" disabled={busy || personas.length >= 10} onClick={() => { if (allowChange()) act(() => api.createAdLibraryAuditPersona(audit.id, { name: 'New persona', description: 'Describe this audience segment.' }), 'Unable to add a persona.') }}><Plus size={14}/> Add persona</button><button className="audit-button primary" disabled={busy || personas.length < 1 || audit.status === 'failed'} onClick={() => act(async () => { await api.confirmAdLibraryAuditPersonas(audit.id); await api.analyzeAdLibraryAudit(audit.id) }, 'Unable to start creative analysis.')}><Sparkles size={14}/> Confirm & analyze creatives</button></div>}
  </section>
}

function CreativesStep({ audit, creatives, canManage, busy, filter, setFilter, act, openCreative }) {
  const personas = audit.personas || []
  const save = (creative, form) => act(() => api.updateAdLibraryAuditCreative(audit.id, creative.id, form), 'Unable to save this row.')
  return <section className="audit-panel"><div className="audit-section-heading"><div><span>3. Review creative audit</span><small>One row per grouped creative. Open the source to inspect every available field.</small></div><span className="audit-count">{audit.progress_completed} complete · {audit.progress_failed} failed</span></div>
    <div className="audit-filters"><input placeholder="Search all classifications…" value={filter.q} onChange={event => setFilter(current => ({ ...current, q: event.target.value }))}/><select value={filter.persona_id} onChange={event => setFilter(current => ({ ...current, persona_id: event.target.value }))}><option value="">All personas</option>{personas.map(persona => <option key={persona.id} value={persona.id}>{persona.name}</option>)}</select><select value={filter.media_type} onChange={event => setFilter(current => ({ ...current, media_type: event.target.value }))}><option value="">All media</option><option value="static">Static</option><option value="video">Video</option></select><select value={filter.status} onChange={event => setFilter(current => ({ ...current, status: event.target.value }))}><option value="">All processing</option><option value="complete">Complete</option><option value="failed">Failed</option></select><input placeholder="Product" value={filter.product} onChange={event => setFilter(current => ({ ...current, product: event.target.value }))}/><input placeholder="Angle" value={filter.angle} onChange={event => setFilter(current => ({ ...current, angle: event.target.value }))}/><input placeholder="Format" value={filter.format} onChange={event => setFilter(current => ({ ...current, format: event.target.value }))}/><input placeholder="Type" value={filter.type} onChange={event => setFilter(current => ({ ...current, type: event.target.value }))}/><select value={filter.review_state} onChange={event => setFilter(current => ({ ...current, review_state: event.target.value }))}><option value="">All review states</option><option value="pending">Pending review</option><option value="reviewed">Reviewed</option></select><label className="audit-error-filter"><input type="checkbox" checked={filter.errors === '1'} onChange={event => setFilter(current => ({ ...current, errors: event.target.checked ? '1' : '' }))}/> Errors only</label></div>
    <div className="audit-table-wrap"><table className="audit-table"><thead><tr><th>Source</th><th>Persona</th><th>Angle</th><th>Product</th><th>What it solves</th><th>Format</th><th>Type</th><th>Remarks</th><th>Review</th></tr></thead><tbody>{creatives.map(creative => <CreativeRow key={creative.id} creative={creative} personas={personas} canManage={canManage} save={save} openCreative={openCreative}/>)}</tbody></table></div>
    {audit.progress_failed > 0 && canManage && <button className="audit-button secondary" disabled={busy} onClick={() => act(() => api.retryFailedAdLibraryAuditCreatives(audit.id), 'Unable to retry failed creatives.')}><RefreshCw size={14}/> Retry failed rows</button>}
    {canManage && audit.status === 'creative_review' && audit.progress_failed === 0 && <div className="audit-actions"><button className="audit-button primary" disabled={busy} onClick={() => act(async () => { await api.confirmAdLibraryAuditGrid(audit.id); await api.generateAdLibraryAuditInsights(audit.id) }, 'Unable to generate insights.')}><Sparkles size={14}/> Confirm grid & generate insights</button></div>}
  </section>
}

function CreativeRow({ creative, personas, canManage, save, openCreative }) {
  const primary = creative.personas?.find(persona => persona.pivot?.role === 'primary')?.id || ''
  const secondary = creative.personas?.filter(persona => persona.pivot?.role === 'secondary').map(persona => persona.id) || []
  const [form, setForm] = useState({ angle: creative.angle || '', product: creative.product || '', what_it_solves: creative.what_it_solves || '', format: creative.format || '', type: creative.type || '', remarks: creative.remarks || '', primary_persona_id: primary, secondary_persona_ids: secondary, reviewed: creative.review_status === 'reviewed' })
  const change = (key, value) => setForm(current => ({ ...current, [key]: value }))
  return <tr className={creative.processing_status === 'failed' ? 'failed' : ''}><td><button className="audit-source-button" onClick={() => openCreative(creative.source_snapshot)}><Eye size={14}/><span className={`audit-media-tag ${creative.source_snapshot?.media_type}`}>{creative.source_snapshot?.media_type}</span><small>{creative.source_snapshot?.is_active ? 'Active' : 'Inactive'} · {runningDuration(creative.source_snapshot)} · {creative.library_ad_ids?.length || 1} ads</small></button>{creative.error_message && <em>{creative.error_message}</em>}</td><td><select disabled={!canManage || creative.processing_status !== 'complete'} value={form.primary_persona_id} onChange={event => change('primary_persona_id', Number(event.target.value))}><option value="">Primary</option>{personas.map(persona => <option key={persona.id} value={persona.id}>{persona.name}</option>)}</select><select multiple disabled={!canManage || creative.processing_status !== 'complete'} value={form.secondary_persona_ids.map(String)} onChange={event => change('secondary_persona_ids', Array.from(event.target.selectedOptions).map(option => Number(option.value)))}>{personas.filter(persona => persona.id !== Number(form.primary_persona_id)).map(persona => <option key={persona.id} value={persona.id}>{persona.name}</option>)}</select></td>{['angle', 'product', 'what_it_solves', 'format', 'type', 'remarks'].map(key => <td key={key}><textarea disabled={!canManage || creative.processing_status !== 'complete'} value={form[key]} onChange={event => change(key, event.target.value)}/></td>)}<td>{creative.processing_status === 'complete' ? <><label><input type="checkbox" disabled={!canManage} checked={form.reviewed} onChange={event => change('reviewed', event.target.checked)}/> Reviewed</label>{canManage && <button className="audit-text-button" disabled={!form.primary_persona_id} onClick={() => save(creative, form)}>Save</button>}<small>{Math.round((creative.confidence || 0) * 100)}% confidence</small></> : <Status status={creative.processing_status}/>}</td></tr>
}

function InsightsStep({ audit, canManage, busy, act }) {
  const insights = audit.insights
  return <section className="audit-panel"><div className="audit-section-heading"><div><span>4. Insights</span><small>Content observations only; Ad Library does not establish performance.</small></div>{audit.insights_stale && <span className="audit-stale">Stale after edits</span>}</div>
    {!insights ? <p className="audit-empty">Insights are being prepared or have not been generated.</p> : <div className="audit-insights"><article className="audit-insight-lead"><h3>Executive summary</h3><p>{insights.executive_summary}</p><small>{insights.observations_disclaimer}</small></article><Coverage title="Persona × creative" rows={insights.persona_creative_coverage} render={row => `${row.persona}: ${row.creative_count}`}/><Coverage title="Persona × product" rows={insights.persona_product_coverage} render={row => `${row.persona}: ${(row.products || []).join(', ')}`}/><Distribution title="Angles" rows={insights.angle_distribution}/><Distribution title="Formats" rows={insights.format_distribution}/><Distribution title="Types" rows={insights.type_distribution}/><List title="Repetition and concentration" items={insights.repetition_and_concentration}/><List title="Long-running patterns" items={insights.long_running_patterns}/><List title="Coverage gaps" items={insights.gaps}/><List title="Content opportunities" items={insights.content_opportunities}/><List title="Test recommendations" items={insights.test_recommendations}/></div>}
    {canManage && audit.grid_confirmed_at && <div className="audit-actions"><button className="audit-button secondary" disabled={busy || audit.status === 'generating_insights'} onClick={() => act(() => api.generateAdLibraryAuditInsights(audit.id), 'Unable to regenerate insights.')}><RefreshCw size={14}/> Regenerate insights</button></div>}
  </section>
}

function Coverage({ title, rows = [], render }) { return <article><h3>{title}</h3>{rows.map((row, index) => <p key={index}>{render(row)}</p>)}</article> }
function Distribution({ title, rows = [] }) { const max = Math.max(1, ...rows.map(row => row.count)); return <article><h3>{title}</h3>{rows.map(row => <div className="audit-bar" key={row.label}><span>{row.label}</span><i style={{ width: `${(row.count / max) * 100}%` }}/><strong>{row.count}</strong></div>)}</article> }
function List({ title, items = [] }) { return <article><h3>{title}</h3><ul>{items.map((item, index) => <li key={index}>{item}</li>)}</ul></article> }
function Field({ label, children }) { return <label className="audit-field"><span>{label}</span>{children}</label> }
function Status({ status }) { return <span className={`audit-status ${status}`}>{labelStatus(status)}</span> }
function labelStatus(status = '') { return status.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()) }
function relationshipLabel(value = 'other') { return value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()) }
function short(value, limit) { return value.length > limit ? `${value.slice(0, limit)}…` : value }
function runningDuration(source = {}) { if (!source.first_shown_at) return 'duration unknown'; const start = new Date(source.first_shown_at); const end = source.is_active || !source.last_shown_at ? new Date() : new Date(source.last_shown_at); const days = Math.max(1, Math.ceil((end - start) / 86400000)); return `${days} day${days === 1 ? '' : 's'}` }
function stageNumber(status, failedStage = '') { if (status === 'failed') return { context: 1, personas: 2, creative: 3, insights: 4 }[failedStage] || 1; if (['draft', 'collecting_context', 'context_review'].includes(status)) return 1; if (['generating_personas', 'persona_review'].includes(status)) return 2; if (['analyzing_creatives', 'creative_review'].includes(status)) return 3; if (['generating_insights', 'complete'].includes(status)) return 4; return 1 }
function apiError(error, fallback) { const errors = error.response?.data?.errors; return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || fallback }
