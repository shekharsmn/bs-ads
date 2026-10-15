import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, ExternalLink, Plus } from 'lucide-react'
import { api } from '../api/client'
import { Page } from './ClientsPage'

export default function ScheduledReportsPage() {
  const qc = useQueryClient()
  const [clientId, setClientId] = useState('')
  const clients = useQuery({ queryKey: ['clients'], queryFn: () => api.getClients().then(r => r.data) })
  const selectedClientId = clientId || (clients.data?.[0]?.id ? String(clients.data[0].id) : '')
  const reports = useQuery({ queryKey: ['report-schedules'], queryFn: () => api.getReportSchedules().then(r => r.data) })
  const save = useMutation({ mutationFn: data => api.saveReportSchedule(data), onSuccess: () => qc.invalidateQueries({ queryKey: ['report-schedules'] }) })
  const add = frequency => save.mutate({ client_id: Number(selectedClientId), frequency, weekday: frequency === 'weekly' ? 1 : null, day_of_month: frequency === 'monthly' ? 1 : null, local_time: '09:00', enabled: true })
  return <Page title="Scheduled reports" subtitle="Immutable weekly and monthly snapshots with 30-day client-safe links.">
    <div className="ops-toolbar"><select className="ops-select" value={selectedClientId} onChange={e => setClientId(e.target.value)}>{(clients.data || []).map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select><button className="ops-primary" onClick={() => add('weekly')} disabled={!selectedClientId}><Plus size={14}/> Weekly</button><button className="ops-primary secondary" onClick={() => add('monthly')} disabled={!selectedClientId}><Plus size={14}/> Monthly</button></div>
    <h2 className="ops-list-title">Schedules</h2><div className="ops-list">{(reports.data?.schedules || []).map(s => <div className="ops-list-row" key={s.id}><CalendarClock size={16}/><div><strong>{s.client.name} · {s.frequency}</strong><small>{s.local_time} client local time · {s.frequency === 'weekly' ? `weekday ${s.weekday}` : `day ${s.day_of_month}`}</small></div><span>{s.enabled ? 'Active' : 'Paused'}</span></div>)}</div>
    <h2 className="ops-list-title">Generated snapshots</h2><div className="ops-list">{(reports.data?.runs || []).map(r => <div className="ops-list-row" key={r.id}><div><strong>{r.client.name} · {r.frequency}</strong><small>{r.period_start} — {r.period_end} · {r.status}</small></div>{r.share_url && <a href={r.share_url} target="_blank" rel="noreferrer">Open <ExternalLink size={13}/></a>}</div>)}</div>
  </Page>
}
