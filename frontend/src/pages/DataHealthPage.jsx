import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Activity, CircleAlert } from 'lucide-react'
import { api } from '../api/client'
import { AsyncState } from '../components/ui/AsyncState'
import { useWorkspace } from '../context/WorkspaceContext'

export default function DataHealthPage() {
  const { clientId } = useWorkspace()
  const [status, setStatus] = useState('open')
  const [severity, setSeverity] = useState('')
  const query = useQuery({ queryKey: ['data-quality', clientId, status, severity], queryFn: () => api.getDataQualityIssues({ client_id: clientId || undefined, status: status || undefined, severity: severity || undefined }).then(r => r.data) })
  const issues = query.data?.data || []

  return <main className="data-health-page">
    <header className="governance-header"><span><Activity size={18}/></span><div><h1>Data health</h1><p>Collection freshness, consistency, account access, and conversion integrity.</p></div></header>
    <div className="governance-toolbar"><select value={status} onChange={e => setStatus(e.target.value)}><option value="open">Open</option><option value="resolved">Resolved</option><option value="">All statuses</option></select><select value={severity} onChange={e => setSeverity(e.target.value)}><option value="">All severities</option><option value="critical">Critical</option><option value="warning">Warning</option></select></div>
    <AsyncState query={query} loadingText="Checking data health…" errorText="Data health could not be loaded." empty={query.isSuccess && issues.length === 0} emptyText="No matching data-quality issues. Scheduled checks will keep monitoring this client.">
      <div className="quality-list">{issues.map(issue => <article key={issue.id} className={issue.severity}><CircleAlert size={18}/><div><header><strong>{issue.title}</strong><span>{issue.severity}</span></header><p>{issue.ad_account?.name} · {issue.client?.name}</p><small>Last detected {new Date(issue.last_detected_at).toLocaleString()}</small><details><summary>Evidence and remediation</summary><pre>{JSON.stringify(issue.evidence, null, 2)}</pre><p>{issue.remediation}</p></details></div></article>)}</div>
    </AsyncState>
  </main>
}
