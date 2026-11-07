import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Clock3, RefreshCw, ShoppingBag } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../context/AuthContext'
import './CommercePanel.css'

const formatAmount = value => new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)

export default function CommercePanel({ clientId }) {
  const { user, features } = useAuth()
  const qc = useQueryClient()
  const [period, setPeriod] = useState('last_30_days')
  const [dates, setDates] = useState({ from: '', to: '' })
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const enabled = Boolean(features?.shopify)
  const connection = useQuery({ queryKey: ['shopify-connection', clientId], queryFn: () => api.getShopifyConnection(clientId).then(r => r.data), enabled })
  const report = useQuery({ queryKey: ['shopify-report', clientId, period, dates], queryFn: () => api.getShopifyReport(clientId, { period, ...(period === 'custom' ? dates : {}) }).then(r => r.data), enabled: enabled && (period !== 'custom' || Boolean(dates.from && dates.to)), refetchInterval: 15000 })
  const perform = async fn => {
    setBusy(true); setError(''); setMessage('')
    try { await fn(); await qc.invalidateQueries({ queryKey: ['shopify-connection', clientId] }); await qc.invalidateQueries({ queryKey: ['shopify-report', clientId] }) }
    catch (e) { setError(e.response?.data?.message || 'Shopify request failed.') }
    finally { setBusy(false) }
  }
  const connect = () => {
    const tab = window.open('about:blank', '_blank')
    if (!tab) { setError('Allow pop-ups to connect Shopify.'); return }
    tab.opener = null
    perform(async () => {
      try {
        const response = await api.connectShopify(clientId)
        tab.location.replace(response.data.url)
      } catch (e) { tab.close(); throw e }
    })
  }
  if (!enabled) return <p>Shopify integration is not enabled.</p>
  const data = report.data
  const store = connection.data?.store || data?.store
  const identity = data?.store || store
  const status = data?.connection?.status
  const connected = Boolean(identity)
  const statusLabel = connection.isPending ? 'Loading…' : !connected ? 'Not connected' : status === 'active' ? 'Connected' : status === 'reconnect' ? 'Reconnect required' : status || 'Connected'
  const syncDate = data?.coverage?.last_synced_at ? new Date(data.coverage.last_synced_at) : null
  const syncLabel = syncDate && !Number.isNaN(syncDate.getTime()) ? new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: identity?.timezone || 'UTC' }).format(syncDate) : 'Pending'
  return <section className="commerce-panel" aria-label="Shopify connection">
    <header className="commerce-card-head">
      <div className="commerce-brand"><span className="commerce-brand-icon"><ShoppingBag size={19}/></span><div><h2>Shopify</h2>{identity?.name && <p>{identity.name}</p>}</div></div>
      <span className="commerce-status" data-status={connected ? status || 'active' : 'disconnected'}>{statusLabel}</span>
    </header>
    {identity?.is_development && <span className="commerce-dev-badge">Development store</span>}
    {error && <p role="alert" className="commerce-warning">{error}</p>}{message && <p role="status" className="commerce-message">{message}</p>}
    {(report.isError || connection.isError) && <p role="alert" className="commerce-warning">Shopify data could not be loaded.</p>}
    {data?.store && <>
      <div className="commerce-toolbar"><label><span className="commerce-sr-only">Period</span><select value={period} onChange={e => setPeriod(e.target.value)}>{[['yesterday','Yesterday'],['last_7_days','Last 7 days'],['month_to_date','Month to date'],['last_30_days','Last 30 days'],['all','All imported history'],['custom','Custom dates']].map(([v,l]) => <option key={v} value={v}>{l}</option>)}</select></label>
        {user?.role === 'admin' && store && <button className="commerce-refresh" disabled={busy} onClick={() => perform(async () => { await api.refreshShopify(clientId); setMessage('Synchronization queued.') })}><RefreshCw size={13}/>Refresh store</button>}
      </div>
      {period === 'custom' && <div className="commerce-dates"><label>From<input type="date" value={dates.from} onChange={e => setDates({ ...dates, from: e.target.value })}/></label><label>To<input type="date" value={dates.to} onChange={e => setDates({ ...dates, to: e.target.value })}/></label></div>}
      <div className="commerce-metrics"><div><span>Orders</span><strong>{data.orders}</strong></div><div><span>Net sales</span><strong>{data.revenue.map(b => <span className="commerce-money" key={b.currency}>{formatAmount(b.net_sales)} <small>{b.currency}</small></span>)}</strong>{!data.revenue.length && <span className="commerce-no-sales">No captured sales</span>}</div></div>
      <div className="commerce-range">{data.range.from} – {data.range.to}<span>{data.store.timezone}</span></div>
      {data.warnings.map(w => <p className="commerce-warning" key={w}>{w}</p>)}
      {data.trend.length > 0 && <details className="commerce-history"><summary>Daily sales</summary><div className="commerce-table"><table><thead><tr><th>Date</th><th>Net sales</th></tr></thead><tbody>{data.trend.map(day => <tr key={day.date}><td>{day.date}</td><td>{Object.entries(day.revenue).map(([c,a]) => `${formatAmount(a)} ${c}`).join(' / ')}</td></tr>)}</tbody></table></div></details>}
      <footer className="commerce-footer"><span><Clock3 size={12}/>Last sync: {syncLabel}</span>{user?.role === 'admin' && store && <details className="commerce-manage"><summary>Manage</summary><button className="commerce-disconnect" disabled={busy} onClick={() => { if (window.confirm('Disconnect this store from the client and stop synchronization?')) perform(() => api.disconnectShopify(clientId)) }}>Disconnect store</button></details>}</footer>
    </>}
    {report.isPending && connected && <p role="status" className="commerce-message">Loading store data…</p>}
    {user?.role === 'admin' && (!store || (status && status !== 'active')) && <button className="ops-primary commerce-connect" disabled={busy || connection.isPending} onClick={connect}>Connect Shopify</button>}
  </section>
}
