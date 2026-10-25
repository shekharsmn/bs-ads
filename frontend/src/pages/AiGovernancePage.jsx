import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Cpu, Gauge } from 'lucide-react'
import { api } from '../api/client'
import { AsyncState } from '../components/ui/AsyncState'
import { useToast } from '../context/ToastContext'

export default function AiGovernancePage() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [budget, setBudget] = useState('')
  const summary = useQuery({ queryKey: ['ai-governance-summary'], queryFn: () => api.getAiGovernanceSummary().then(r => r.data) })
  const save = useMutation({ mutationFn: value => api.saveAiBudget({ client_id: null, monthly_budget: Number(value), warning_percent: 70, critical_percent: 90, enabled: true }), onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['ai-governance-summary'] }); setBudget(''); toast.success('AI budget updated.') }, onError: () => toast.error('Unable to update AI budget.') })
  const data = summary.data || {}
  const currentBudget = Number(data.budget?.monthly_budget || 0)

  return <main className="ai-governance-page">
    <header className="governance-header"><span><Cpu size={18}/></span><div><h1>AI governance</h1><p>Monthly spend, pricing coverage, provider reliability, and latency.</p></div></header>
    <AsyncState query={summary} loadingText="Loading AI governance…" errorText="AI governance could not be loaded." empty={summary.isSuccess && !data.month && !(data.providers || []).length} emptyText="No AI usage has been recorded yet.">
      <section className="ai-governance-summary"><article><small>Estimated this month</small><strong>${Number(data.estimated_cost || 0).toFixed(2)}</strong><span>{data.month || '—'}</span></article><article><small>Monthly budget</small><strong>{currentBudget ? `$${currentBudget.toFixed(2)}` : 'Not set'}</strong><span>{data.budget_percent == null ? 'Add a warning budget' : `${data.budget_percent}% used`}</span></article><form onSubmit={e => { e.preventDefault(); save.mutate(budget) }}><label>Set organization budget<input type="number" min="0.01" step="0.01" value={budget} onChange={e => setBudget(e.target.value)} placeholder={currentBudget || '500'}/></label><button disabled={!budget || save.isPending}>Save</button></form></section>
      <section className="provider-health"><h2><Gauge size={16}/>Provider health</h2><div className="governance-table-wrap"><table><thead><tr><th>Provider / model</th><th>Requests</th><th>Failures</th><th>Latency</th><th>Spend</th><th>Pricing</th></tr></thead><tbody>{(data.providers || []).map(row => <tr key={`${row.provider}:${row.model}`}><td><strong>{row.provider}</strong><small>{row.model}</small></td><td>{row.requests}</td><td><span className={row.failure_rate >= 50 ? 'danger-text' : ''}>{row.failure_rate}%</span></td><td>{row.average_latency ? `${row.average_latency} ms` : '—'}</td><td>${Number(row.estimated_cost).toFixed(4)}</td><td>{row.unpriced ? `${row.unpriced} unpriced` : 'Covered'}</td></tr>)}</tbody></table></div></section>
    </AsyncState>
  </main>
}
