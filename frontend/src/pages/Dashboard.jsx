import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowUpRight, Clock3, DatabaseZap, Plus, RefreshCw } from 'lucide-react'
import { api } from '../api/client'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import { useAuth } from '../context/AuthContext'
import { formatMoney, formatNumber } from '../lib/performance'
import { accountPerformancePath } from '../lib/performanceRoutes'

const WINDOWS = [['yesterday', 'Yesterday'], ['last_7_days', '7 days'], ['last_30_days', '30 days']]

export default function Dashboard() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [refreshPolling, setRefreshPolling] = useState(null)
  const [refreshOverrideAvailableAt, setRefreshOverrideAvailableAt] = useState(null)
  const [countdownClock, setCountdownClock] = useState(() => Date.now())
  const accountQuery = useQuery({ queryKey: ['performance-accounts-command-center'], queryFn: () => api.getPerformanceAccounts().then(response => response.data), refetchInterval: refreshPolling ? 5_000 : 60_000 })
  const accounts = accountQuery.data?.accounts || []
  const forceRefresh = useMutation({
    mutationFn: () => api.refreshPerformanceAccounts(),
    onSuccess: response => {
      setRefreshOverrideAvailableAt(response.data.refresh?.available_at || null)
      setCountdownClock(Date.now())
      setRefreshPolling({ accountCount: response.data.queued_accounts })
      accountQuery.refetch()
    },
    onError: error => {
      setRefreshOverrideAvailableAt(error.response?.data?.refresh?.available_at || null)
      setCountdownClock(Date.now())
    },
  })
  const refreshAvailableAt = latestCooldown(accountQuery.data?.refresh?.available_at, refreshOverrideAvailableAt)
  const cooldownSeconds = secondsUntil(refreshAvailableAt, countdownClock)
  useEffect(() => {
    if (!refreshAvailableAt || cooldownSeconds === 0) return undefined
    const interval = window.setInterval(() => setCountdownClock(Date.now()), 1_000)
    return () => window.clearInterval(interval)
  }, [cooldownSeconds, refreshAvailableAt])
  useEffect(() => {
    if (!refreshPolling) return undefined
    const timeout = window.setTimeout(() => setRefreshPolling(null), 2 * 60_000)
    return () => window.clearTimeout(timeout)
  }, [refreshPolling])

  if (!accountQuery.isLoading && accountQuery.data && accounts.length === 0) return <div className="performance-empty-page"><p className="ops-eyebrow">Command center</p><h1>No connected ad accounts yet.</h1><p>Connect Google Ads or Meta, then assign at least one account to begin collecting performance.</p>{user?.role === 'admin' && <button onClick={() => navigate('/connections')} className="ops-primary"><Plus size={16}/> Connect an account</button>}</div>

  const coolingDown = cooldownSeconds > 0
  const refreshLabel = forceRefresh.isPending ? 'Starting…' : coolingDown ? `Refresh in ${formatCountdown(cooldownSeconds)}` : 'Refresh'
  const refreshFailed = forceRefresh.isError && forceRefresh.error?.response?.status !== 429

  return <main className="performance-page">
    <header className="performance-page-head fade-up"><div><p className="ops-eyebrow">Command center</p><h1>Client performance</h1><p>One Google Ads row and one Meta row per client, showing the higher-spend result type.</p></div><button className="performance-refresh" disabled={forceRefresh.isPending || coolingDown} onClick={() => forceRefresh.mutate()}><RefreshCw className={forceRefresh.isPending ? 'spin' : ''} size={14}/>{refreshLabel}</button></header>
    <div className="performance-status-line fade-up-delay-1" aria-live="polite"><span><DatabaseZap size={14}/> {accounts.length} client-platform views</span><span><Clock3 size={14}/> Windows end yesterday in each account timezone</span>{refreshPolling && <span className="performance-refresh-state">Refresh queued for {refreshPolling.accountCount} {refreshPolling.accountCount === 1 ? 'account' : 'accounts'}; updating automatically.</span>}{refreshFailed && <span className="performance-refresh-state error">Refresh could not be started.</span>}</div>
    <AsyncStateNotice queries={[accountQuery]} partialText="Account performance could not be refreshed."/>
    <section className="performance-table-shell fade-up-delay-2" aria-label="Ad account performance"><div className="performance-table-scroll"><table className="performance-table account-performance-table">
      <thead><tr className="period-group-row"><th rowSpan="2">Client</th><th rowSpan="2">Platform</th><th rowSpan="2">Result type</th>{WINDOWS.map(([, label]) => <th key={label} colSpan="3" className="period-group-heading">{label}</th>)}<th rowSpan="2" aria-label="Open account"/></tr><tr className="period-metric-row">{WINDOWS.flatMap(([key]) => [<th key={`${key}-results`}>Results</th>, <th key={`${key}-cpr`}>CPR</th>, <th key={`${key}-spend`}>Spend</th>])}</tr></thead>
      <tbody>{accounts.map(account => <AccountResultRow key={`${account.client?.id}-${account.platform}-${account.currency}`} account={account} onOpen={() => navigate(accountPerformancePath(account.client?.id, account.id))}/>)}</tbody>
    </table></div>{accountQuery.isPending && <div className="performance-table-loading skeleton" aria-label="Account performance loading"/>}</section>
  </main>
}

function latestCooldown(...values) {
  return values.filter(Boolean).sort((left, right) => Date.parse(right) - Date.parse(left))[0] || null
}

function secondsUntil(availableAt, currentTime = Date.now()) {
  const timestamp = Date.parse(availableAt)
  return Number.isFinite(timestamp) ? Math.max(0, Math.ceil((timestamp - currentTime) / 1_000)) : 0
}

function formatCountdown(seconds) {
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

function AccountResultRow({ account, onOpen }) {
  const type = account.result_type === 'sales' ? 'sales' : 'leads'
  return <tr tabIndex={0} onClick={onOpen} onKeyDown={event => ['Enter', ' '].includes(event.key) && onOpen()}>
    <td className="sticky-column"><span className="account-identity"><strong>{account.client?.name}</strong><small>{account.account_count || 1} {(account.account_count || 1) === 1 ? 'account' : 'accounts'} · {account.currency}</small></span></td><td><span className={`platform-badge ${account.platform}`}>{account.platform === 'google' ? 'Google Ads' : 'Meta'}</span></td><td><span className={`result-type ${type}`}>{type === 'sales' ? 'Sales' : 'Leads'}</span></td>
    {WINDOWS.flatMap(([key]) => { const metrics = account.windows?.[key] || {}; return [<td key={`${key}-result`} className="numeric-cell">{formatNumber(metrics.results)}</td>, <td key={`${key}-cpr`} className="numeric-cell">{formatMoney(metrics.cpr, account.currency)}</td>, <td key={`${key}-spend`} className="numeric-cell period-spend-cell">{formatMoney(metrics.spend, account.currency)}</td>] })}<td><ArrowUpRight size={14}/></td>
  </tr>
}
