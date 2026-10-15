import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../api/client'
import { Page } from './ClientsPage'
import { useAuth } from '../context/AuthContext'

export default function AlertRulesPage() {
  const { user } = useAuth(); const qc = useQueryClient(); const [clientId,setClientId]=useState(''); const [open,setOpen]=useState(false)
  const clients=useQuery({queryKey:['clients'],queryFn:()=>api.getClients().then(r=>r.data)}); const selectedClientId=clientId||(clients.data?.[0]?.id?String(clients.data[0].id):'')
  const rules=useQuery({queryKey:['rules',selectedClientId],queryFn:()=>api.getAlertRules({client_id:selectedClientId}).then(r=>r.data),enabled:Boolean(selectedClientId)})
  const templates=useQuery({queryKey:['alert-templates'],queryFn:()=>api.getAlertTemplates().then(r=>r.data)})
  const [form,setForm]=useState({name:'',metric:'cpl',operator:'above',threshold:0,severity:'warning',window_days:1})
  const create=useMutation({mutationFn:()=>api.createAlertRule({...form,client_id:Number(selectedClientId)}),onSuccess:()=>{qc.invalidateQueries({queryKey:['rules',selectedClientId]});setOpen(false)}})
  const remove=useMutation({mutationFn:id=>api.deleteAlertRule(id),onSuccess:()=>qc.invalidateQueries({queryKey:['rules',selectedClientId]})})
  const canManage=user?.role!=='viewer'
  return <Page title="Alert policies" subtitle="Global Brandshark templates with client, account and campaign overrides."><div className="ops-toolbar"><select className="ops-select" value={selectedClientId} onChange={e=>setClientId(e.target.value)}>{(clients.data||[]).map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select>{canManage&&<button className="ops-primary" onClick={()=>setOpen(v=>!v)}><Plus size={14}/> Add override</button>}</div>
  {open&&<form className="ops-form compact" onSubmit={e=>{e.preventDefault();create.mutate()}}><label><span>Start from template</span><select onChange={e=>{const t=templates.data?.find(x=>String(x.id)===e.target.value);if(t)setForm({...form,name:t.name,metric:t.metric,operator:t.operator,threshold:t.threshold??'',severity:t.severity,alert_template_id:t.id})}}><option value="">Custom</option>{(templates.data||[]).map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></label><label><span>Name</span><input required value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/></label><label><span>Metric</span><select value={form.metric} onChange={e=>setForm({...form,metric:e.target.value})}>{['spend','impressions','clicks','conversions','leads','purchases','ctr','cpc','cpm','cpa','cpl','conversion_rate','roas','frequency'].map(x=><option key={x}>{x}</option>)}</select></label><label><span>Operator</span><select value={form.operator} onChange={e=>setForm({...form,operator:e.target.value})}>{['below','above','percentage_drop','percentage_increase','no_spend','no_impressions','no_conversions_with_spend','budget_under_pacing','budget_over_pacing','data_stale','connection_error'].map(x=><option key={x}>{x}</option>)}</select></label><label><span>Threshold</span><input type="number" value={form.threshold} onChange={e=>setForm({...form,threshold:e.target.value===''?null:Number(e.target.value)})}/></label><button className="ops-primary">Save rule</button></form>}
  <div className="ops-list">{(rules.data||[]).map(r=><div className="ops-list-row" key={r.id}><i className="rule-dot" data-severity={r.severity}/><div><strong>{r.name}</strong><small>{r.metric} · {r.operator} {r.threshold??''} · {r.client?.name||'Global template'}</small></div><span>{r.enabled?'Active':'Paused'}</span>{canManage&&r.client_id&&<button onClick={()=>window.confirm('Delete this override?')&&remove.mutate(r.id)}><Trash2 size={14}/></button>}</div>)}</div></Page>
}
