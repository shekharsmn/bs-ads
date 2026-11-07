import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Download } from 'lucide-react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api/client'
import PerformanceMetricsTable from '../components/PerformanceMetricsTable'
import PerformancePeriodControl from '../components/PerformancePeriodControl'
import PerformanceTrendChart from '../components/PerformanceTrendChart'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import { useAuth } from '../context/AuthContext'
import { completedDateRange, formatMoney, formatNumber, formatRoas, metricColumnsFor, objectiveMetricKeys } from '../lib/performance'
import { PortalShell } from './ClientPortalPage'

export default function ClientPortalDrilldownPage({ preview = false }) {
  const { clientId, accountId, campaignId, groupId, adId } = useParams(); const navigate = useNavigate(); const { logout } = useAuth(); const [search, setSearch] = useSearchParams()
  const clients = useQuery({ queryKey: ['portal-clients'], queryFn: () => api.getPortalClients().then(response => response.data) })
  const accountQuery = useQuery({ queryKey: ['portal-account', accountId], queryFn: () => api.getPortalAccount(accountId).then(response => response.data) })
  const account = accountQuery.data
  const range = search.get('range') || '30d'; const preset = completedDateRange(Number(range === 'custom' ? 30 : range.replace('d', '')), account?.timezone)
  const dates = { start_date: search.get('start_date') || preset.start_date, end_date: search.get('end_date') || preset.end_date }; const query = new URLSearchParams({ range, ...dates }).toString()
  const level = adId ? 'ad' : groupId ? 'group' : campaignId ? 'campaign' : 'account'
  const clientBase = preview ? '/portal/preview/clients' : '/portal/clients'
  const overview = useQuery({ queryKey: ['portal-overview', accountId, dates], queryFn: () => api.getPortalAccountPerformance(accountId, dates).then(r => r.data), enabled: Boolean(account) && level === 'account' })
  const campaigns = useQuery({ queryKey: ['portal-campaigns', accountId, dates], queryFn: () => api.getPortalAccountCampaigns(accountId, dates).then(r => r.data), enabled: Boolean(account) && level === 'account' })
  const groups = useQuery({ queryKey: ['portal-groups', accountId, campaignId, dates], queryFn: () => api.getPortalCampaignAdGroups(accountId, campaignId, dates).then(r => r.data), enabled: level === 'campaign' })
  const ads = useQuery({ queryKey: ['portal-ads', accountId, campaignId, groupId, dates], queryFn: () => api.getPortalAdGroupAds(accountId, campaignId, groupId, dates).then(r => r.data), enabled: level === 'group' })
  const ad = useQuery({ queryKey: ['portal-ad', accountId, campaignId, groupId, adId, dates], queryFn: () => api.getPortalAdPerformance(accountId, campaignId, groupId, adId, dates).then(r => r.data), enabled: level === 'ad' })
  const payload = level === 'account' ? overview.data : level === 'campaign' ? groups.data : level === 'group' ? ads.data : ad.data
  const title = level === 'account' ? account?.name : level === 'campaign' ? payload?.campaign?.name : level === 'group' ? payload?.ad_group?.name : payload?.ad?.name
  const metrics = payload?.metrics; const series = payload?.series; const columns = metricColumnsFor(objectiveMetricKeys(account?.client?.objective))
  const rows = level === 'account' ? (campaigns.data?.campaigns || []).map(row => ({ entity: row.campaign, metrics: row.metrics })) : level === 'campaign' ? (groups.data?.ad_groups || []).map(row => ({ entity: row.ad_group, metrics: row.metrics })) : level === 'group' ? (ads.data?.ads || []).map(row => ({ entity: row.ad, metrics: row.metrics })) : []
  const childLabel = level === 'account' ? 'Campaigns' : level === 'campaign' ? (account?.platform === 'meta' ? 'Ad sets' : 'Ad groups') : 'Ads'
  const openChild = entity => navigate(level === 'account' ? `${clientBase}/${clientId}/accounts/${accountId}/campaigns/${entity.id}?${query}` : level === 'campaign' ? `${clientBase}/${clientId}/accounts/${accountId}/campaigns/${campaignId}/ad-groups/${entity.id}?${query}` : `${clientBase}/${clientId}/accounts/${accountId}/campaigns/${campaignId}/ad-groups/${groupId}/ads/${entity.id}?${query}`)
  const updatePeriod = next => setSearch(current => { const merged = new URLSearchParams(current); Object.entries(next).forEach(([key, value]) => value ? merged.set(key, value) : merged.delete(key)); return merged }, { replace: true })
  const exportBase = level === 'account' ? `/api/portal/accounts/${accountId}/export` : level === 'campaign' ? `/api/portal/accounts/${accountId}/campaigns/${campaignId}/export` : level === 'group' ? `/api/portal/accounts/${accountId}/campaigns/${campaignId}/ad-groups/${groupId}/export` : `/api/portal/accounts/${accountId}/campaigns/${campaignId}/ad-groups/${groupId}/ads/${adId}/export`
  const exportUrl = format => `${exportBase}/${format}?${new URLSearchParams(dates)}`
  const activeQueries = [accountQuery, ...(level === 'account' ? [overview, campaigns] : level === 'campaign' ? [groups] : level === 'group' ? [ads] : [ad])]

  return <PortalShell clients={clients.data || []} clientId={clientId} logout={logout} preview={preview}><main className="portal-page"><AsyncStateNotice queries={activeQueries}/>
    <nav className="performance-breadcrumbs"><Link to={`${clientBase}/${clientId}`}>Performance</Link><span>/</span><Link to={`${clientBase}/${clientId}/accounts/${accountId}?${query}`}>{account?.name || 'Account'}</Link>{payload?.campaign && <><span>/</span><span>{payload.campaign.name}</span></>}{payload?.ad_group && <><span>/</span><span>{payload.ad_group.name}</span></>}{payload?.ad && <><span>/</span><strong>{payload.ad.name}</strong></>}</nav>
    <header className="portal-page-head"><div><p>{level === 'account' ? account?.visibility_mode === 'selected_campaigns' ? 'Selected campaigns' : 'Full account' : `${level} performance`}</p><h1>{title || 'Loading performance…'}</h1><span>{dates.start_date} — {dates.end_date} · {account?.timezone}</span></div><div className="portal-actions"><a href={exportUrl('pdf')}><Download size={14}/>PDF report</a><a href={exportUrl('xlsx')}><Download size={14}/>Excel table</a><a href={exportUrl('csv')}><Download size={14}/>CSV table</a><PerformancePeriodControl range={range} startDate={dates.start_date} endDate={dates.end_date} timezone={account?.timezone} onChange={updatePeriod}/></div></header>
    <section className="performance-kpis">{columns.map(column => <div key={column.key}><span>{column.label}</span><strong>{['spend','cpl','cpp'].includes(column.key) ? formatMoney(metrics?.[column.key], account?.currency) : column.key === 'roas' ? formatRoas(metrics?.[column.key]) : formatNumber(metrics?.[column.key])}</strong></div>)}</section>
    <PerformanceTrendChart series={series || []} currency={account?.currency} columns={columns}/>
    {level !== 'ad' && <section className="performance-children"><div className="performance-section-head"><div><h2>{childLabel}</h2><p>Open a row to inspect the next permitted level.</p></div>{level !== 'account' && <button onClick={() => navigate(-1)}><ArrowLeft size={14}/>Back</button>}</div><PerformanceMetricsTable label={childLabel.slice(0, -1)} rows={rows} currency={account?.currency} onOpen={openChild} metricColumns={columns}/></section>}
  </main></PortalShell>
}
