import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createElement } from 'react'
import { CheckCircle2, Pause, Play, RefreshCw, RotateCcw, X, XCircle } from 'lucide-react'
import { api } from '../api/client'
import { useToast } from '../context/ToastContext'
import { AsyncState } from './ui/AsyncState'

const activeStatuses = new Set(['pending', 'running', 'collecting_context', 'generating_personas', 'analyzing_creatives', 'generating_insights'])
const labels = { deep_audit: 'Account audit', report_snapshot: 'Report', ad_library_search: 'Ad search', content_audit: 'Content audit' }

export default function ActivityCenter({ open, onClose }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const query = useQuery({
    queryKey: ['activities'], queryFn: () => api.getActivities({ per_page: 50 }).then(r => r.data), enabled: open,
    refetchInterval: current => current.state.data?.data?.some(item => activeStatuses.has(item.status)) ? 5000 : 30000,
  })
  const action = useMutation({ mutationFn: ({ id, name }) => api.activityAction(id, name), onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['activities'] }); toast.success('Activity updated.') }, onError: error => toast.error(error.response?.data?.message || 'The activity could not be updated.') })
  if (!open) return null

  return <><button className="activity-scrim" aria-label="Close activity center" onClick={onClose}/><aside className="activity-center" aria-label="Activity center">
    <header><div><p className="ops-eyebrow">Workflow history</p><h2>Activity</h2></div><button onClick={onClose} aria-label="Close"><X size={18}/></button></header>
    <div className="activity-feed">
      <AsyncState query={query} loadingText="Loading activity…" errorText="Activity could not be loaded." empty={query.isSuccess && !query.data?.data?.length} emptyText="Long-running work and its history will appear here.">
      {(query.data?.data || []).map(item => <article className="activity-item" key={item.id}>
        <div className={`activity-state ${activeStatuses.has(item.status) ? 'active' : item.status === 'failed' ? 'failed' : 'complete'}`}>{item.status === 'failed' ? <XCircle size={15}/> : activeStatuses.has(item.status) ? <RefreshCw className="spin" size={15}/> : <CheckCircle2 size={15}/>}</div>
        <div><small>{labels[item.type] || item.type} · {item.client?.name || item.company_name || 'Workspace'}</small><strong>{item.title}</strong><p>{item.error_message || `${String(item.stage || item.status).replaceAll('_', ' ')}${item.progress_total ? ` · ${item.progress_completed}/${item.progress_total}` : ''}`}</p>
          <time>{new Date(item.updated_at).toLocaleString()}</time>
          <ActivityActions item={item} busy={action.isPending} run={name => action.mutate({ id: item.id, name })}/>
        </div>
      </article>)}
      </AsyncState>
    </div>
  </aside></>
}

function ActivityActions({ item, busy, run }) {
  const actions = []
  if (item.type === 'content_audit' && item.status === 'analyzing_creatives') actions.push(['pause', Pause])
  if (item.type === 'content_audit' && item.status === 'creative_paused') actions.push(['resume', Play])
  if (item.type === 'content_audit' && item.status === 'creative_review' && item.progress_failed > 0) actions.push(['retry', RotateCcw])
  if (item.type === 'deep_audit' && ['pending', 'running'].includes(item.status)) actions.push(['cancel', XCircle])
  if (['report_snapshot', 'ad_library_search'].includes(item.type) && ['failed', 'blocked', 'empty', 'partial', 'complete'].includes(item.status)) actions.push(['retry', RotateCcw])
  if (!actions.length) return null
  return <div className="activity-actions">{actions.map(([name, icon]) => <button disabled={busy} key={name} onClick={() => run(name)}>{createElement(icon, { size: 12 })}{name}</button>)}</div>
}

