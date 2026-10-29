import { useQuery } from '@tanstack/react-query'
import { Activity, AlertTriangle, CalendarDays, CheckCircle2, Clock3, Printer, ShieldCheck } from 'lucide-react'
import { useParams } from 'react-router-dom'
import client from '../api/client'

const METRICS = [['spend', 'Spend'], ['leads', 'Leads'], ['purchases', 'Purchases'], ['conversions', 'Conversions'], ['roas', 'ROAS'], ['cpl', 'Cost per lead']]

function formatNumber(value, maximumFractionDigits = 2) {
  if (value == null || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return number.toLocaleString(undefined, { maximumFractionDigits })
}

function formatMoney(value, currency) {
  if (value == null || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: number >= 1000 ? 0 : 2 }).format(number)
  } catch {
    return `${currency} ${formatNumber(number)}`
  }
}

function formatMetric(key, value, currency) {
  if (['spend', 'cpl', 'cpa', 'cpc', 'cpm', 'conversion_value'].includes(key)) return formatMoney(value, currency)
  if (key === 'roas') return value == null ? '—' : `${formatNumber(value)}×`
  if (['ctr', 'conversion_rate', 'frequency'].includes(key)) return value == null ? '—' : `${formatNumber(value)}%`
  return formatNumber(value, 1)
}

function formatDate(value, options = {}) {
  if (!value) return '—'
  const date = new Date(`${value}`.length === 10 ? `${value}T00:00:00` : value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, options)
}

function metricDelta(current, previous) {
  const now = Number(current); const before = Number(previous)
  if (!Number.isFinite(now) || !Number.isFinite(before) || before === 0) return null
  const value = ((now - before) / Math.abs(before)) * 100
  return `${value >= 0 ? '+' : ''}${formatNumber(value, 1)}% vs prior`
}

function SpendTrend({ series, currency }) {
  const values = series.map(item => Number(item.metrics?.spend || 0))
  const max = Math.max(...values, 1)
  const width = 720
  const height = 190
  const padding = 12
  const points = values.map((value, index) => {
    const x = values.length === 1 ? width / 2 : padding + (index / (values.length - 1)) * (width - padding * 2)
    const y = height - padding - (value / max) * (height - padding * 2)
    return [x, y]
  })
  const path = points.map(point => point.join(',')).join(' ')
  const area = points.length ? `${padding},${height - padding} ${path} ${width - padding},${height - padding}` : ''

  if (!series.length) return <div className="public-report-empty">No daily trend data was available for this period.</div>

  return <div className="public-report-trend">
    <div className="public-report-chart-scale"><span>{formatMoney(max, currency)}</span><span>{formatMoney(0, currency)}</span></div>
    <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`Daily spend trend in ${currency}`} preserveAspectRatio="none">
      <line x1="12" y1="178" x2="708" y2="178" stroke="#dbe5f0" strokeWidth="1" />
      {area && <polygon points={area} fill="#2563eb" opacity=".08" />}
      <polyline points={path} fill="none" stroke="#2563eb" strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
      {points.map(([x, y], index) => <circle key={series[index].date} cx={x} cy={y} r="3.5" fill="#fff" stroke="#2563eb" strokeWidth="2" vectorEffect="non-scaling-stroke"><title>{formatDate(series[index].date, { month: 'short', day: 'numeric' })}: {formatMoney(values[index], currency)}</title></circle>)}
    </svg>
    <div className="public-report-chart-dates"><span>{formatDate(series[0]?.date, { month: 'short', day: 'numeric' })}</span><span>{formatDate(series.at(-1)?.date, { month: 'short', day: 'numeric' })}</span></div>
  </div>
}

function CampaignTable({ campaigns, currency }) {
  const rows = campaigns.filter(row => row.currency === currency).sort((a, b) => Number(b.metrics?.spend || 0) - Number(a.metrics?.spend || 0))
  if (!rows.length) return <div className="public-report-empty">No campaign-level data was recorded for this currency.</div>

  return <div className="public-report-table-wrap"><table className="public-report-table">
    <thead><tr><th>Campaign</th><th>Spend</th><th>Leads</th><th>Conversions</th><th>CPA</th><th>ROAS</th></tr></thead>
    <tbody>{rows.map((row, index) => <tr key={row.campaign?.id || `${currency}-${index}`}>
      <td><strong>{row.campaign?.name || 'Unnamed campaign'}</strong><small>{[row.campaign?.type, row.campaign?.status].filter(Boolean).join(' · ') || 'Campaign'}</small></td>
      <td>{formatMoney(row.metrics?.spend, currency)}</td><td>{formatNumber(row.metrics?.leads, 1)}</td><td>{formatNumber(row.metrics?.conversions, 1)}</td><td>{formatMoney(row.metrics?.cpa, currency)}</td><td>{formatMetric('roas', row.metrics?.roas, currency)}</td>
    </tr>)}</tbody>
  </table></div>
}

function ReportState({ error = false }) {
  return <main className="public-report-state"><div className="public-report-mark"><Activity size={20} /></div>{error ? <><h1>This report is unavailable</h1><p>The share link may be invalid or expired. Ask your SharkAds administrator for a fresh link.</p></> : <><span className="public-report-loader" /><h1>Preparing your report</h1><p>Loading the latest performance snapshot…</p></>}</main>
}

export default function PublicPerformanceReportPage() {
  const { token } = useParams()
  const query = useQuery({ queryKey: ['public-performance-report', token], queryFn: () => client.get(`/api/public/performance-reports/${token}`).then(response => response.data), retry: false })
  if (query.isLoading) return <ReportState />
  if (query.isError || !query.data?.snapshot) return <ReportState error />

  const report = query.data
  const snapshot = report.snapshot
  const currencies = snapshot.summary?.currencies || []
  const trendSeries = snapshot.trends?.series || []
  const campaigns = snapshot.campaigns?.campaigns || []
  const alerts = snapshot.alerts || []
  const branding = snapshot.template || {}
  const brandName = branding.brand_name || 'SharkAds'
  const openAlerts = alerts.filter(alert => !['resolved', 'closed'].includes(`${alert.status}`.toLowerCase()))

  return <div className="public-report-page" style={{ '--report-accent': branding.brand_color || '#2563eb' }}>
    <header className="public-report-hero"><div className="public-report-hero-inner">
      <div className="public-report-brand"><span>{branding.logo_url ? <img src={branding.logo_url} alt=""/> : <Activity size={17} />}</span><div><strong>{brandName}</strong><small>Performance intelligence</small></div></div>
      <div className="public-report-badge"><ShieldCheck size={14} /> Client report</div>
      <div className="public-report-intro"><p>{report.frequency} performance report</p><h1>{snapshot.client?.name || 'Performance report'}</h1><div><span><CalendarDays size={14} /> {formatDate(report.period_start, { month: 'short', day: 'numeric', year: 'numeric' })} — {formatDate(report.period_end, { month: 'short', day: 'numeric', year: 'numeric' })}</span><span><Clock3 size={14} /> Generated {formatDate(report.generated_at, { month: 'short', day: 'numeric', year: 'numeric' })}</span></div></div>
    </div></header>

    <main className="public-report-content">
      <section className="public-report-summary-row"><div><p className="public-report-kicker">Executive snapshot</p><h2>Performance at a glance</h2><p>Results are presented separately by currency to keep every total accurate.</p></div><button type="button" onClick={() => window.print()}><Printer size={15} /> Print / save PDF</button></section>
      {!currencies.length && <div className="public-report-empty large">No performance data was available for this reporting period.</div>}

      {currencies.map((block, currencyIndex) => {
        const currency = block.currency || snapshot.client?.currency || 'USD'
        const series = trendSeries.filter(row => row.currency === currency)
        const previous = snapshot.comparison?.summary?.currencies?.find(item => item.currency === currency)?.metrics || {}
        return <section className="public-report-currency" key={currency}>
          <div className="public-report-section-heading"><div><span>{String(currencyIndex + 1).padStart(2, '0')}</span><div><p>{currency} portfolio</p><h2>Performance overview</h2></div></div><p>Data through {formatDate(snapshot.summary?.freshness_timestamp, { month: 'short', day: 'numeric', year: 'numeric' })}</p></div>
          <div className="public-report-kpis">{METRICS.map(([key, label]) => <div key={key} className="public-report-kpi"><span>{label}</span><strong>{formatMetric(key, block.metrics?.[key], currency)}</strong>{metricDelta(block.metrics?.[key], previous[key]) && <small>{metricDelta(block.metrics?.[key], previous[key])}</small>}</div>)}</div>
          <div className="public-report-section-block"><div className="public-report-block-title"><div><p>Daily movement</p><h3>Spend trend</h3></div><span>{series.length} reporting days</span></div><SpendTrend series={series} currency={currency} /></div>
          <div className="public-report-section-block campaigns"><div className="public-report-block-title"><div><p>Campaign performance</p><h3>Where results came from</h3></div><span>{campaigns.filter(row => row.currency === currency).length} campaigns</span></div><CampaignTable campaigns={campaigns} currency={currency} /></div>
        </section>
      })}

      <section className="public-report-alerts">
        <div className="public-report-section-heading compact"><div><span><AlertTriangle size={15} /></span><div><p>Monitoring</p><h2>Performance signals</h2></div></div><p>{openAlerts.length ? `${openAlerts.length} requiring attention` : 'No open signals'}</p></div>
        {!alerts.length ? <div className="public-report-all-clear"><CheckCircle2 size={20} /><div><strong>No alerts were recorded</strong><p>No reportable performance signals occurred during this period.</p></div></div> : <div className="public-report-alert-list">{alerts.map((alert, index) => {
          const resolved = ['resolved', 'closed'].includes(`${alert.status}`.toLowerCase())
          return <div key={`${alert.title}-${index}`}><i data-severity={resolved ? 'resolved' : alert.severity} /><div><strong>{alert.title}</strong><small>{formatDate(alert.triggered_at, { month: 'short', day: 'numeric' })}{resolved && alert.resolved_at ? ` · Resolved ${formatDate(alert.resolved_at, { month: 'short', day: 'numeric' })}` : ''}</small></div><span className={resolved ? 'resolved' : ''}>{resolved ? 'Resolved' : 'Open'}</span></div>
        })}</div>}
      </section>

      <footer className="public-report-footer"><div className="public-report-brand dark"><span>{branding.logo_url ? <img src={branding.logo_url} alt=""/> : <Activity size={16} />}</span><div><strong>{brandName}</strong><small>Prepared for {snapshot.client?.name}</small></div></div><p>This immutable snapshot excludes internal notes and operational diagnostics. Conversion results may change as advertising platforms complete attribution. Link expires {formatDate(report.expires_at, { month: 'short', day: 'numeric', year: 'numeric' })}.</p></footer>
    </main>
  </div>
}
