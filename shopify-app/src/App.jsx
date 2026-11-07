import { useEffect, useState } from 'react'
import { merchantApi } from './api'
import './style.css'

const formatAmount = value => new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)

export default function App() {
  const [report, setReport] = useState(null)
  const [period, setPeriod] = useState('last_30_days')
  const [dates, setDates] = useState({ from: '', to: '' })
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [ready, setReady] = useState(false)
  const [busy, setBusy] = useState(false)
  const [linkToken, setLinkToken] = useState('')
  const [confirm, setConfirm] = useState(false)
  const [privacy, setPrivacy] = useState([])
  const [registration, setRegistration] = useState(null)
  const query = new URLSearchParams({ period, ...(period === 'custom' ? dates : {}) }).toString()
  useEffect(() => {
    let active = true
    merchantApi('session', { method: 'POST' }).then(result => {
      if (active) { setReady(true); setRegistration(result.registration); if (result.warning) setMessage(result.warning.replaceAll('_', ' ')) }
    }).catch(e => { if (active) setError(e.message) })
    return () => { active = false }
  }, [])
  useEffect(() => {
    if (!ready || (period === 'custom' && (!dates.from || !dates.to))) return undefined
    let active = true
    const load = () => merchantApi(`report?${query}`).then(data => { if (active) { setReport(data); setError('') } }).catch(e => { if (active) setError(e.message) })
    load()
    merchantApi('privacy').then(data => { if (active && Array.isArray(data)) setPrivacy(data) }).catch(() => {})
    const timer = setInterval(load, 15000)
    return () => { active = false; clearInterval(timer) }
  }, [ready, period, dates.from, dates.to, query])
  const action = async (path, body) => {
    setBusy(true); setError('')
    try { await merchantApi(path, { method: 'POST', body: JSON.stringify(body || {}) }); setMessage(path === 'disconnect' ? 'Synchronization stopped. You can uninstall SharkAds in Shopify settings.' : 'Request accepted.'); if (path === 'disconnect') setReady(false) }
    catch (e) { setError(e.message) }
    finally { setBusy(false) }
  }
  const status = !ready ? report ? 'disconnected' : 'connecting' : report?.connection?.status || 'connecting'
  const statusLabel = ({ active: 'Connected', connecting: 'Connecting…', disconnected: 'Disconnected', reconnect: 'Reconnect required', revoked: 'Disconnected' })[status] || status
  const lastSync = report?.coverage?.last_synced_at ? new Date(report.coverage.last_synced_at) : null
  const lastSyncLabel = lastSync && !Number.isNaN(lastSync.getTime()) ? new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: report.store.timezone }).format(lastSync) : 'Pending'
  return <s-page heading="Store performance">
    <main className="shop-page">
      <header className="shop-header">
        <div className="shop-identity"><span className="shop-brand-icon"><BagIcon/></span><div><span className="shop-brand">SharkAds</span><h1>{report?.store?.name || 'Store performance'}</h1>{report?.store?.domain && <span className="shop-domain">{report.store.domain}</span>}</div></div>
        <div className="shop-header-badges"><span className="shop-status" data-status={status}>{statusLabel}</span>{report?.store?.is_development && <span className="shop-dev-badge">Development store</span>}</div>
      </header>
      {error && <div className="shop-error"><p role="alert">{error}</p><button className="shop-button shop-secondary" onClick={() => window.location.reload()}>Reconnect / retry through Shopify</button></div>}
      {message && <p role="status" className="shop-notice">{message}</p>}
      <div className="shop-toolbar"><div className="shop-period-controls"><label><span>Reporting period</span><select value={period} onChange={e => setPeriod(e.target.value)}>
        <option value="yesterday">Yesterday</option><option value="last_7_days">Last 7 days</option><option value="month_to_date">Month to date</option><option value="last_30_days">Last 30 days</option><option value="all">All imported history</option><option value="custom">Custom dates</option>
      </select></label>{period === 'custom' && <><label><span>From</span><input type="date" value={dates.from} onChange={e => setDates({ ...dates, from: e.target.value })}/></label><label><span>To</span><input type="date" value={dates.to} onChange={e => setDates({ ...dates, to: e.target.value })}/></label></>}</div>
        <button className="shop-button shop-secondary" disabled={!ready || busy} aria-busy={busy} onClick={() => action('refresh')}><RefreshIcon/>Refresh data</button>
      </div>
      {!report && !error && <div className="shop-loading" role="status"><span/>Connecting to your store…</div>}
      {report && <>
        <section className="shop-metrics" aria-label="Store metrics">
          <article className="shop-metric"><span className="shop-metric-label">Orders</span><strong>{report.orders}</strong><span className="shop-metric-range">{report.range.from} – {report.range.to}</span></article>
          <article className="shop-metric shop-revenue"><span className="shop-metric-label">Net sales</span>{report.revenue.length ? report.revenue.map(block => <strong key={block.currency}>{formatAmount(block.net_sales)} {block.currency}</strong>) : <span className="shop-no-sales">No captured sales in this range.</span>}<span className="shop-metric-range">{report.store.timezone}</span></article>
        </section>
        {report.warnings.length > 0 && <div className="shop-warnings">{report.warnings.map(w => <p key={w}>{w}</p>)}</div>}
        <div className="shop-detail-grid">
          <section className="shop-card shop-sales"><header className="shop-card-head"><h2>Daily net sales</h2><span>{report.trend.length} {report.trend.length === 1 ? 'day' : 'days'}</span></header>
            {report.trend.length ? <div className="shop-table-wrap"><table><thead><tr><th scope="col">Date</th><th scope="col">Net sales</th></tr></thead><tbody>{report.trend.map(day => <tr key={day.date}><td><time dateTime={day.date}>{new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${day.date}T00:00:00Z`))}</time></td><td>{Object.entries(day.revenue).map(([currency, amount]) => <span key={currency}>{formatAmount(amount)} {currency}</span>)}</td></tr>)}</tbody></table></div> : <div className="shop-empty"><BagIcon/><span>No sales in this range</span></div>}
          </section>
          <section className="shop-card shop-sync"><header className="shop-card-head"><h2>Data coverage</h2><span className="shop-sync-dot" data-complete={report.coverage.complete}/></header><dl>
            <div><dt>Import</dt><dd>{report.connection.run?.stage === 'done' ? 'Up to date' : report.connection.run?.stage || 'Waiting for import'}</dd></div>
            <div><dt>Orders processed</dt><dd>{report.connection.run?.records || 0}</dd></div>
            <div><dt>Last successful sync</dt><dd>{lastSyncLabel}</dd></div>
            <div><dt>Timezone</dt><dd>{report.store.timezone}</dd></div>
          </dl></section>
        </div>
        {privacy.length > 0 && <section className="shop-card"><header className="shop-card-head"><h2>Data requests</h2></header><div className="shop-requests">{privacy.map(request => <div key={request.id}><span>{request.topic}</span><span>{request.status}</span>{request.status === 'export_ready' && <button className="shop-button shop-secondary" onClick={async () => {
          try {
            const data = await merchantApi(`privacy/${request.id}/export`)
            const url = URL.createObjectURL(new Blob([JSON.stringify(data)], { type: 'application/json' }))
            const link = document.createElement('a'); link.href = url; link.download = 'shopify-data.json'; link.click(); URL.revokeObjectURL(url)
          } catch (e) { setError(e.message) }
        }}>Download data export</button>}</div>)}</div></section>}
        {registration?.distribution === 'public' && <section className="shop-card"><header className="shop-card-head"><h2>Optional agency connection</h2></header><div className="shop-link-form"><label><span>Linking code</span><input value={linkToken} onChange={e => setLinkToken(e.target.value)} autoComplete="off"/></label><label className="shop-check"><input type="checkbox" checked={confirm} onChange={e => setConfirm(e.target.checked)}/>I authorize this store to be linked using this code.</label><button className="shop-button shop-primary" disabled={!confirm || linkToken.length !== 48 || busy} onClick={() => action('link', { token: linkToken, confirm })}>Confirm connection</button></div></section>}
      </>}
      <footer className="shop-footer"><div><a href="/privacy-policy" target="_blank" rel="noreferrer">Privacy policy</a><a href="mailto:design@brandshark.com">Get help</a></div><button className="shop-stop" disabled={!ready || busy} onClick={() => { if (window.confirm('Stop synchronization for this store?')) action('disconnect') }}>Stop synchronization</button></footer>
    </main>
  </s-page>
}

function BagIcon() {
  return <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M6 7h12l2 14H4L6 7Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
}
function RefreshIcon() {
  return <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M20 7v5h-5"/><path d="M4 17v-5h5"/><path d="M6.1 7a7 7 0 0 1 11.6-2L20 8M4 16l2.3 3A7 7 0 0 0 18 17"/></svg>
}
