import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import { Check as CheckIcon, Clock3, Copy, ExternalLink, LibraryBig, LoaderCircle, Play, PlayCircle, Plus, RefreshCw, Search, Star, Trash2, X } from 'lucide-react'
import { api } from '../api/client'
import AdCreativeModal from '../components/AdCreativeModal'
import { useAuth } from '../context/AuthContext'
import { copyText } from '../utils/clipboard'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { useToast } from '../context/ToastContext'

const terminalStatuses = new Set(['complete', 'partial', 'empty', 'blocked', 'failed'])
function SparklesIcon() { return <span aria-hidden="true">✦</span> }
const initialForm = { company_name: '', company_type: 'competitor', related_client_id: '', website_url: '', name: '', search_mode: 'keyword', query: '', advertiser_page_id: '', region: 'ALL', active_status: 'active', ad_type: 'all', exact_phrase: false, details: false, max_results: 100 }

export default function AdLibraryPage() {
  const { permissions, features } = useAuth()
  const [urlSearch] = useSearchParams()
  const relatedClientId = urlSearch.get('related_client_id') || ''
  const toast = useToast()
  const canManage = Boolean(permissions?.manage_ad_library)
  const canRunContentAudits = Boolean(permissions?.run_content_audits)
  const [form, setForm] = useState(initialForm)
  const [searches, setSearches] = useState([])
  const [clients, setClients] = useState([])
  const [tags, setTags] = useState([])
  const [tagFilter, setTagFilter] = useState('')
  const [newTag, setNewTag] = useState('')
  const [tagTarget, setTagTarget] = useState('')
  const [runs, setRuns] = useState([])
  const [selectedRun, setSelectedRun] = useState(null)
  const [results, setResults] = useState({ data: [], current_page: 1, last_page: 1, total: 0, raw_total: 0, duplicate_count: 0 })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [selectedCreative, setSelectedCreative] = useState(null)
  const [mediaFilter, setMediaFilter] = useState('all')
  const [favoritesOnly, setFavoritesOnly] = useState(false)
  const [creativeSort, setCreativeSort] = useState('relevance')
  const [minimumAge, setMinimumAge] = useState('')
  const [runJsonCopyState, setRunJsonCopyState] = useState('idle')
  const [searchModalOpen, setSearchModalOpen] = useState(false)
  const [pendingDelete, setPendingDelete] = useState(null)

  const loadWorkspace = useCallback(async () => {
    if (!features?.ad_library) return
    try {
      const [searchResponse, runResponse, clientResponse] = await Promise.all([
        api.getAdLibrarySearches({ tag_id: tagFilter || undefined, related_client_id: relatedClientId || undefined }),
        api.getAdLibraryRuns({ related_client_id: relatedClientId || undefined }),
        canManage ? api.getClients().catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
      ])
      setSearches(searchResponse.data.searches || [])
      setTags(searchResponse.data.tags || [])
      setRuns(runResponse.data.data || [])
      setClients(clientResponse.data || [])
      setError('')
    } catch (requestError) {
      setError(requestError.response?.data?.message || 'Unable to load Ad Library research.')
    }
  }, [canManage, features?.ad_library, relatedClientId, tagFilter])

  const loadResults = useCallback(async (run, page = 1) => {
    if (!run || !terminalStatuses.has(run.status) || run.result_count === 0) {
      setResults({ data: [], current_page: 1, last_page: 1, total: 0, raw_total: 0, duplicate_count: 0 })
      return
    }
    const response = await api.getAdLibraryResults(run.id, { page, per_page: 24, media_type: mediaFilter, favorites: favoritesOnly ? 1 : undefined, sort: creativeSort, min_age_days: minimumAge || undefined })
    setResults(response.data)
  }, [creativeSort, favoritesOnly, mediaFilter, minimumAge])

  useEffect(() => { loadWorkspace() }, [loadWorkspace])
  useEffect(() => { loadResults(selectedRun).catch(() => setError('Unable to load saved ads.')) }, [selectedRun, loadResults])

  const hasActiveRuns = useMemo(() => runs.some(run => !terminalStatuses.has(run.status)), [runs])
  useEffect(() => {
    if (!hasActiveRuns) return undefined
    const timer = window.setInterval(async () => {
      await loadWorkspace()
      if (selectedRun && !terminalStatuses.has(selectedRun.status)) {
        const response = await api.getAdLibraryRun(selectedRun.id)
        setSelectedRun(response.data)
      }
    }, 3000)
    return () => window.clearInterval(timer)
  }, [hasActiveRuns, loadWorkspace, selectedRun])

  if (!features?.ad_library) return <Navigate to="/" replace />

  const updateForm = (key, value) => setForm(current => {
    const next = { ...current, [key]: value }
    if (key === 'details' && value && Number(next.max_results) > 50) next.max_results = 50
    return next
  })

  const createAndRun = async (event) => {
    event.preventDefault()
    setLoading(true); setError('')
    try {
      const payload = { ...form, related_client_id: form.related_client_id ? Number(form.related_client_id) : null, website_url: form.website_url || null, max_results: Number(form.max_results), name: form.name.trim() || (form.search_mode === 'keyword' ? form.query : `Advertiser ${form.advertiser_page_id}`) }
      const created = await api.createAdLibrarySearch(payload)
      const run = await api.runAdLibrarySearch(created.data.id)
      setForm(initialForm)
      setSelectedRun(run.data)
      await loadWorkspace()
      setSearchModalOpen(false)
    } catch (requestError) {
      const messages = requestError.response?.data?.errors
      setError(messages ? Object.values(messages).flat().join(' ') : requestError.response?.data?.message || 'Unable to start the search.')
    } finally { setLoading(false) }
  }

  const rerun = async (searchId) => {
    setLoading(true); setError('')
    try {
      const response = await api.runAdLibrarySearch(searchId)
      setSelectedRun(response.data)
      await loadWorkspace()
    } catch (requestError) { setError(requestError.response?.data?.message || 'Unable to rerun the search.') }
    finally { setLoading(false) }
  }

  const removeSearch = async (id) => {
    setLoading(true)
    try { await api.deleteAdLibrarySearch(id); await loadWorkspace(); setPendingDelete(null); toast.success('Saved search removed.') }
    catch (requestError) { setError(requestError.response?.data?.message || 'Unable to remove the search.') }
    finally { setLoading(false) }
  }

  const removeRun = async (id) => {
    setLoading(true)
    try { await api.deleteAdLibraryRun(id); if (selectedRun?.id === id) setSelectedRun(null); await loadWorkspace(); setPendingDelete(null); toast.success('Run and saved results deleted.') }
    catch (requestError) { setError(requestError.response?.data?.message || 'Unable to delete the run.') }
    finally { setLoading(false) }
  }

  const copyRunJson = async () => {
    if (!selectedRun) return
    setRunJsonCopyState('copying'); setError('')
    try {
      const response = await api.getAdLibraryRunJson(selectedRun.id)
      await copyText(JSON.stringify(response.data, null, 2))
      setRunJsonCopyState('copied')
      window.setTimeout(() => setRunJsonCopyState('idle'), 1800)
    } catch (requestError) {
      setRunJsonCopyState('idle')
      setError(requestError.response?.data?.message || 'Unable to copy the run JSON response.')
    }
  }

  const attachTag = async event => {
    event.preventDefault()
    if (!newTag.trim() || !tagTarget) return
    try {
      const created = await api.createAdLibraryTag(newTag.trim())
      const search = searches.find(item => String(item.id) === String(tagTarget))
      await api.syncAdLibrarySearchTags(tagTarget, [...new Set([...(search?.tags || []).map(tag => tag.id), created.data.id])])
      setNewTag(''); await loadWorkspace()
    } catch (requestError) { setError(requestError.response?.data?.message || 'Unable to add the tag.') }
  }

  const toggleFavorite = async ad => {
    const favorite = !ad.favorite
    setResults(current => ({ ...current, data: current.data.map(item => item.id === ad.id ? { ...item, favorite } : item) }))
    try { await api.favoriteAdLibraryResult(ad.id, favorite) } catch { setResults(current => ({ ...current, data: current.data.map(item => item.id === ad.id ? { ...item, favorite: !favorite } : item) })); setError('Unable to update the favorite.') }
  }

  return (
    <div className="ad-library-page" style={{ padding: '28px', maxWidth: 1480, margin: '0 auto' }}>
      <header className="ad-library-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'end', marginBottom: 20 }}>
        <div><p style={eyebrow}>Research</p><h1 style={title}><LibraryBig size={25} /> Meta Ad Library</h1><p style={muted}>Save public competitor searches and keep each run’s completeness visible.</p></div>
        <div style={{ display: 'flex', gap: 8 }}>
          {features?.ad_library_audits && <Link to={`/ad-library/audits${selectedRun ? `?run=${selectedRun.id}` : ''}`} style={{ ...secondaryButton, textDecoration: 'none' }}><SparklesIcon /> Content audits</Link>}
          {canManage && <button type="button" style={primaryButton} onClick={() => { setError(''); setSearchModalOpen(true) }}><Plus size={15} /> New search</button>}
        </div>
      </header>

      {error && <div style={errorBox}>{error}</div>}

      <div className="ad-library-workspace" style={{ display: 'grid', gridTemplateColumns: 'minmax(280px, 0.8fr) minmax(560px, 2fr)', gap: 18 }}>
        <aside className="ad-library-sidebar" style={panel}>
          <SectionTitle title="Saved searches" count={searches.length} />
          {tags.length > 0 && <div className="ad-library-tags"><button className={!tagFilter ? 'active' : ''} onClick={() => setTagFilter('')}>All</button>{tags.map(tag => <button className={String(tag.id) === tagFilter ? 'active' : ''} key={tag.id} onClick={() => setTagFilter(String(tag.id))}>{tag.name}</button>)}</div>}
          {searches.length === 0 && <Empty text="No company research saved yet." />}
          {searches.map(search => <div key={search.id} style={listRow}>
            <div style={{ minWidth: 0 }}><strong style={{ fontSize: 13 }}>{search.name}</strong><div style={small}>{search.company_name} · {relationshipLabel(search.company_type)}{search.related_client ? ` to ${search.related_client.name}` : ''} · {search.search_mode === 'keyword' ? search.query : `Page ${search.advertiser_page_id}`} · {search.region} · {search.runs_count} runs</div>{search.tags?.length > 0 && <div className="ad-library-row-tags">{search.tags.map(tag => <span key={tag.id}>{tag.name}</span>)}</div>}</div>
            {canManage && <div style={{ display: 'flex', gap: 5 }}><IconButton title="Run again" onClick={() => rerun(search.id)}><Play size={13} /></IconButton><IconButton title="Delete search" onClick={() => setPendingDelete({ type: 'search', id: search.id, title: search.name })}><Trash2 size={13} /></IconButton></div>}
          </div>)}
          {canManage && searches.length > 0 && <form className="ad-library-tag-form" onSubmit={attachTag}><select value={tagTarget} onChange={event => setTagTarget(event.target.value)} required><option value="">Tag a search…</option>{searches.map(search => <option value={search.id} key={search.id}>{search.name}</option>)}</select><input value={newTag} onChange={event => setNewTag(event.target.value)} placeholder="Tag name"/><button>Add</button></form>}

          <div style={{ marginTop: 24 }}><SectionTitle title="Run history" count={runs.length} /></div>
          {runs.length === 0 && <Empty text="No runs yet." />}
          {runs.map(run => <button key={run.id} onClick={() => setSelectedRun(run)} style={{ ...runRow, borderColor: selectedRun?.id === run.id ? '#3b82f6' : '#e2e8f0', background: selectedRun?.id === run.id ? '#eff6ff' : '#fff' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}><strong>#{run.id} · {run.search?.name || 'Deleted search'}</strong><Status status={run.status} /></div>
            <div style={small}>{new Date(run.created_at).toLocaleString()} · {run.result_count} ads{run.capped ? ' · capped' : ''}</div>
          </button>)}
        </aside>

        <section className="ad-library-results" style={panel}>
          {!selectedRun ? <Empty text="Select a run to inspect its saved ads." /> : <>
            <div className="ad-library-run-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', marginBottom: 16 }}>
              <div><div style={{ display: 'flex', gap: 8, alignItems: 'center' }}><h2 style={{ margin: 0, fontSize: 18 }}>Run #{selectedRun.id}</h2><Status status={selectedRun.status} /></div><p style={{ ...small, marginTop: 6 }}>{selectedRun.result_count} saved{selectedRun.reported_total != null ? ` of ~${selectedRun.reported_total} reported` : ''}{selectedRun.stop_reason ? ` · ${selectedRun.stop_reason}` : ''}</p></div>
              <div className="ad-library-run-actions" style={{ display: 'flex', gap: 7, alignItems: 'center' }}>
                {terminalStatuses.has(selectedRun.status) && <button type="button" style={jsonButton} disabled={runJsonCopyState === 'copying'} onClick={copyRunJson}>
                  {runJsonCopyState === 'copied' ? <CheckIcon size={13} /> : runJsonCopyState === 'copying' ? <LoaderCircle className="spin" size={13} /> : <Copy size={13} />}
                  {runJsonCopyState === 'copied' ? 'Copied' : 'Copy run JSON'}
                </button>}
                {features?.ad_library_audits && canRunContentAudits && ['complete', 'partial'].includes(selectedRun.status) && selectedRun.result_count > 0 && <Link to={`/ad-library/audits?run=${selectedRun.id}`} style={{ ...jsonButton, textDecoration: 'none' }}>Create content audit</Link>}
                <IconButton title="Refresh" onClick={async () => { const response = await api.getAdLibraryRun(selectedRun.id); setSelectedRun(response.data); await loadResults(response.data) }}><RefreshCw size={14} /></IconButton>
                {canManage && terminalStatuses.has(selectedRun.status) && <IconButton title="Delete run" onClick={() => setPendingDelete({ type: 'run', id: selectedRun.id, title: `Run #${selectedRun.id}` })}><Trash2 size={14} /></IconButton>}
              </div>
            </div>
            {!terminalStatuses.has(selectedRun.status) && <div style={waiting}><LoaderCircle className="spin" size={18} /> The isolated scraper is working. This page refreshes automatically.</div>}
            {selectedRun.error_message && <div style={errorBox}>{selectedRun.error_message}</div>}
            {terminalStatuses.has(selectedRun.status) && selectedRun.result_count > 0 && <div className="ad-library-filter-bar">
              <div className="ad-library-filter-tabs" role="group" aria-label="Creative type">
                {['all', 'static', 'video'].map(type => <button key={type} type="button" className={mediaFilter === type ? 'active' : ''} onClick={() => setMediaFilter(type)}>{type === 'all' ? 'All creatives' : type === 'static' ? 'Static' : 'Video'}</button>)}
              </div>
              <button className={`ad-library-favorite-filter ${favoritesOnly ? 'active' : ''}`} onClick={() => setFavoritesOnly(value => !value)}><Star size={12} fill={favoritesOnly ? 'currentColor' : 'none'}/> Favorites</button>
              <label className="ad-library-filter-select"><span>Age</span><select aria-label="Filter by creative age" value={minimumAge} onChange={event => setMinimumAge(event.target.value)}><option value="">Any age</option><option value="7">7+ days</option><option value="30">1+ month</option><option value="90">3+ months</option><option value="180">6+ months</option></select></label>
              <label className="ad-library-filter-select"><span>Sort</span><select aria-label="Sort creatives" value={creativeSort} onChange={event => setCreativeSort(event.target.value)}><option value="relevance">Relevance</option><option value="oldest">Oldest first</option></select></label>
              <span>{results.total} creative{results.total === 1 ? '' : 's'}{results.duplicate_count > 0 ? ` · ${results.duplicate_count} repeat${results.duplicate_count === 1 ? '' : 's'} grouped` : ''}</span>
            </div>}
            {terminalStatuses.has(selectedRun.status) && results.data.length === 0 && <Empty text={selectedRun.status === 'empty' ? 'Meta returned no matching ads.' : favoritesOnly || minimumAge ? 'No creatives match the current filters.' : mediaFilter === 'all' ? 'This run has no saved ads.' : `No ${mediaFilter} creatives in this run.`} />}
            <div className="ad-library-card-grid" style={cardGrid}>{results.data.map(ad => <AdCard key={ad.creative_key || ad.id} ad={ad} onFavorite={() => toggleFavorite(ad)} onViewDetails={() => setSelectedCreative(ad)} />)}</div>
            {results.last_page > 1 && <div style={{ display: 'flex', justifyContent: 'center', gap: 8, marginTop: 18 }}><button style={secondaryButton} disabled={results.current_page <= 1} onClick={() => loadResults(selectedRun, results.current_page - 1)}>Previous</button><span style={{ ...small, padding: '9px' }}>{results.current_page} / {results.last_page}</span><button style={secondaryButton} disabled={results.current_page >= results.last_page} onClick={() => loadResults(selectedRun, results.current_page + 1)}>Next</button></div>}
          </>}
        </section>
      </div>
      <SearchModal
        open={searchModalOpen}
        form={form}
        clients={clients}
        loading={loading}
        error={error}
        updateForm={updateForm}
        onSubmit={createAndRun}
        onClose={() => { if (!loading) setSearchModalOpen(false) }}
      />
      <AdCreativeModal
        key={selectedCreative?.creative_key || 'closed'}
        open={Boolean(selectedCreative)}
        onClose={() => setSelectedCreative(null)}
        creative={selectedCreative}
      />
      <ConfirmDialog open={Boolean(pendingDelete)} title={`Delete ${pendingDelete?.title || 'item'}?`} description={pendingDelete?.type === 'search' ? 'The saved search will be removed. Existing run history remains available.' : 'This permanently removes the run and all saved results from it.'} confirmLabel="Delete" busy={loading} onClose={() => setPendingDelete(null)} onConfirm={() => pendingDelete?.type === 'search' ? removeSearch(pendingDelete.id) : removeRun(pendingDelete.id)}/>
    </div>
  )
}

function SearchModal({ open, form, clients, loading, error, updateForm, onSubmit, onClose }) {
  useEffect(() => {
    if (!open) return undefined
    const previousOverflow = document.body.style.overflow
    const onKeyDown = event => { if (event.key === 'Escape' && !loading) onClose() }
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKeyDown)
    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', onKeyDown)
    }
  }, [loading, onClose, open])

  if (!open) return null

  return <div className="ad-library-search-backdrop" role="dialog" aria-modal="true" aria-labelledby="ad-library-search-title" onClick={onClose}>
    <div className="ad-library-search-modal" onClick={event => event.stopPropagation()}>
      <header className="ad-library-search-modal-header">
        <div><p style={eyebrow}>Meta Ad Library</p><h2 id="ad-library-search-title">New saved search</h2></div>
        <button type="button" aria-label="Close new search" disabled={loading} onClick={onClose}><X size={17} /></button>
      </header>
      <form className="ad-library-search-form" onSubmit={onSubmit}>
        {error && <div style={errorBox}>{error}</div>}
        <div className="ad-library-search-form-grid">
          <Field label="Company / brand"><input required autoFocus style={input} value={form.company_name} onChange={event => updateForm('company_name', event.target.value)} placeholder="Company being researched" /></Field>
          <Field label="Relationship"><select style={input} value={form.company_type} onChange={event => updateForm('company_type', event.target.value)}><option value="competitor">Competitor</option><option value="lead">Lead</option><option value="client">Client</option><option value="partner">Partner</option><option value="other">Other</option></select></Field>
          <Field label={relatedClientLabel(form.company_type)}><select style={input} value={form.related_client_id} onChange={event => updateForm('related_client_id', event.target.value)}><option value="">No client linked</option>{clients.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}</select></Field>
          <Field label="Website"><input type="url" style={input} value={form.website_url} onChange={event => updateForm('website_url', event.target.value)} placeholder="https://company.com" /></Field>
          <Field label="Name"><input style={input} value={form.name} onChange={event => updateForm('name', event.target.value)} placeholder="Optional label" /></Field>
          <Field label="Search by"><select style={input} value={form.search_mode} onChange={event => updateForm('search_mode', event.target.value)}><option value="keyword">Keyword</option><option value="advertiser">Advertiser Page ID</option></select></Field>
          {form.search_mode === 'keyword'
            ? <Field label="Keyword"><input required style={input} value={form.query} onChange={event => updateForm('query', event.target.value)} placeholder="running shoes" /></Field>
            : <Field label="Page ID"><input required pattern="\d+" style={input} value={form.advertiser_page_id} onChange={event => updateForm('advertiser_page_id', event.target.value)} placeholder="15087023444" /></Field>}
          <Field label="Country"><input required style={input} maxLength={3} value={form.region} onChange={event => updateForm('region', event.target.value.toUpperCase())} placeholder="ALL or US" /></Field>
          <Field label="Status"><select style={input} value={form.active_status} onChange={event => updateForm('active_status', event.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option><option value="all">All</option></select></Field>
          <Field label="Ad type"><select style={input} value={form.ad_type} onChange={event => updateForm('ad_type', event.target.value)}><option value="all">All ads</option><option value="political_and_issue_ads">Political & issue</option></select></Field>
          <Field label="Result limit"><input required type="number" min="1" max={form.details ? 50 : 500} style={input} value={form.max_results} onChange={event => updateForm('max_results', event.target.value)} /></Field>
        </div>
        <div className="ad-library-search-options">
          {form.search_mode === 'keyword' && <Check label="Exact phrase" checked={form.exact_phrase} onChange={value => updateForm('exact_phrase', value)} />}
          <Check label="Fetch detailed reach and targeting (max 50)" checked={form.details} onChange={value => updateForm('details', value)} />
        </div>
        <footer className="ad-library-search-actions">
          <button type="button" disabled={loading} style={secondaryButton} onClick={onClose}>Cancel</button>
          <button disabled={loading || !form.company_name.trim()} style={primaryButton}>{loading ? <LoaderCircle className="spin" size={15} /> : <Search size={15} />} Save & run</button>
        </footer>
      </form>
    </div>
  </div>
}

function AdCard({ ad, onViewDetails, onFavorite }) {
  const [imageFailed, setImageFailed] = useState(false)
  const image = ad.image_urls?.[0]
  const runDuration = formatRunDuration(ad)
  return <article className="ad-library-card" style={adCard}>
    <button className={`ad-library-favorite ${ad.favorite ? 'active' : ''}`} aria-label={ad.favorite ? 'Remove favorite' : 'Add favorite'} onClick={onFavorite}><Star size={15} fill={ad.favorite ? 'currentColor' : 'none'}/></button>
    <button type="button" aria-label={`View ${ad.advertiser_name || 'ad'} creative details`} className="ad-library-media" style={mediaBox} onClick={onViewDetails}>
      {image && !imageFailed ? <img src={image} alt="" onError={() => setImageFailed(true)} /> : ad.media_type === 'video' ? <PlayCircle size={32} color="#94a3b8" /> : <LibraryBig size={28} color="#94a3b8" />}
      <span className={`ad-library-media-tag ${ad.media_type}`}>{ad.media_type === 'video' ? <PlayCircle size={11} /> : null}{ad.media_type === 'video' ? 'Video' : 'Static'}</span>
      {ad.variant_count > 1 && <span className="ad-library-variant-tag">{ad.variant_count} ads</span>}
    </button>
    <div className="ad-library-card-body" style={{ padding: 13 }}>
      <div className="ad-library-card-meta">
        <span><Clock3 size={12} />{runDuration}</span>
        <span style={ad.is_active ? activeChip : inactiveChip}>{ad.is_active ? 'Active' : 'Inactive'}</span>
      </div>
      <p className="ad-library-card-copy" title={ad.body || ad.title || ''} style={{ fontSize: 12, lineHeight: 1.45, color: '#334155', minHeight: 35 }}>{ad.body || ad.title || 'No ad copy available.'}</p>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5 }}>{(ad.platforms || []).map(platform => <span key={platform} style={chip}>{platform}</span>)}</div>
      {(ad.target_ages || ad.target_gender || ad.eu_reach) && <p style={{ ...small, marginTop: 10 }}>{[ad.target_ages, ad.target_gender, ad.eu_reach ? `EU reach ${ad.eu_reach.toLocaleString()}` : null].filter(Boolean).join(' · ')}</p>}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginTop: 11 }}>
        {ad.product_url ? <a href={ad.product_url} target="_blank" rel="noreferrer" style={sourceLink}>Open in Meta <ExternalLink size={12} /></a> : <span />}
        <button type="button" onClick={onViewDetails} style={adJsonButton}>View details</button>
      </div>
    </div>
  </article>
}

function formatRunDuration(ad) {
  const start = Date.parse(ad.first_shown_at)
  const recordedEnd = Date.parse(ad.last_shown_at)
  if (Number.isNaN(start)) return ad.is_active ? 'Currently running' : 'Run duration unavailable'

  const end = ad.is_active || Number.isNaN(recordedEnd) ? Date.now() : recordedEnd
  const days = Math.max(1, Math.floor((end - start) / 86_400_000))
  return `${ad.is_active ? 'Running' : 'Ran'} for ${days} ${days === 1 ? 'day' : 'days'}`
}

function Field({ label, children }) { return <label style={{ display: 'grid', gap: 6 }}><span style={labelStyle}>{label}</span>{children}</label> }
function Check({ label, checked, onChange }) { return <label style={{ display: 'flex', gap: 7, alignItems: 'center', fontSize: 12, color: '#475569' }}><input type="checkbox" checked={checked} onChange={event => onChange(event.target.checked)} />{label}</label> }
function SectionTitle({ title: text, count }) { return <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 10 }}><strong style={{ fontSize: 14 }}>{text}</strong><span style={countChip}>{count}</span></div> }
function Empty({ text }) { return <div style={{ padding: '28px 12px', textAlign: 'center', color: '#94a3b8', fontSize: 13 }}>{text}</div> }
function IconButton({ children, ...props }) { return <button type="button" style={iconButton} {...props}>{children}</button> }
function Status({ status }) { return <span style={{ ...statusChip, background: statusColor[status]?.bg || '#f1f5f9', color: statusColor[status]?.fg || '#475569' }}>{status}</span> }
function relationshipLabel(value = 'other') { return value.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()) }
function relatedClientLabel(value) {
  return ({ competitor: 'Competitor of client', lead: 'Lead for client', client: 'Client record', partner: 'Partner of client', other: 'Related client' })[value] || 'Related client'
}

const statusColor = { complete: { bg: '#dcfce7', fg: '#166534' }, partial: { bg: '#fef3c7', fg: '#92400e' }, failed: { bg: '#fee2e2', fg: '#991b1b' }, blocked: { bg: '#fee2e2', fg: '#991b1b' }, empty: { bg: '#f1f5f9', fg: '#475569' }, pending: { bg: '#e0f2fe', fg: '#075985' }, running: { bg: '#dbeafe', fg: '#1d4ed8' } }
const eyebrow = { margin: '0 0 5px', textTransform: 'uppercase', letterSpacing: '.11em', color: '#3b82f6', fontSize: 10, fontWeight: 700 }
const title = { margin: 0, display: 'flex', alignItems: 'center', gap: 9, fontFamily: 'Syne, sans-serif', color: '#0f172a' }
const muted = { margin: '6px 0 0', color: '#64748b', fontSize: 13 }
const panel = { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 18, boxShadow: '0 2px 8px rgba(15,23,42,.03)' }
const input = { minHeight: 38, border: '1px solid #cbd5e1', borderRadius: 8, padding: '8px 10px', background: '#fff', color: '#0f172a', fontFamily: 'inherit', fontSize: 13 }
const labelStyle = { fontSize: 11, color: '#64748b', fontWeight: 600 }
const primaryButton = { display: 'flex', alignItems: 'center', gap: 7, padding: '9px 14px', border: 0, borderRadius: 8, background: '#2563eb', color: '#fff', fontWeight: 600, cursor: 'pointer' }
const secondaryButton = { padding: '8px 12px', border: '1px solid #cbd5e1', borderRadius: 8, background: '#fff', cursor: 'pointer' }
const jsonButton = { display: 'inline-flex', alignItems: 'center', gap: 6, minHeight: 30, padding: '6px 10px', border: '1px solid #bfdbfe', borderRadius: 7, background: '#eff6ff', color: '#1d4ed8', fontFamily: 'inherit', fontSize: 11, fontWeight: 700, cursor: 'pointer' }
const errorBox = { padding: '10px 12px', marginBottom: 14, border: '1px solid #fecaca', borderRadius: 8, background: '#fef2f2', color: '#991b1b', fontSize: 13 }
const listRow = { display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'center', padding: '10px 0', borderBottom: '1px solid #f1f5f9' }
const runRow = { display: 'block', width: '100%', textAlign: 'left', padding: 10, marginBottom: 7, border: '1px solid', borderRadius: 8, cursor: 'pointer', fontFamily: 'inherit', color: '#0f172a' }
const small = { fontSize: 11, color: '#64748b', marginTop: 4 }
const iconButton = { width: 30, height: 30, display: 'grid', placeItems: 'center', border: '1px solid #e2e8f0', borderRadius: 7, background: '#fff', color: '#475569', cursor: 'pointer' }
const statusChip = { padding: '3px 7px', borderRadius: 999, fontSize: 10, fontWeight: 700, textTransform: 'uppercase' }
const countChip = { ...statusChip, background: '#f1f5f9', color: '#475569' }
const waiting = { display: 'flex', gap: 8, alignItems: 'center', padding: 13, borderRadius: 8, background: '#eff6ff', color: '#1d4ed8', fontSize: 13 }
const cardGrid = { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(245px, 1fr))', gap: 13 }
const adCard = { border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden', background: '#fff' }
const mediaBox = { height: 180, background: '#f1f5f9', display: 'grid', placeItems: 'center' }
const chip = { padding: '3px 6px', borderRadius: 5, background: '#f1f5f9', color: '#475569', fontSize: 9, fontWeight: 600 }
const activeChip = { ...chip, background: '#dcfce7', color: '#166534' }
const inactiveChip = { ...chip, background: '#f1f5f9', color: '#64748b' }
const sourceLink = { display: 'inline-flex', gap: 5, alignItems: 'center', color: '#2563eb', fontSize: 11, textDecoration: 'none', fontWeight: 600 }
const adJsonButton = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 8px', border: '1px solid #e2e8f0', borderRadius: 6, background: '#f8fafc', color: '#475569', fontFamily: 'inherit', fontSize: 10, fontWeight: 700, cursor: 'pointer' }
