import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { AlertTriangle, ArrowLeft, DatabaseZap, ExternalLink, FileText, HeartPulse, LayoutDashboard } from 'lucide-react'
import { Area, AreaChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '../api/client'
import PerformanceMetricsTable from '../components/PerformanceMetricsTable'
import PerformancePeriodControl from '../components/PerformancePeriodControl'
import { ScopedAlerts, ScopedConnections, ScopedDataHealth, ScopedReports } from '../components/ScopedOperations'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import { completedDateRange, formatMoney, formatNumber, formatRoas, metricColumns } from '../lib/performance'
import { adPlatformEntityUrl, adPlatformLabel } from '../lib/adPlatformDeepLinks'
import { accountPerformancePath, adGroupPerformancePath, adPerformancePath, campaignPerformancePath, commandCenterPath } from '../lib/performanceRoutes'

const accountSections = [
  ['details', 'Details', <LayoutDashboard key="details-icon" size={14}/>],
  ['alerts', 'Alerts', <AlertTriangle key="alerts-icon" size={14}/>],
  ['data-health', 'Data health', <HeartPulse key="health-icon" size={14}/>],
  ['reports', 'Reports', <FileText key="reports-icon" size={14}/>],
  ['connection', 'Connection', <DatabaseZap key="connection-icon" size={14}/>],
]

export default function PerformanceDrilldownPage() {
  const { clientId, accountId, campaignId, groupId, adId } = useParams()
  const [search, setSearch] = useSearchParams()
  const navigate = useNavigate()
  const accounts = useQuery({ queryKey: ['performance-accounts'], queryFn: () => api.getPerformanceAccounts().then(response => response.data.accounts) })
  const account = accounts.data?.find(item => (String(item.id) === String(accountId) || item.account_ids?.some(id => String(id) === String(accountId))) && String(item.client?.id) === String(clientId))
  const range = search.get('range') || '30d'
  const preset = completedDateRange(Number(range === 'custom' ? 30 : range.replace('d', '')), account?.timezone)
  const dates = { start_date: search.get('start_date') || preset.start_date, end_date: search.get('end_date') || preset.end_date }
  const queryString = new URLSearchParams({ range, ...dates }).toString()
  const section = search.get('section') || 'details'
  const updatePeriod = next => setSearch(current => { const merged = new URLSearchParams(current); Object.entries(next).forEach(([key, value]) => value ? merged.set(key, value) : merged.delete(key)); return merged }, { replace: true })
  const selectSection = value => setSearch(current => { const next = new URLSearchParams(current); next.set('section', value); return next }, { replace: true })
  const enabled = Boolean(account)
  const overview = useQuery({ queryKey: ['account-overview', accountId, dates], queryFn: () => api.getAccountPerformance(accountId, dates).then(response => response.data), enabled: enabled && !campaignId })
  const campaigns = useQuery({ queryKey: ['account-campaigns', accountId, dates], queryFn: () => api.getAccountCampaigns(accountId, dates).then(response => response.data), enabled: enabled && !campaignId })
  const groups = useQuery({ queryKey: ['campaign-ad-groups', accountId, campaignId, dates], queryFn: () => api.getCampaignAdGroups(accountId, campaignId, dates).then(response => response.data), enabled: enabled && Boolean(campaignId) && !groupId })
  const ads = useQuery({ queryKey: ['ad-group-ads', accountId, campaignId, groupId, dates], queryFn: () => api.getAdGroupAds(accountId, campaignId, groupId, dates).then(response => response.data), enabled: enabled && Boolean(groupId) })
  const activeQueries = [accounts, ...(groupId ? [ads] : campaignId ? [groups] : [overview, campaigns])]

  const level = adId ? 'ad' : groupId ? 'group' : campaignId ? 'campaign' : 'account'
  const payload = groupId ? ads.data : level === 'campaign' ? groups.data : overview.data
  const selectedAd = adId ? payload?.ads?.find(row => String(row.ad?.id) === String(adId)) : null
  const title = level === 'ad' ? selectedAd?.ad?.name : level === 'group' ? payload?.ad_group?.name : level === 'campaign' ? payload?.campaign?.name : account?.name
  const metrics = level === 'ad' ? selectedAd?.metrics : level === 'account' ? overview.data?.metrics : payload?.metrics
  const childLabel = level === 'account' ? 'Campaigns' : level === 'campaign' ? (account?.platform === 'meta' ? 'Ad sets' : 'Ad groups') : level === 'group' ? 'Ads' : null
  const childNoun = level === 'account' ? 'campaign' : account?.platform === 'meta' ? 'ad set' : 'ad group'
  const groupLabel = account?.platform === 'meta' ? 'Ad set' : 'Ad group'
  const platformLabel = adPlatformLabel(account?.platform)
  const platformUrlFor = ({ campaign, adGroup, ad } = {}) => adPlatformEntityUrl({
    platform: account?.platform,
    accountId: account?.external_id,
    campaignId: campaign?.external_id,
    adGroupId: adGroup?.external_id,
    adId: ad?.external_id,
  })
  const rows = level === 'account'
    ? (campaigns.data?.campaigns || []).map(row => ({ entity: row.campaign, metrics: row.metrics, platformUrl: platformUrlFor({ campaign: row.campaign }) }))
    : level === 'campaign'
      ? (groups.data?.ad_groups || []).map(row => ({ entity: row.ad_group, metrics: row.metrics, platformUrl: platformUrlFor({ campaign: groups.data?.campaign, adGroup: row.ad_group }) }))
      : level === 'group' ? (ads.data?.ads || []).map(row => ({ entity: row.ad, metrics: row.metrics, platformUrl: platformUrlFor({ campaign: ads.data?.campaign, adGroup: ads.data?.ad_group, ad: row.ad }) })) : []

  const selectedPlatformUrl = level === 'campaign'
    ? platformUrlFor({ campaign: payload?.campaign })
    : level === 'group'
      ? platformUrlFor({ campaign: payload?.campaign, adGroup: payload?.ad_group })
      : level === 'ad'
        ? platformUrlFor({ campaign: payload?.campaign, adGroup: payload?.ad_group, ad: selectedAd?.ad })
        : null

  const openChild = level === 'account'
    ? entity => navigate(campaignPerformancePath(clientId, accountId, entity.id, queryString))
    : level === 'campaign'
      ? entity => navigate(adGroupPerformancePath(clientId, accountId, campaignId, entity.id, queryString))
      : level === 'group' ? entity => navigate(adPerformancePath(clientId, accountId, campaignId, groupId, entity.id, queryString)) : null

  const accountPath = accountPerformancePath(clientId, accountId, queryString)
  const campaignPath = campaignId ? campaignPerformancePath(clientId, accountId, campaignId, queryString) : null
  const groupPath = groupId ? adGroupPerformancePath(clientId, accountId, campaignId, groupId, queryString) : null

  return <main className="performance-page drilldown-page">
    <nav className="performance-breadcrumbs" aria-label="Breadcrumb"><Link to={commandCenterPath}>Command Center</Link>{account && <><span>/</span><Link to={`/clients/${clientId}`}>{account.client?.name}</Link><span>/</span><Link to={accountPath}>{account.name}</Link></>}{payload?.campaign && level !== 'account' && <><span>/</span>{level === 'campaign' ? <strong>{payload.campaign.name}</strong> : <Link to={campaignPath}>{payload.campaign.name}</Link>}</>}{payload?.ad_group && <><span>/</span>{level === 'group' ? <strong>{payload.ad_group.name}</strong> : <Link to={groupPath}>{payload.ad_group.name}</Link>}</>}{selectedAd?.ad && <><span>/</span><strong>{selectedAd.ad.name}</strong></>}</nav>
    <header className="performance-page-head"><div><p className="ops-eyebrow">{level === 'account' ? `${account?.platform === 'google' ? 'Google Ads' : 'Meta'} account` : level === 'campaign' ? 'Campaign performance' : level === 'ad' ? 'Ad performance' : account?.platform === 'meta' ? 'Ad set performance' : 'Ad group performance'}</p><h1>{title || 'Loading performance…'}</h1><p>{dates.start_date} — {dates.end_date} · {account?.timezone}</p></div><div className="performance-page-actions">{selectedPlatformUrl && <a className="platform-open-link" href={selectedPlatformUrl} target="_blank" rel="noreferrer">Open in {platformLabel}<ExternalLink size={14}/></a>}<PerformancePeriodControl range={range} startDate={dates.start_date} endDate={dates.end_date} timezone={account?.timezone} onChange={updatePeriod}/></div></header>
    {level === 'account' && account && <div className="performance-context-links" role="tablist" aria-label="Account sections">{accountSections.map(([value, label, icon]) => <button key={value} role="tab" aria-selected={section === value} aria-controls="account-section-panel" className={section === value ? 'active' : ''} onClick={() => selectSection(value)}>{icon} {label}</button>)}</div>}
    {level === 'account' && account ? <div id="account-section-panel" className="account-section-panel" role="tabpanel">
      {section === 'details' && <>
        <AsyncStateNotice queries={activeQueries}/>
        <section className="performance-kpis">{metricColumns.map(column => <div key={column.key}><span>{column.label}</span><strong>{column.key === 'spend' || column.key === 'cpl' || column.key === 'cpp' ? formatMoney(metrics?.[column.key], account?.currency) : column.key === 'roas' ? formatRoas(metrics?.[column.key]) : formatNumber(metrics?.[column.key])}</strong></div>)}</section>
        <section className="performance-trend"><div className="performance-section-head"><div><h2>Spend trend</h2><p>Daily account spend in {account?.currency}</p></div></div><div className="performance-chart">{overview.data?.series?.length ? <ResponsiveContainer width="100%" height="100%"><AreaChart data={overview.data.series.map(item => ({ date: item.date.slice(5), spend: item.metrics.spend }))}><defs><linearGradient id="accountSpend" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#2563eb" stopOpacity=".2"/><stop offset="100%" stopColor="#2563eb" stopOpacity="0"/></linearGradient></defs><XAxis dataKey="date" axisLine={false} tickLine={false}/><YAxis axisLine={false} tickLine={false}/><Tooltip formatter={value => formatMoney(value, account?.currency)}/><Area type="monotone" dataKey="spend" stroke="#2563eb" strokeWidth={2} fill="url(#accountSpend)"/></AreaChart></ResponsiveContainer> : <div className="performance-empty-row">No trend data in this period.</div>}</div></section>
        <section className="performance-children"><div className="performance-section-head"><div><h2>{childLabel}</h2><p>{`Select ${childNoun === 'ad set' ? 'an' : 'a'} ${childNoun} to inspect the next level.`}</p></div></div><PerformanceMetricsTable label={childLabel.slice(0, -1)} rows={rows} currency={account?.currency} onOpen={openChild} platformLabel={platformLabel}/></section>
      </>}
      {section === 'alerts' && <ScopedAlerts clientId={clientId} accountId={account.id} limit={25}/>} 
      {section === 'data-health' && <ScopedDataHealth clientId={clientId} accountId={account.id} limit={25}/>} 
      {section === 'reports' && <ScopedReports clientId={clientId} accountId={account.id} limit={25}/>} 
      {section === 'connection' && <ScopedConnections clientId={clientId} accountId={account.id}/>} 
    </div> : <>
      <AsyncStateNotice queries={activeQueries}/>
      {level !== 'account' && <section className="performance-kpis">{metricColumns.map(column => <div key={column.key}><span>{column.label}</span><strong>{column.key === 'spend' || column.key === 'cpl' || column.key === 'cpp' ? formatMoney(metrics?.[column.key], account?.currency) : column.key === 'roas' ? formatRoas(metrics?.[column.key]) : formatNumber(metrics?.[column.key])}</strong></div>)}</section>}
      {level !== 'account' && level !== 'ad' && <section className="performance-children"><div className="performance-section-head"><div><h2>{childLabel}</h2><p>{level === 'group' ? 'Select an ad to inspect its performance.' : `Select ${childNoun === 'ad set' ? 'an' : 'a'} ${childNoun} to inspect the next level.`}</p></div><button onClick={() => navigate(-1)}><ArrowLeft size={14}/> Back</button></div><PerformanceMetricsTable label={childLabel.slice(0, -1)} rows={rows} currency={account?.currency} onOpen={openChild} platformLabel={platformLabel}/></section>}
    </>}
    {level === 'ad' && <section className="performance-children ad-detail-section"><div className="performance-section-head"><div><h2>Ad details</h2><p>Platform identity and hierarchy for this ad.</p></div><button onClick={() => navigate(-1)}><ArrowLeft size={14}/> Back to ads</button></div><dl className="ad-detail-grid"><div><dt>Status</dt><dd><span className="entity-status">{selectedAd?.ad?.status || 'Unknown'}</span></dd></div><div><dt>Format</dt><dd>{selectedAd?.ad?.type || 'Not reported'}</dd></div><div><dt>Platform</dt><dd>{platformLabel}</dd></div><div><dt>Ad ID</dt><dd className="mono">{selectedAd?.ad?.external_id || 'Not reported'}</dd></div><div><dt>Client</dt><dd>{account?.client?.name || 'Not reported'}</dd></div><div><dt>Ad account</dt><dd><Link to={accountPath}>{account?.name || 'Not reported'}</Link><small className="mono">{account?.external_id}</small></dd></div><div><dt>Campaign</dt><dd><Link to={campaignPath}>{payload?.campaign?.name || 'Not reported'}</Link><small className="mono">{payload?.campaign?.external_id}</small></dd></div><div><dt>{groupLabel}</dt><dd><Link to={groupPath}>{payload?.ad_group?.name || 'Not reported'}</Link><small className="mono">{payload?.ad_group?.external_id}</small></dd></div><div><dt>Currency</dt><dd>{account?.currency || 'Not reported'}</dd></div><div><dt>Reporting timezone</dt><dd>{account?.timezone || 'Not reported'}</dd></div></dl></section>}
  </main>
}
