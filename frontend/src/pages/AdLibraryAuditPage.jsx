import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Check, ChevronDown, ChevronLeft, ChevronRight, CirclePause, Download, Eye, FileJson, FileSpreadsheet, Filter, LoaderCircle, Pencil, Play, Plus, RefreshCw, Search, Sparkles, Trash2, X } from 'lucide-react'
import { api } from '../api/client'
import AdCreativeModal from '../components/AdCreativeModal'
import { useAuth } from '../context/AuthContext'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { useToast } from '../context/ToastContext'
import { copyText } from '../utils/clipboard'

const activeStatuses = new Set(['collecting_context', 'generating_personas', 'analyzing_creatives', 'generating_insights'])
const arrayFields = ['facts', 'products', 'claims', 'audience_signals', 'positioning', 'value_propositions']
const personaArrayFields = ['problems', 'desired_outcomes', 'motivations', 'purchase_triggers', 'objections', 'products', 'evidence']
const stages = [
  { key: 'context', label: 'Context' },
  { key: 'personas', label: 'Personas' },
  { key: 'creatives', label: 'Creative audit' },
  { key: 'insights', label: 'Insights' },
]

export default function AdLibraryAuditPage() {
  const { id } = useParams()
  const { features } = useAuth()
  if (!features?.ad_library_audits) return <Navigate to="/ad-library" replace />
  return id ? <AuditWorkspace id={id} /> : <AuditHome />
}

function AuditHome() {
  const { permissions } = useAuth()
  const toast = useToast()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const canManage = Boolean(permissions?.manage_ad_library)
  const [runs, setRuns] = useState([])
  const [audits, setAudits] = useState([])
  const [pagination, setPagination] = useState({ current: 1, last: 1, total: 0 })
  const [filters, setFilters] = useState({ q: '', status: '', company_type: '', page: 1 })
  const [loading, setLoading] = useState(true)
  const [listLoading, setListLoading] = useState(true)
  const [error, setError] = useState('')
  const [dialogOpen, setDialogOpen] = useState(Boolean(searchParams.get('run')))
  const [deleteAudit, setDeleteAudit] = useState(null)

  useEffect(() => {
    let current = true
    api.getAdLibraryRuns({ per_page: 100 }).then(({ data }) => {
      if (!current) return
      setRuns((data.data || []).filter(run => ['complete', 'partial'].includes(run.status) && run.result_count > 0))
    }).catch(() => current && setError('Unable to load completed Ad Library runs.')).finally(() => current && setLoading(false))
    return () => { current = false }
  }, [])

  useEffect(() => {
    let current = true
    const timer = window.setTimeout(() => {
      setListLoading(true)
      api.getAdLibraryAudits(filters).then(({ data }) => {
        if (!current) return
        setAudits(data.data || [])
        setPagination({ current: data.current_page || 1, last: data.last_page || 1, total: data.total ?? (data.data || []).length })
      }).catch(() => current && setError('Unable to load content audits.')).finally(() => current && setListLoading(false))
    }, filters.q ? 300 : 0)
    return () => { current = false; window.clearTimeout(timer) }
  }, [filters])

  const remove = async audit => {
    try {
      await api.deleteAdLibraryAudit(audit.id)
      setDeleteAudit(null)
      setFilters(current => ({ ...current }))
      toast.success('Audit deleted.')
    } catch (requestError) { setError(apiError(requestError, 'Unable to delete the audit.')) }
  }

  return <main className="audit-shell audit-home">
    <header className="audit-page-header">
      <div><p className="audit-kicker">Ad Library intelligence</p><h1>Content audits</h1><p>Review brand context, audience personas, creative classifications, and content opportunities.</p></div>
      <div className="audit-header-actions"><Link className="audit-button secondary" to="/ad-library"><ArrowLeft size={15}/> Ad Library</Link>{canManage && <button className="audit-button primary" onClick={() => setDialogOpen(true)}><Plus size={15}/> New content audit</button>}</div>
    </header>
    {error && <div className="audit-error" role="alert">{error}<button aria-label="Dismiss error" onClick={() => setError('')}><X size={14}/></button></div>}
    <section className="audit-list-section" aria-busy={listLoading}>
      <div className="audit-list-toolbar">
        <div><h2>Audits</h2><span>{pagination.total} total</span></div>
        <div className="audit-list-filters">
          <label className="audit-search"><Search size={14}/><input aria-label="Search audits" placeholder="Search audits or companies" value={filters.q} onChange={event => setFilters(current => ({ ...current, q: event.target.value, page: 1 }))}/></label>
          <select aria-label="Filter by status" value={filters.status} onChange={event => setFilters(current => ({ ...current, status: event.target.value, page: 1 }))}><option value="">All statuses</option><option value="context_review">Context review</option><option value="persona_review">Persona review</option><option value="creative_selection">Creative selection</option><option value="analyzing_creatives">Analyzing</option><option value="creative_paused">Paused</option><option value="creative_review">Creative review</option><option value="generating_insights">Generating insights</option><option value="complete">Complete</option><option value="failed">Failed</option></select>
          <select aria-label="Filter by relationship" value={filters.company_type} onChange={event => setFilters(current => ({ ...current, company_type: event.target.value, page: 1 }))}><option value="">All relationships</option>{['client', 'competitor', 'lead', 'partner', 'other'].map(type => <option key={type} value={type}>{relationshipLabel(type)}</option>)}</select>
        </div>
      </div>
      {listLoading ? <AuditSkeleton rows={4}/> : audits.length === 0 ? <div className="audit-empty-state"><Sparkles size={22}/><strong>No matching audits</strong><p>{runs.length ? 'Create an audit from a completed Ad Library run.' : 'Run an Ad Library search first, then return here to audit its grouped creatives.'}</p>{canManage && (runs.length ? <button className="audit-button primary" onClick={() => setDialogOpen(true)}>New content audit</button> : <Link className="audit-button primary" to="/ad-library">Open Ad Library</Link>)}</div> : <div className="audit-list">{audits.map(audit => <div key={audit.id}><Link to={`/ad-library/audits/${audit.id}`}><div><strong>{audit.name}</strong><small>{audit.company_name} · {relationshipLabel(audit.company_type)}</small></div><div className="audit-list-meta"><Status status={audit.status}/><small>{audit.creatives_count} creatives · {audit.personas_count} personas · {new Date(audit.created_at).toLocaleDateString()}</small></div></Link>{canManage && <button title="Delete audit" aria-label={`Delete ${audit.name}`} onClick={() => setDeleteAudit(audit)}><Trash2 size={14}/></button>}</div>)}</div>}
      {pagination.last > 1 && <div className="audit-pagination"><button disabled={pagination.current <= 1 || listLoading} onClick={() => setFilters(current => ({ ...current, page: current.page - 1 }))}><ChevronLeft size={14}/> Previous</button><span>Page {pagination.current} of {pagination.last}</span><button disabled={pagination.current >= pagination.last || listLoading} onClick={() => setFilters(current => ({ ...current, page: current.page + 1 }))}>Next <ChevronRight size={14}/></button></div>}
    </section>
    <NewAuditDialog open={dialogOpen} runs={runs} initialRunId={searchParams.get('run') || ''} loadingRuns={loading} onClose={() => setDialogOpen(false)} onCreated={audit => navigate(`/ad-library/audits/${audit.id}`)} />
    <ConfirmDialog open={Boolean(deleteAudit)} title={`Delete ${deleteAudit?.name || 'audit'}?`} description="This permanently removes the audit, its classifications, personas, insights, and AI request history." confirmLabel="Delete audit" busy={false} onClose={() => setDeleteAudit(null)} onConfirm={() => remove(deleteAudit)}/>
  </main>
}

function NewAuditDialog({ open, runs, initialRunId, loadingRuns, onClose, onCreated }) {
  const [step, setStep] = useState(1)
  const [runId, setRunId] = useState(initialRunId)
  const [creatives, setCreatives] = useState([])
  const [selected, setSelected] = useState([])
  const [creativeQuery, setCreativeQuery] = useState('')
  const [mediaDepth, setMediaDepth] = useState('visual')
  const [models, setModels] = useState([])
  const [modelKey, setModelKey] = useState('')
  const [name, setName] = useState('')
  const [websiteUrl, setWebsiteUrl] = useState('')
  const [preview, setPreview] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [loadingCreatives, setLoadingCreatives] = useState(false)
  const titleRef = useRef(null)
  const selectedModel = models.find(model => model.key === modelKey)
  const chosenRun = runs.find(run => String(run.id) === String(runId))
  const visibleCreatives = creatives.filter(row => `${row.title || ''} ${row.link_title || ''} ${row.body || ''}`.toLowerCase().includes(creativeQuery.toLowerCase()))
  const estimatedCost = useMemo(() => {
    if (!selectedModel || !selected.length) return null
    const multiplier = mediaDepth === 'text_only' ? 1 : mediaDepth === 'visual' ? 1.7 : 2.8
    const inputTokens = Math.round((4500 + selected.length * 1800) * multiplier)
    const outputTokens = 3500 + selected.length * 650
    return ((inputTokens * selectedModel.input_price) + (outputTokens * selectedModel.output_price)) / 1_000_000
  }, [mediaDepth, selected.length, selectedModel])

  useEffect(() => {
    if (!open) return undefined
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.setTimeout(() => titleRef.current?.focus(), 0)
    const close = event => event.key === 'Escape' && !busy && onClose()
    window.addEventListener('keydown', close)
    return () => { document.body.style.overflow = previous; window.removeEventListener('keydown', close) }
  }, [busy, onClose, open])

  useEffect(() => {
    if (!open) return
    api.getAdLibraryAuditModels(mediaDepth).then(({ data }) => {
      setModels(data.models || [])
      setModelKey(current => data.models?.some(model => model.key === current) ? current : (data.default || data.models?.[0]?.key || ''))
    }).catch(() => { setModels([]); setModelKey('') })
  }, [mediaDepth, open])

  useEffect(() => {
    if (!open || !runId) { setCreatives([]); setSelected([]); return }
    let current = true
    setLoadingCreatives(true)
    api.getAdLibraryResults(runId, { per_page: 100 }).then(({ data }) => {
      if (!current) return
      const rows = (data.data || []).slice(0, 50)
      setCreatives(rows); setSelected(rows.map(row => row.id))
      const run = runs.find(item => String(item.id) === String(runId))
      setName(`${run?.search?.company_name || 'Company'} content audit`)
      setWebsiteUrl(run?.search?.website_url || '')
    }).catch(() => current && setError('Unable to load grouped creatives.')).finally(() => current && setLoadingCreatives(false))
    return () => { current = false }
  }, [open, runId, runs])

  useEffect(() => {
    if (open && initialRunId && runs.some(run => String(run.id) === String(initialRunId))) setRunId(initialRunId)
  }, [initialRunId, open, runs])

  if (!open) return null
  const create = async () => {
    setBusy(true); setError('')
    try {
      const response = await api.createAdLibraryAudit({ run_id: Number(runId), name, website_url: websiteUrl || null, media_depth: mediaDepth, model_key: modelKey, result_ids: selected })
      onCreated(response.data)
    } catch (requestError) { setError(apiError(requestError, 'Unable to create the audit.')) }
    finally { setBusy(false) }
  }
  const toggleVisible = () => {
    const visibleIds = visibleCreatives.map(row => row.id)
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every(id => selected.includes(id))
    setSelected(current => allVisibleSelected ? current.filter(id => !visibleIds.includes(id)) : [...new Set([...current, ...visibleIds])].slice(0, 50))
  }

  return <div className="audit-dialog-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && !busy && onClose()}>
    <section className="audit-create-dialog" role="dialog" aria-modal="true" aria-labelledby="new-audit-title">
      <header><div><p className="audit-kicker">New content audit</p><h2 id="new-audit-title" ref={titleRef} tabIndex="-1">{step === 1 ? 'Choose the source and analysis depth' : 'Choose grouped creatives'}</h2><p>{step === 1 ? 'The selected model stays fixed across all audit stages.' : 'Only selected creatives consume AI analysis.'}</p></div><button aria-label="Close" disabled={busy} onClick={onClose}><X size={18}/></button></header>
      <div className="audit-dialog-steps"><span className={step === 1 ? 'active' : 'done'}><i>{step > 1 ? <Check size={12}/> : 1}</i> Setup</span><span className={step === 2 ? 'active' : ''}><i>2</i> Creatives</span></div>
      {error && <div className="audit-error" role="alert">{error}</div>}
      <div className="audit-dialog-body">
        {step === 1 ? <div className="audit-setup-grid">
          <Field label="Completed source run"><select required disabled={loadingRuns} value={runId} onChange={event => setRunId(event.target.value)}><option value="">{loadingRuns ? 'Loading runs…' : 'Select a run'}</option>{runs.map(run => <option key={run.id} value={run.id}>#{run.id} · {run.search?.company_name || 'Unknown company'} · {relationshipLabel(run.search?.company_type)} · {run.result_count} ads</option>)}</select></Field>
          <Field label="Audit name"><input required value={name} onChange={event => setName(event.target.value)}/></Field>
          <Field label="Website"><input type="url" placeholder="https://brand.com" value={websiteUrl} onChange={event => setWebsiteUrl(event.target.value)}/></Field>
          <div className="audit-depth-options" role="radiogroup" aria-label="Media depth">{[
            ['text_only', 'Text only', 'Copy and metadata'], ['visual', 'Visual', 'Copy, metadata, and images'], ['full_media', 'Full media', 'Images, video, and audio'],
          ].map(([value, label, help]) => <label className={mediaDepth === value ? 'active' : ''} key={value}><input type="radio" name="media-depth" value={value} checked={mediaDepth === value} onChange={() => setMediaDepth(value)}/><strong>{label}</strong><small>{help}</small></label>)}</div>
          <Field label="AI model"><select required value={modelKey} onChange={event => setModelKey(event.target.value)}><option value="">No compatible model configured</option>{models.map(model => <option key={model.key} value={model.key}>{model.label}</option>)}</select></Field>
          {chosenRun && <div className="audit-run-summary"><span>{chosenRun.result_count} ads found</span><span>{creatives.length} grouped creatives</span><span>{relationshipLabel(chosenRun.search?.company_type)}</span></div>}
        </div> : <>
          <div className="audit-picker-toolbar"><label className="audit-search"><Search size={14}/><input aria-label="Search grouped creatives" placeholder="Search copy or titles" value={creativeQuery} onChange={event => setCreativeQuery(event.target.value)}/></label><span>{selected.length}/50 selected</span><button type="button" onClick={toggleVisible}>{visibleCreatives.length > 0 && visibleCreatives.every(row => selected.includes(row.id)) ? 'Clear visible' : 'Select visible'}</button></div>
          {loadingCreatives ? <AuditSkeleton rows={5}/> : <div className="audit-creative-picker rich">{visibleCreatives.map(row => <div key={row.id} className={selected.includes(row.id) ? 'selected' : ''}><label><input type="checkbox" checked={selected.includes(row.id)} onChange={() => setSelected(values => values.includes(row.id) ? values.filter(id => id !== row.id) : values.length < 50 ? [...values, row.id] : values)}/><span className={`audit-media-tag ${row.media_type}`}>{row.media_type}</span><span><strong>{short(row.title || row.link_title || `Ad ${row.library_ad_id}`, 80)}</strong><small>{short(row.body || row.link_description || 'No ad copy available', 120)}</small></span><small>{row.variant_count} grouped ad{row.variant_count === 1 ? '' : 's'}</small></label><button aria-label="Preview creative" onClick={() => setPreview(row)}><Eye size={14}/></button></div>)}</div>}
        </>}
      </div>
      <footer><div>{estimatedCost != null && <><strong>Estimated AI cost ${estimatedCost < .01 ? estimatedCost.toFixed(4) : estimatedCost.toFixed(2)}</strong><small>Actual token usage is recorded in AI Usage.</small></>}</div><div>{step === 2 && <button className="audit-button secondary" disabled={busy} onClick={() => setStep(1)}><ChevronLeft size={14}/> Back</button>}{step === 1 ? <button className="audit-button primary" disabled={!runId || !name || !modelKey || loadingCreatives} onClick={() => setStep(2)}>Review {creatives.length} creatives <ChevronRight size={14}/></button> : <button className="audit-button primary" disabled={busy || !selected.length} onClick={create}>{busy ? <LoaderCircle className="spin" size={15}/> : <Sparkles size={15}/>} Create and collect context</button>}</div></footer>
    </section>
    <AdCreativeModal open={Boolean(preview)} creative={preview} onClose={() => setPreview(null)}/>
  </div>
}

function AuditWorkspace({ id }) {
  const { permissions } = useAuth()
  const canManage = Boolean(permissions?.manage_ad_library)
  const [searchParams, setSearchParams] = useSearchParams()
  const [audit, setAudit] = useState(null)
  const [creatives, setCreatives] = useState([])
  const [context, setContext] = useState({})
  const [contextDirty, setContextDirty] = useState(false)
  const contextDirtyRef = useRef(false)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [creativesLoading, setCreativesLoading] = useState(false)
  const [filter, setFilter] = useState({ q: '', status: '', media_type: '', persona_id: '', review_state: '', errors: '', product: '', angle: '', format: '', type: '' })
  const [debouncedFilter, setDebouncedFilter] = useState(filter)
  const [creativeModal, setCreativeModal] = useState(null)
  const [pendingStage, setPendingStage] = useState(null)

  const loadAudit = useCallback(async () => {
    const { data } = await api.getAdLibraryAudit(id)
    setAudit(data)
    if (!contextDirtyRef.current) setContext(data.website_context || {})
    return data
  }, [id])
  const loadCreatives = useCallback(async (filters = debouncedFilter) => {
    setCreativesLoading(true)
    try { const { data } = await api.getAdLibraryAuditCreatives(id, { ...filters, per_page: 100 }); setCreatives(data.data || []) }
    finally { setCreativesLoading(false) }
  }, [debouncedFilter, id])

  useEffect(() => { loadAudit().catch(requestError => setError(apiError(requestError, 'Unable to load this audit.'))) }, [loadAudit])
  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedFilter(filter), filter.q || filter.product || filter.angle || filter.format || filter.type ? 300 : 0)
    return () => window.clearTimeout(timer)
  }, [filter])
  useEffect(() => {
    if (!audit || activeStage(audit, searchParams.get('stage')) !== 'creatives') return
    let current = true
    setCreativesLoading(true)
    api.getAdLibraryAuditCreatives(id, { ...debouncedFilter, per_page: 100 }).then(({ data }) => current && setCreatives(data.data || [])).catch(requestError => current && setError(apiError(requestError, 'Unable to load creative results.'))).finally(() => current && setCreativesLoading(false))
    return () => { current = false }
  }, [audit, debouncedFilter, id, searchParams])
  useEffect(() => {
    if (!audit || !activeStatuses.has(audit.status)) return undefined
    const timer = window.setInterval(() => loadAudit().catch(() => {}), 3000)
    return () => window.clearInterval(timer)
  }, [audit, loadAudit])
  useEffect(() => {
    const warn = event => { if (contextDirtyRef.current) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [])

  const act = async (action, fallback, refreshCreatives = false) => {
    setBusy(true); setError('')
    try { await action(); await loadAudit(); if (refreshCreatives) await loadCreatives(); return true }
    catch (requestError) { setError(apiError(requestError, fallback)); return false }
    finally { setBusy(false) }
  }
  const chooseStage = key => {
    if (contextDirty && key !== 'context') { setPendingStage(key); return }
    setSearchParams(current => { const next = new URLSearchParams(current); next.set('stage', key); return next })
  }
  const markContextDirty = value => { contextDirtyRef.current = value; setContextDirty(value) }
  if (!audit) return <div className="audit-loading"><LoaderCircle className="spin"/> Loading audit…</div>
  const stageNumberValue = stageNumber(audit.status, audit.failed_stage)
  const stage = activeStage(audit, searchParams.get('stage'))
  const summary = audit.creative_summary || {}

  return <main className="audit-shell wide audit-workspace">
    <header className="audit-page-header audit-workspace-header"><div><Link className="audit-back" to="/ad-library/audits"><ArrowLeft size={14}/> Audits</Link><h1>{audit.name}</h1><p>{audit.company_name} · {relationshipLabel(audit.company_type)} · {audit.model} · {audit.media_depth.replaceAll('_', ' ')}</p></div><div className="audit-header-actions"><Status status={audit.status}/><a className="audit-button secondary" href={api.getAdLibraryAuditJsonUrl(id)}><FileJson size={14}/> JSON</a></div></header>
    <StageNavigation current={stage} available={stageNumberValue} audit={audit} summary={summary} onChoose={chooseStage}/>
    <div className="audit-stage-status" aria-live="polite">
      {activeStatuses.has(audit.status) ? <><LoaderCircle className="spin" size={16}/><span>{labelStatus(audit.status)}</span>{audit.status === 'analyzing_creatives' && <strong>{(summary.complete || 0) + (summary.failed || 0)}/{summary.selected || audit.progress_total}</strong>}</> : <><span className="audit-stage-dot"/><span>{stageHelp(stage, audit.status)}</span></>}
    </div>
    {error && <div className="audit-error" role="alert">{error}<button aria-label="Dismiss error" onClick={() => setError('')}><X size={14}/></button></div>}
    {audit.error_message && <div className="audit-warning">{audit.error_message}</div>}
    {audit.status === 'creative_paused' && <div className="audit-warning">Audit paused. Queued creatives will stay pending; an AI request that was already running may still finish safely.</div>}

    <div className="audit-stage-enter" key={stage}>
      {stage === 'context' && <ContextStep audit={audit} context={context} setContext={setContext} dirty={contextDirty} setDirty={markContextDirty} canManage={canManage} busy={busy} act={act}/>}
      {stage === 'personas' && <PersonasStep audit={audit} canManage={canManage} busy={busy} act={act}/>}
      {stage === 'creatives' && <CreativesStep audit={audit} creatives={creatives} loading={creativesLoading} canManage={canManage} busy={busy} filter={filter} setFilter={setFilter} act={act} openCreative={setCreativeModal}/>}
      {stage === 'insights' && <InsightsStep audit={audit} canManage={canManage} busy={busy} act={act}/>}
    </div>
    <AdCreativeModal open={Boolean(creativeModal)} creative={creativeModal} onClose={() => setCreativeModal(null)}/>
    <ConfirmDialog open={Boolean(pendingStage)} title="Discard unsaved context changes?" description="Your edits to the brand context have not been saved." confirmLabel="Discard changes" busy={false} onClose={() => setPendingStage(null)} onConfirm={() => { const nextStage = pendingStage; markContextDirty(false); setContext(audit.website_context || {}); setSearchParams(current => { const next = new URLSearchParams(current); next.set('stage', nextStage); return next }); setPendingStage(null) }}/>
  </main>
}

function StageNavigation({ current, available, audit, summary, onChoose }) {
  const details = {
    context: audit.context_sources?.length ? `${audit.context_sources.length} sources` : 'Brand evidence',
    personas: `${audit.personas?.length || 0} personas`,
    creatives: audit.status === 'creative_selection' ? `${summary.total || audit.creatives_count || 0} available` : `${summary.complete || audit.progress_completed || 0}/${summary.selected || audit.progress_total || 0} analyzed`,
    insights: audit.insights ? 'Ready' : 'Pending',
  }
  return <nav className="audit-stage-nav" aria-label="Audit stages">{stages.map((stage, index) => {
    const enabled = index + 1 <= available || (stage.key === 'insights' && Boolean(audit.insights))
    const complete = index + 1 < available || (stage.key === 'insights' && audit.status === 'complete')
    return <button key={stage.key} className={`${current === stage.key ? 'current' : ''} ${complete ? 'complete' : ''}`} disabled={!enabled} aria-current={current === stage.key ? 'step' : undefined} onClick={() => onChoose(stage.key)}><i>{complete ? <Check size={13}/> : index + 1}</i><span><strong>{stage.label}</strong><small>{details[stage.key]}</small></span></button>
  })}</nav>
}

function ContextStep({ audit, context, setContext, dirty, setDirty, canManage, busy, act }) {
  const editable = canManage && (['context_review', 'persona_review'].includes(audit.status) || (audit.status === 'failed' && audit.failed_stage === 'context'))
  const update = patch => { setContext(current => ({ ...current, ...patch })); setDirty(true) }
  const updateArray = (key, value) => update({ [key]: value.split('\n').map(item => item.trim()).filter(Boolean) })
  const saveAndContinue = () => act(async () => { await api.updateAdLibraryAuditContext(audit.id, { website_context: context }); setDirty(false); await api.generateAdLibraryAuditPersonas(audit.id) }, 'Unable to generate personas.')
  return <section className="audit-stage-panel"><StageHeading number="1" title="Approve brand context" description="Verify the public facts that will anchor persona and creative analysis." meta={audit.context_sources?.length ? `${audit.context_sources.length} sources` : null}/>
    {editable ? <div className="audit-context-layout"><div className="audit-context-editor"><Field label="Brand summary"><textarea rows="5" value={context.summary || ''} onChange={event => update({ summary: event.target.value })}/></Field><div className="audit-context-grid">{arrayFields.map(key => <Field key={key} label={key.replaceAll('_', ' ')}><textarea rows="4" value={(context[key] || []).join('\n')} onChange={event => updateArray(key, event.target.value)}/></Field>)}</div></div><SourceEvidence sources={audit.context_sources}/></div> : <div className="audit-context-layout"><ContextReadView context={context}/><SourceEvidence sources={audit.context_sources}/></div>}
    {editable && <div className="audit-sticky-actions"><span>{dirty ? 'Unsaved changes' : 'Context is up to date'}</span><div><button className="audit-button secondary" disabled={busy} onClick={() => act(() => api.collectAdLibraryAuditContext(audit.id), 'Unable to recollect context.')}><RefreshCw size={14}/> Recollect</button><button className="audit-button primary" disabled={busy} onClick={saveAndContinue}><Sparkles size={14}/> Save and suggest personas</button></div></div>}
  </section>
}

function ContextReadView({ context }) {
  return <div className="audit-read-view"><article className="audit-read-summary"><small>Brand summary</small><p>{context.summary || 'No summary available.'}</p></article>{arrayFields.map(key => <article key={key}><small>{key.replaceAll('_', ' ')}</small>{context[key]?.length ? <ul>{context[key].map((item, index) => <li key={index}>{item}</li>)}</ul> : <p>Not provided</p>}</article>)}</div>
}

function SourceEvidence({ sources = [] }) {
  return <aside className="audit-evidence"><div><strong>Source evidence</strong><small>{sources.length ? `${sources.length} public sources` : 'No source links captured'}</small></div>{sources.map(source => <a key={source.url} href={source.url} target="_blank" rel="noreferrer"><span>{source.title || source.url}</span><small>{source.url}</small></a>)}</aside>
}

function PersonasStep({ audit, canManage, busy, act }) {
  const [drafts, setDrafts] = useState({})
  const [expanded, setExpanded] = useState(audit.personas?.[0]?.id || null)
  const [saveState, setSaveState] = useState({})
  const [destructiveAction, setDestructiveAction] = useState(null)
  const timers = useRef({})
  useEffect(() => () => Object.values(timers.current).forEach(window.clearTimeout), [])
  const personas = audit.personas || []
  const autosave = audit.status === 'persona_review' && canManage
  const draft = persona => drafts[persona.id] || persona
  const payloadFor = value => { const payload = { ...value }; personaArrayFields.forEach(key => { payload[key] = Array.isArray(value[key]) ? value[key] : String(value[key] || '').split('\n').map(item => item.trim()).filter(Boolean) }); return payload }
  const update = (persona, key, value) => setDrafts(current => {
    const next = { ...(current[persona.id] || persona), [key]: value }
    if (autosave) {
      window.clearTimeout(timers.current[persona.id]); setSaveState(states => ({ ...states, [persona.id]: 'saving' }))
      timers.current[persona.id] = window.setTimeout(() => api.updateAdLibraryAuditPersona(audit.id, persona.id, payloadFor(next)).then(() => setSaveState(states => ({ ...states, [persona.id]: 'saved' }))).catch(() => setSaveState(states => ({ ...states, [persona.id]: 'error' }))), 900)
    }
    return { ...current, [persona.id]: next }
  })
  const runChange = action => {
    const invalidates = ['analyzing_creatives', 'creative_review', 'generating_insights', 'complete'].includes(audit.status)
    if (invalidates) setDestructiveAction(() => action); else action()
  }
  const save = persona => runChange(() => act(() => api.updateAdLibraryAuditPersona(audit.id, persona.id, payloadFor(draft(persona))), 'Unable to save persona.'))
  const move = (index, direction) => { const ordered = [...personas]; const target = index + direction; if (target < 0 || target >= ordered.length) return; [ordered[index], ordered[target]] = [ordered[target], ordered[index]]; runChange(() => act(() => api.reorderAdLibraryAuditPersonas(audit.id, ordered.map(item => item.id)), 'Unable to reorder personas.')) }
  return <section className="audit-stage-panel"><StageHeading number="2" title="Confirm audience personas" description="Review the suggested segments before classifying creatives." meta={`${personas.length}/10 personas`}/>
    <div className="audit-personas compact">{personas.map((persona, index) => { const value = draft(persona); const isOpen = expanded === persona.id; return <article key={persona.id} className={isOpen ? 'open' : ''}><button className="audit-persona-summary" onClick={() => setExpanded(isOpen ? null : persona.id)}><span className="audit-persona-avatar">{(value.name || '?').slice(0, 1).toUpperCase()}</span><span><strong>{value.name || 'Unnamed persona'}</strong><small>{short(value.description || 'No description', 120)}</small></span><span className="audit-persona-confidence">{saveState[persona.id] === 'saving' ? 'Saving…' : saveState[persona.id] === 'saved' ? 'Saved' : saveState[persona.id] === 'error' ? 'Save failed' : `${Math.round((persona.confidence || 0) * 100)}% confidence`}</span><ChevronDown size={16}/></button>{isOpen && <div className="audit-persona-editor"><div className="audit-persona-head"><input disabled={!canManage} aria-label="Persona name" value={value.name || ''} onChange={event => update(persona, 'name', event.target.value)}/>{canManage && <div><button disabled={index === 0} aria-label="Move persona up" onClick={() => move(index, -1)}><ChevronLeft size={14}/></button><button disabled={index === personas.length - 1} aria-label="Move persona down" onClick={() => move(index, 1)}><ChevronRight size={14}/></button><button aria-label="Delete persona" onClick={() => runChange(() => act(() => api.deleteAdLibraryAuditPersona(audit.id, persona.id), 'Unable to delete persona.'))}><Trash2 size={14}/></button></div>}</div><Field label="Description"><textarea disabled={!canManage} rows="2" value={value.description || ''} onChange={event => update(persona, 'description', event.target.value)}/></Field><Field label="Demographics or life stage"><input disabled={!canManage} value={value.demographics || ''} onChange={event => update(persona, 'demographics', event.target.value)}/></Field><div className="audit-persona-fields">{personaArrayFields.map(key => <Field key={key} label={key.replaceAll('_', ' ')}><textarea disabled={!canManage} rows="3" value={Array.isArray(value[key]) ? value[key].join('\n') : value[key] || ''} onChange={event => update(persona, key, event.target.value)}/></Field>)}</div>{canManage && !autosave && <button className="audit-text-button" onClick={() => save(persona)}>Save persona</button>}</div>}</article>})}</div>
    {canManage && <div className="audit-sticky-actions"><span>{autosave ? 'Edits save automatically' : 'Changes may reset later stages'}</span><div>{audit.status === 'failed' && audit.failed_stage === 'personas' && <button className="audit-button secondary" disabled={busy} onClick={() => act(() => api.generateAdLibraryAuditPersonas(audit.id), 'Unable to retry persona suggestions.')}><RefreshCw size={14}/> Retry suggestions</button>}<button className="audit-button secondary" disabled={busy || personas.length >= 10} onClick={() => runChange(() => act(() => api.createAdLibraryAuditPersona(audit.id, { name: 'New persona', description: 'Describe this audience segment.' }), 'Unable to add a persona.'))}><Plus size={14}/> Add persona</button>{audit.status === 'persona_review' && <button className="audit-button primary" disabled={busy || personas.length < 1} onClick={() => act(() => api.confirmAdLibraryAuditPersonas(audit.id), 'Unable to confirm personas.')}><Check size={14}/> Confirm personas</button>}</div></div>}
    <ConfirmDialog open={Boolean(destructiveAction)} title="Change confirmed personas?" description="This will clear creative classifications and generated insights so the audit can be run again with the updated personas." confirmLabel="Change personas" busy={busy} onClose={() => setDestructiveAction(null)} onConfirm={async () => { const action = destructiveAction; setDestructiveAction(null); await action?.() }}/>
  </section>
}

function CreativesStep({ audit, creatives, loading, canManage, busy, filter, setFilter, act, openCreative }) {
  const toast = useToast()
  const personas = audit.personas || []
  const selecting = audit.status === 'creative_selection'
  const [selected, setSelected] = useState([])
  const [selectionReady, setSelectionReady] = useState(false)
  const [editing, setEditing] = useState(null)
  const [confirmGrid, setConfirmGrid] = useState(false)
  const [exporting, setExporting] = useState(false)
  const summary = audit.creative_summary || {}
  const effectiveSelected = selectionReady ? selected : creatives.map(creative => creative.id)
  const updateSelected = updater => { setSelectionReady(true); setSelected(current => updater(selectionReady ? current : creatives.map(creative => creative.id))) }
  const allVisibleSelected = creatives.length > 0 && creatives.every(creative => effectiveSelected.includes(creative.id))
  const toggleVisible = () => updateSelected(current => allVisibleSelected ? current.filter(id => !creatives.some(creative => creative.id === id)) : [...new Set([...current, ...creatives.map(creative => creative.id)])])
  const save = (creative, form) => act(() => api.updateAdLibraryAuditCreative(audit.id, creative.id, form), 'Unable to save this creative.', true)
  const generate = () => act(async () => { await api.confirmAdLibraryAuditGrid(audit.id); await api.generateAdLibraryAuditInsights(audit.id) }, 'Unable to generate insights.', true)
  const markAllReviewed = async () => {
    if (await act(() => api.markAllAdLibraryAuditCreativesReviewed(audit.id), 'Unable to mark the creative audit as reviewed.', true)) toast.success('All completed creatives are marked reviewed.')
  }
  const openInGoogleSheets = async () => {
    window.open('https://sheets.new', '_blank', 'noopener,noreferrer')
    setExporting(true)
    try {
      const { data } = await api.getAdLibraryAuditSheets(audit.id)
      await copyText(data)
      toast.success('Creative audit copied. Paste it into the new Google Sheet.')
    } catch (requestError) {
      toast.error(apiError(requestError, 'Unable to prepare the Google Sheets export.'))
    } finally {
      setExporting(false)
    }
  }
  return <section className="audit-stage-panel"><StageHeading number="3" title={selecting ? 'Choose creatives' : 'Review creative audit'} description={selecting ? 'Choose which grouped creatives AI should classify.' : 'Review exceptions and edit classifications before generating insights.'} meta={selecting ? `${effectiveSelected.length} selected` : `${summary.reviewed || 0}/${summary.complete || 0} reviewed`}/>
    {selecting ? <><div className="audit-selection-bar"><button className="audit-text-button" onClick={toggleVisible}>{allVisibleSelected ? 'Clear visible' : 'Select visible'}</button><span>{effectiveSelected.length} of {summary.total || creatives.length} grouped creatives selected</span></div>{loading ? <AuditSkeleton rows={5}/> : <CreativeSelectionList creatives={creatives} selected={effectiveSelected} setSelected={updateSelected} openCreative={openCreative}/>}</> : <>
      <div className="audit-review-summary"><div><strong>{summary.complete || audit.progress_completed || 0}</strong><span>Analyzed</span></div><div><strong>{summary.pending_review || 0}</strong><span>Pending review</span></div><div className={summary.failed ? 'danger' : ''}><strong>{summary.failed || audit.progress_failed || 0}</strong><span>Failed</span></div><div><strong>{summary.skipped || 0}</strong><span>Skipped</span></div></div>
      <div className="audit-review-toolbar"><div><strong>Creative audit table</strong><span>Export includes every completed creative.</span></div><div>{canManage && <button className="audit-button secondary" disabled={busy || !summary.pending_review} onClick={markAllReviewed}><Check size={14}/> Mark all reviewed{summary.pending_review ? ` (${summary.pending_review})` : ''}</button>}<details className="audit-export-menu"><summary className="audit-button secondary"><Download size={14}/> Export <ChevronDown size={13}/></summary><div><a href={api.getAdLibraryAuditCsvUrl(audit.id)}><Download size={14}/><span><strong>CSV</strong><small>Comma-separated file</small></span></a><a href={api.getAdLibraryAuditXlsxUrl(audit.id)}><FileSpreadsheet size={14}/><span><strong>Excel</strong><small>.xlsx workbook</small></span></a><button disabled={exporting} onClick={openInGoogleSheets}><FileSpreadsheet size={14}/><span><strong>Google Sheets</strong><small>{exporting ? 'Copying table…' : 'Open a sheet and copy data'}</small></span></button></div></details></div></div>
      <div className="audit-filters"><label className="audit-search"><Search size={14}/><input aria-label="Search classifications" placeholder="Search classifications" value={filter.q} onChange={event => setFilter(current => ({ ...current, q: event.target.value }))}/></label><select value={filter.persona_id} onChange={event => setFilter(current => ({ ...current, persona_id: event.target.value }))}><option value="">All personas</option>{personas.map(persona => <option key={persona.id} value={persona.id}>{persona.name}</option>)}</select><select value={filter.media_type} onChange={event => setFilter(current => ({ ...current, media_type: event.target.value }))}><option value="">All media</option><option value="static">Static</option><option value="video">Video</option></select><select value={filter.review_state} onChange={event => setFilter(current => ({ ...current, review_state: event.target.value }))}><option value="">All review states</option><option value="pending">Pending review</option><option value="reviewed">Reviewed</option></select><select value={filter.status} onChange={event => setFilter(current => ({ ...current, status: event.target.value }))}><option value="">All processing</option><option value="pending">Pending</option><option value="running">Running</option><option value="complete">Complete</option><option value="failed">Failed</option><option value="skipped">Skipped</option></select><label className="audit-error-filter"><input type="checkbox" checked={filter.errors === '1'} onChange={event => setFilter(current => ({ ...current, errors: event.target.checked ? '1' : '' }))}/> Errors only</label></div>
      <details className="audit-more-filters"><summary><Filter size={13}/> More filters</summary><div><input placeholder="Product" value={filter.product} onChange={event => setFilter(current => ({ ...current, product: event.target.value }))}/><input placeholder="Angle" value={filter.angle} onChange={event => setFilter(current => ({ ...current, angle: event.target.value }))}/><input placeholder="Format" value={filter.format} onChange={event => setFilter(current => ({ ...current, format: event.target.value }))}/><input placeholder="Type" value={filter.type} onChange={event => setFilter(current => ({ ...current, type: event.target.value }))}/></div></details>
      {loading ? <AuditSkeleton rows={6}/> : creatives.length ? <CreativeReviewTable creatives={creatives} personas={personas} canManage={canManage} busy={busy} save={save} openCreative={openCreative} onEdit={setEditing}/> : <p className="audit-empty">No creatives match these filters.</p>}
    </>}
    {canManage && <div className="audit-sticky-actions"><span>{selecting ? 'Only selected creatives consume AI analysis.' : summary.failed ? 'Retry failed rows before generating insights.' : summary.pending_review ? `${summary.pending_review} completed rows will be approved when you continue.` : 'The reviewed grid is ready.'}</span><div>{selecting && <button className="audit-button primary" disabled={busy || effectiveSelected.length === 0} onClick={() => act(() => api.analyzeAdLibraryAudit(audit.id, effectiveSelected), 'Unable to start creative analysis.', true)}><Sparkles size={14}/> Start audit ({effectiveSelected.length})</button>}{audit.status === 'analyzing_creatives' && <button className="audit-button secondary" disabled={busy} onClick={() => act(() => api.pauseAdLibraryAudit(audit.id), 'Unable to pause creative analysis.', true)}><CirclePause size={14}/> Pause audit</button>}{audit.status === 'creative_paused' && <button className="audit-button primary" disabled={busy} onClick={() => act(() => api.resumeAdLibraryAudit(audit.id), 'Unable to resume creative analysis.', true)}><Play size={14}/> Resume audit</button>}{audit.status === 'creative_review' && summary.failed > 0 && <button className="audit-button secondary" disabled={busy} onClick={() => act(() => api.retryFailedAdLibraryAuditCreatives(audit.id), 'Unable to retry failed creatives.', true)}><RefreshCw size={14}/> Retry failed</button>}{audit.status === 'creative_review' && !summary.failed && <button className="audit-button primary" disabled={busy} onClick={() => summary.pending_review > 0 ? setConfirmGrid(true) : generate()}><Sparkles size={14}/> Generate insights</button>}</div></div>}
    <CreativeEditorDrawer key={editing?.id || 'closed'} creative={editing} personas={personas} busy={busy} onClose={() => setEditing(null)} onSave={async form => { if (await save(editing, form)) setEditing(null) }}/>
    <ConfirmDialog open={confirmGrid} title="Approve remaining creative rows?" description={`${summary.pending_review || 0} completed rows have not been individually marked reviewed. Continuing will approve them and generate insights.`} confirmLabel="Approve and generate" tone="primary" busy={busy} onClose={() => setConfirmGrid(false)} onConfirm={async () => { if (await generate()) setConfirmGrid(false) }}/>
  </section>
}

function CreativeSelectionList({ creatives, selected, setSelected, openCreative }) {
  return <div className="audit-selection-list">{creatives.map(creative => { const source = creative.source_snapshot || {}; return <article key={creative.id} className={selected.includes(creative.id) ? 'selected' : ''}><label><input type="checkbox" checked={selected.includes(creative.id)} onChange={event => setSelected(current => event.target.checked ? [...new Set([...current, creative.id])] : current.filter(id => id !== creative.id))}/><span className={`audit-media-tag ${source.media_type}`}>{source.media_type || 'unknown'}</span><span><strong>{source.title || source.link_title || `Ad ${creative.library_ad_ids?.[0] || creative.id}`}</strong><small>{short(source.body || source.link_description || 'No ad copy available', 160)}</small></span></label><div><small>{runningDuration(source)} · {creative.library_ad_ids?.length || 1} grouped ads</small><button className="audit-icon-button" aria-label="Preview creative" onClick={() => openCreative(source)}><Eye size={14}/></button></div></article>})}</div>
}

function CreativeReviewTable({ creatives, canManage, busy, save, openCreative, onEdit }) {
  return <div className="audit-table-wrap"><table className="audit-table audit-review-table"><thead><tr><th>Creative</th><th>Persona</th><th>Product</th><th>Angle</th><th>What it solves</th><th>Execution</th><th>Review</th></tr></thead><tbody>{creatives.map(creative => <CreativeRow key={`${creative.id}:${creative.updated_at}:${creative.processing_status}`} creative={creative} canManage={canManage} busy={busy} save={save} openCreative={openCreative} onEdit={onEdit}/>)}</tbody></table></div>
}

function CreativeRow({ creative, canManage, busy, save, openCreative, onEdit }) {
  const [reviewed, setReviewed] = useState(creative.review_status === 'reviewed')
  const primary = creative.personas?.find(persona => persona.pivot?.role === 'primary')
  const secondary = creative.personas?.filter(persona => persona.pivot?.role === 'secondary') || []
  const toggleReviewed = async () => { const next = !reviewed; setReviewed(next); if (!await save(creative, { reviewed: next })) setReviewed(!next) }
  return <tr className={creative.processing_status === 'failed' ? 'failed' : ''}><td className="audit-creative-cell"><button className="audit-source-button" onClick={() => openCreative(creative.source_snapshot)}><span className={`audit-media-tag ${creative.source_snapshot?.media_type}`}>{creative.source_snapshot?.media_type}</span><strong>{creative.source_snapshot?.title || creative.source_snapshot?.link_title || `Ad ${creative.library_ad_ids?.[0] || creative.id}`}</strong><small>{creative.source_snapshot?.is_active ? 'Active' : 'Inactive'} · {runningDuration(creative.source_snapshot)} · {creative.library_ad_ids?.length || 1} ads</small></button>{creative.error_message && <em>{creative.error_message}</em>}</td><td><strong>{primary?.name || 'Unassigned'}</strong>{secondary.length > 0 && <small>Also: {secondary.map(persona => persona.name).join(', ')}</small>}</td><td>{creative.product || '—'}</td><td>{creative.angle || '—'}</td><td><p className="audit-cell-copy">{creative.what_it_solves || '—'}</p></td><td><strong>{creative.format || '—'}</strong><small>{creative.type || 'Type not set'}</small></td><td>{creative.processing_status === 'complete' ? <div className="audit-row-actions"><button className={`audit-review-toggle ${reviewed ? 'reviewed' : ''}`} disabled={!canManage || busy} onClick={toggleReviewed}>{reviewed && <Check size={12}/>} {reviewed ? 'Reviewed' : 'Mark reviewed'}</button>{canManage && <button className="audit-icon-button" disabled={busy} title="Edit classification" onClick={() => onEdit(creative)}><Pencil size={14}/></button>}<small>{Math.round((creative.confidence || 0) * 100)}% confidence</small></div> : <Status status={creative.processing_status}/>}</td></tr>
}

function CreativeEditorDrawer({ creative, personas, busy, onClose, onSave }) {
  const [form, setForm] = useState(() => creative ? creativeReviewForm(creative) : null)
  useEffect(() => {
    if (!creative) return undefined
    const close = event => event.key === 'Escape' && !busy && onClose()
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [busy, creative, onClose])
  if (!creative || !form) return null
  const change = (key, value) => setForm(current => ({ ...current, [key]: value }))
  return <div className="audit-drawer-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose()}><aside className="audit-editor-drawer" role="dialog" aria-modal="true" aria-labelledby="creative-editor-title"><header><div><p className="audit-kicker">Creative classification</p><h2 id="creative-editor-title">{creative.source_snapshot?.title || creative.source_snapshot?.link_title || `Ad ${creative.id}`}</h2></div><button aria-label="Close editor" onClick={onClose}><X size={18}/></button></header><div className="audit-drawer-body"><Field label="Primary persona"><select value={form.primary_persona_id} onChange={event => change('primary_persona_id', Number(event.target.value))}><option value="">Select primary</option>{personas.map(persona => <option key={persona.id} value={persona.id}>{persona.name}</option>)}</select></Field><Field label="Secondary personas"><select multiple value={form.secondary_persona_ids.map(String)} onChange={event => change('secondary_persona_ids', Array.from(event.target.selectedOptions).map(option => Number(option.value)))}>{personas.filter(persona => persona.id !== Number(form.primary_persona_id)).map(persona => <option key={persona.id} value={persona.id}>{persona.name}</option>)}</select></Field><Field label="Product"><input value={form.product} onChange={event => change('product', event.target.value)}/></Field><Field label="Angle"><textarea rows="3" value={form.angle} onChange={event => change('angle', event.target.value)}/></Field><Field label="What it solves"><textarea rows="3" value={form.what_it_solves} onChange={event => change('what_it_solves', event.target.value)}/></Field><div className="audit-drawer-grid"><Field label="Format"><input value={form.format} onChange={event => change('format', event.target.value)}/></Field><Field label="Type"><input value={form.type} onChange={event => change('type', event.target.value)}/></Field></div><Field label="Remarks"><textarea rows="4" value={form.remarks} onChange={event => change('remarks', event.target.value)}/></Field></div><footer><button className="audit-button secondary" disabled={busy} onClick={onClose}>Cancel</button><button className="audit-button primary" disabled={busy || !form.primary_persona_id} onClick={() => onSave(form)}><Check size={14}/> Save classification</button></footer></aside></div>
}

function creativeReviewForm(creative) {
  const primary = creative.personas?.find(persona => persona.pivot?.role === 'primary')?.id || ''
  const secondary = creative.personas?.filter(persona => persona.pivot?.role === 'secondary').map(persona => persona.id) || []
  return { angle: creative.angle || '', product: creative.product || '', what_it_solves: creative.what_it_solves || '', format: creative.format || '', type: creative.type || '', remarks: creative.remarks || '', primary_persona_id: primary, secondary_persona_ids: secondary, reviewed: creative.review_status === 'reviewed' }
}

function InsightsStep({ audit, canManage, busy, act }) {
  const insights = audit.insights
  return <section className="audit-stage-panel"><StageHeading number="4" title="Content insights" description="Content observations only; Ad Library does not establish performance." meta={audit.insights_stale ? 'Stale after edits' : insights ? 'Ready' : 'Pending'}/>
    {!insights ? <div className="audit-empty-state"><Sparkles size={22}/><strong>Insights are not ready</strong><p>{audit.status === 'generating_insights' ? 'The final synthesis is running.' : 'Confirm the creative review grid to generate insights.'}</p></div> : <div className="audit-insights refined"><article className="audit-insight-lead"><small>Executive summary</small><p>{insights.executive_summary}</p><em>{insights.observations_disclaimer}</em></article><div className="audit-insight-columns"><section><h3>Coverage</h3><Coverage title="Persona × creative" rows={insights.persona_creative_coverage} render={row => `${row.persona}: ${row.creative_count}`}/><Coverage title="Persona × product" rows={insights.persona_product_coverage} render={row => `${row.persona}: ${(row.products || []).join(', ')}`}/></section><section><h3>Distribution</h3><Distribution title="Angles" rows={insights.angle_distribution}/><Distribution title="Formats" rows={insights.format_distribution}/><Distribution title="Types" rows={insights.type_distribution}/></section></div><div className="audit-opportunity-grid"><List title="Coverage gaps" items={insights.gaps}/><List title="Content opportunities" items={insights.content_opportunities}/><List title="Test recommendations" items={insights.test_recommendations}/><List title="Repetition and concentration" items={insights.repetition_and_concentration}/><List title="Long-running patterns" items={insights.long_running_patterns}/></div></div>}
    {canManage && audit.grid_confirmed_at && <div className="audit-sticky-actions"><span>{audit.insights_stale ? 'Creative edits made these insights stale.' : 'Insights reflect the confirmed creative grid.'}</span><button className="audit-button secondary" disabled={busy || audit.status === 'generating_insights'} onClick={() => act(() => api.generateAdLibraryAuditInsights(audit.id), 'Unable to regenerate insights.')}><RefreshCw size={14}/> Regenerate insights</button></div>}
  </section>
}

function StageHeading({ number, title, description, meta }) { return <div className="audit-stage-heading"><span>{number}</span><div><h2>{title}</h2><p>{description}</p></div>{meta && <small>{meta}</small>}</div> }
function Coverage({ title, rows = [], render }) { return <div className="audit-coverage"><strong>{title}</strong>{rows?.length ? rows.map((row, index) => <p key={index}>{render(row)}</p>) : <p>No data</p>}</div> }
function Distribution({ title, rows = [] }) { const max = Math.max(1, ...(rows || []).map(row => row.count)); return <div className="audit-distribution"><strong>{title}</strong>{rows?.length ? rows.map(row => <div className="audit-bar" key={row.label}><span>{row.label}</span><i style={{ width: `${(row.count / max) * 100}%` }}/><b>{row.count}</b></div>) : <p>No data</p>}</div> }
function List({ title, items = [] }) { return <section><h3>{title}</h3>{items?.length ? <ul>{items.map((item, index) => <li key={index}>{item}</li>)}</ul> : <p>No observations</p>}</section> }
function Field({ label, children }) { return <label className="audit-field"><span>{label}</span>{children}</label> }
function Status({ status }) { return <span className={`audit-status ${status}`}>{labelStatus(status)}</span> }
function AuditSkeleton({ rows = 3 }) { return <div className="audit-skeleton" aria-label="Loading">{Array.from({ length: rows }, (_, index) => <span key={index}/>)}</div> }
function labelStatus(status = '') { return status.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()) }
function relationshipLabel(value = 'other') { return value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()) }
function short(value = '', limit) { return value.length > limit ? `${value.slice(0, limit)}…` : value }
function runningDuration(source = {}) { if (!source.first_shown_at) return 'Duration unknown'; const start = new Date(source.first_shown_at); const end = source.is_active || !source.last_shown_at ? new Date() : new Date(source.last_shown_at); const days = Math.max(1, Math.ceil((end - start) / 86400000)); return `${days} day${days === 1 ? '' : 's'}` }
function stageNumber(status, failedStage = '') { if (status === 'failed') return { context: 1, personas: 2, creative: 3, insights: 4 }[failedStage] || 1; if (['draft', 'collecting_context', 'context_review'].includes(status)) return 1; if (['generating_personas', 'persona_review'].includes(status)) return 2; if (['creative_selection', 'analyzing_creatives', 'creative_paused', 'creative_review'].includes(status)) return 3; if (['generating_insights', 'complete'].includes(status)) return 4; return 1 }
function activeStage(audit, requested) { const available = stageNumber(audit.status, audit.failed_stage); const index = stages.findIndex(stage => stage.key === requested); if (index >= 0 && (index + 1 <= available || (requested === 'insights' && audit.insights))) return requested; return stages[available - 1]?.key || 'context' }
function stageHelp(stage, status) { if (status === 'failed') return 'This stage needs attention before the audit can continue.'; return { context: 'Verify the evidence used by the audit.', personas: 'Confirm the audience segments used for classification.', creatives: 'Select, monitor, and review creative classifications.', insights: 'Use the confirmed audit to identify content opportunities.' }[stage] }
function apiError(error, fallback) { const errors = error.response?.data?.errors; return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || fallback }
