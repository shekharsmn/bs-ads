import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Check, Clock, MessageSquare, UserCheck } from 'lucide-react'
import { api } from '../api/client'
import { Page } from './ClientsPage'
import { useAuth } from '../context/AuthContext'

export default function AlertInboxPage() {
  const { id } = useParams(); const navigate = useNavigate(); const qc = useQueryClient(); const { user } = useAuth()
  const alerts = useQuery({ queryKey: ['alerts'], queryFn: () => api.getAlerts({}).then(r => r.data.data), refetchInterval: 60_000 })
  const detail = useQuery({ queryKey: ['alert', id], queryFn: () => api.getAlert(id).then(r => r.data), enabled: Boolean(id) })
  const action = useMutation({ mutationFn: ({ action, data }) => api.alertAction(id, action, data), onSuccess: () => { qc.invalidateQueries({ queryKey: ['alerts'] }); qc.invalidateQueries({ queryKey: ['alert', id] }) } })
  const operate = user?.role !== 'viewer'
  return <Page title="Alert inbox" subtitle="Assign, acknowledge and resolve performance exceptions across clients.">
    <div className="ops-split"><section className="ops-inbox">{(alerts.data || []).map(a => <button key={a.id} className={`ops-inbox-row ${id===a.id?'active':''}`} onClick={() => navigate(`/alerts/${a.id}`)}><i data-severity={a.severity}/><span><strong>{a.title}</strong><small>{a.client.name} · {a.campaign?.name || a.ad_account?.name || 'Account'}</small></span><em>{a.status}</em></button>)}</section>
    <aside className="ops-inspector">{detail.data ? <><p className="ops-eyebrow">{detail.data.severity} · {detail.data.status}</p><h2>{detail.data.title}</h2><p>{detail.data.summary}</p><dl><dt>Observed</dt><dd>{detail.data.observed_value ?? '—'}</dd><dt>Baseline</dt><dd>{detail.data.baseline_value ?? '—'}</dd><dt>Threshold</dt><dd>{detail.data.threshold_value ?? '—'}</dd></dl>{operate && <div className="ops-actions"><button onClick={() => action.mutate({action:'acknowledge',data:{}})}><Check size={14}/> Acknowledge</button><button onClick={() => { const note=window.prompt('Add a note'); if(note) action.mutate({action:'note',data:{note}}) }}><MessageSquare size={14}/> Note</button><button onClick={() => action.mutate({action:'snooze',data:{snoozed_until:new Date(Date.now()+6*3600000).toISOString()}})}><Clock size={14}/> Snooze 6h</button><button onClick={() => action.mutate({action:'resolve',data:{note:'Manually resolved'}})}><UserCheck size={14}/> Resolve</button></div>}<h3>Activity</h3><ol className="ops-timeline">{detail.data.events?.map(e => <li key={e.id}><strong>{e.type}</strong><span>{e.actor?.name || 'System'} · {new Date(e.created_at).toLocaleString()}</span>{e.note && <p>{e.note}</p>}</li>)}</ol></> : <div style={{ color:'#94a3b8' }}>Select an incident to inspect its history.</div>}</aside></div>
  </Page>
}
