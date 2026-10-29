import { createElement, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { Activity, AlertTriangle, BarChart3, Cable, ClipboardCheck, FileText, LibraryBig, Settings2, Trash2 } from 'lucide-react'
import { api } from '../api/client'
import { AsyncStateNotice } from '../components/ui/AsyncState'
import { ScopedAlerts, ScopedConnections, ScopedDataHealth, ScopedReports } from '../components/ScopedOperations'
import { accountPerformancePath } from '../lib/performanceRoutes'
import ConfirmDialog from '../components/ui/ConfirmDialog'
import { useAuth } from '../context/AuthContext'
import { useToast } from '../context/ToastContext'

const tabs = [
  ['overview', 'Overview', BarChart3], ['accounts', 'Ad accounts', Settings2], ['connections', 'Connections', Cable],
  ['health', 'Alerts & data health', Activity], ['library', 'Ad library', LibraryBig], ['audits', 'Content audits', ClipboardCheck], ['reports', 'Reports', FileText],
]

export default function ClientHubPage() {
  const { clientId } = useParams()
  const { user } = useAuth()
  const toast = useToast()
  const queryClient = useQueryClient()
  const [search, setSearch] = useSearchParams()
  const [pendingDelete, setPendingDelete] = useState(null)
  const active = search.get('tab') || 'overview'
  const client = useQuery({ queryKey: ['client', clientId], queryFn: () => api.getClient(clientId).then(response => response.data) })
  const library = useQuery({ queryKey: ['ad-library-searches', clientId], queryFn: () => api.getAdLibrarySearches({ related_client_id: clientId }).then(response => response.data), enabled: active === 'library' })
  const audits = useQuery({ queryKey: ['ad-library-audits', clientId], queryFn: () => api.getAdLibraryAudits({ related_client_id: clientId, per_page: 25 }).then(response => response.data), enabled: active === 'audits' })
  const selectTab = tab => setSearch(tab === 'overview' ? {} : { tab }, { replace: true })
  const accountCount = client.data?.ad_accounts?.length || 0
  const alertCount = client.data?.incidents_count || 0
  const removeAccount = useMutation({
    mutationFn: account => api.deleteAdAccount(account.id),
    onSuccess: async () => {
      setPendingDelete(null)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['client', clientId] }),
        queryClient.invalidateQueries({ queryKey: ['clients'] }),
        queryClient.invalidateQueries({ queryKey: ['performance-accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['connections'] }),
      ])
      toast.success('Ad account deleted.')
    },
    onError: error => toast.error(error.response?.data?.message || 'The ad account could not be deleted.'),
  })

  return <main className="client-hub-page">
    <AsyncStateNotice queries={[client]}/>
    <header className="client-hub-head"><div><p className="ops-eyebrow">Client operating hub</p><h1>{client.data?.name || 'Loading client…'}</h1><p>{client.data ? `${client.data.timezone} · ${client.data.currency} · ${client.data.objective.replace('_', ' ')}` : 'Accounts, delivery, research, and operational health in one workspace.'}</p></div><Link className="client-hub-back" to="/clients">All clients</Link></header>
    <section className="client-hub-stats"><div><span>Ad accounts</span><strong>{accountCount}</strong></div><div><span>Open alerts</span><strong>{alertCount}</strong></div><div><span>Report schedules</span><strong>{client.data?.report_schedules?.length || 0}</strong></div><div><span>Status</span><strong>{client.data?.active ? 'Active' : 'Paused'}</strong></div></section>
    <nav className="client-hub-tabs" aria-label="Client sections">{tabs.map(([key, label, icon]) => <button key={key} className={active === key ? 'active' : ''} onClick={() => selectTab(key)}>{createElement(icon, { size: 14 })}{label}</button>)}</nav>

    {active === 'overview' && <div className="client-overview-grid"><ScopedAlerts clientId={clientId} limit={5}/><ScopedDataHealth clientId={clientId} limit={5}/><ScopedConnections clientId={clientId}/><ScopedReports clientId={clientId} limit={4}/></div>}
    {active === 'accounts' && <section className="client-hub-section"><SectionHead title="Connected ad accounts" subtitle="Open an account to inspect performance and its contextual operations."/>{(client.data?.ad_accounts || []).map(account => <article className="client-account-row" key={account.id}><i data-platform={account.platform}/><Link className="client-account-main" to={accountPerformancePath(clientId, account.id)}><strong>{account.name}</strong><small>{account.platform} · {account.external_id} · {account.currency} · {account.timezone}</small></Link><em data-status={account.status}>{account.status}</em>{user?.role === 'admin' && <button className="account-delete-button" aria-label={`Delete ${account.name}`} onClick={() => setPendingDelete(account)}><Trash2 size={14}/></button>}</article>)}{client.isSuccess && !accountCount && <Empty>No ad accounts are assigned to this client.</Empty>}</section>}
    {active === 'connections' && <ScopedConnections clientId={clientId}/>} 
    {active === 'health' && <div className="client-overview-grid"><ScopedAlerts clientId={clientId} limit={25}/><ScopedDataHealth clientId={clientId} limit={25}/></div>}
    {active === 'library' && <section className="client-hub-section"><SectionHead title="Ad library" subtitle="Saved research and collection activity linked to this client." action={<Link to={`/ad-library?related_client_id=${clientId}`}>Open Ad Library</Link>}/><AsyncStateNotice queries={[library]}/>{(library.data?.searches || []).map(item => <Link className="client-account-row" key={item.id} to={`/ad-library?related_client_id=${clientId}`}><LibraryBig size={16}/><span><strong>{item.name}</strong><small>{item.company_name} · {item.company_type} · {item.runs_count} runs</small></span></Link>)}{library.isSuccess && !(library.data?.searches || []).length && <Empty>No Ad Library searches are linked to this client.</Empty>}</section>}
    {active === 'audits' && <section className="client-hub-section"><SectionHead title="Content audits" subtitle="Creative audits linked through the client’s Ad Library research." action={<Link to={`/ad-library/audits?related_client_id=${clientId}`}>Open Content Audits</Link>}/><AsyncStateNotice queries={[audits]}/>{(audits.data?.data || []).map(audit => <Link className="client-account-row" key={audit.id} to={`/ad-library/audits/${audit.id}`}><ClipboardCheck size={16}/><span><strong>{audit.name}</strong><small>{audit.company_name} · {audit.status} · {audit.creatives_count} creatives</small></span></Link>)}{audits.isSuccess && !(audits.data?.data || []).length && <Empty>No content audits are linked to this client.</Empty>}</section>}
    {active === 'reports' && <ScopedReports clientId={clientId} limit={25}/>} 
    <ConfirmDialog open={Boolean(pendingDelete)} title={`Delete ${pendingDelete?.name || 'ad account'}?`} description="This permanently removes the account’s collected metrics, campaigns, ads, data-health issues, alert policies, and report schedules. Historical reports and alert incidents are retained without the account link." confirmLabel="Delete account" busy={removeAccount.isPending} onClose={() => setPendingDelete(null)} onConfirm={() => pendingDelete && removeAccount.mutate(pendingDelete)}/>
  </main>
}

function SectionHead({ title, subtitle, action }) { return <header className="client-section-head"><div><h2>{title}</h2><p>{subtitle}</p></div>{action}</header> }
function Empty({ children }) { return <p className="scoped-empty">{children}</p> }

