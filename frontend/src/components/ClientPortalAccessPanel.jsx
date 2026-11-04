import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, ExternalLink, Search, ShieldCheck } from 'lucide-react'
import { api } from '../api/client'
import { useToast } from '../context/ToastContext'
import { AsyncStateNotice } from './ui/AsyncState'

export default function ClientPortalAccessPanel({ clientId }) {
  const toast = useToast(); const queryClient = useQueryClient(); const [draft, setDraft] = useState(null); const [searches, setSearches] = useState({})
  const access = useQuery({ queryKey: ['client-portal-access', clientId], queryFn: () => api.getClientPortalAccess(clientId).then(response => response.data) })
  const model = draft || access.data
  const save = useMutation({ mutationFn: () => api.updateClientPortalAccess(clientId, { portal_enabled: model.portal_enabled, accounts: model.accounts.map(item => ({ ad_account_id: item.ad_account.id, visibility_mode: item.visibility_mode, campaign_ids: item.visibility_mode === 'selected_campaigns' ? item.campaign_ids : [] })) }), onSuccess: response => { setDraft(null); queryClient.setQueryData(['client-portal-access', clientId], response.data); toast.success('Client portal access saved.') }, onError: error => toast.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || 'Unable to save client access.') })
  const dirty = Boolean(draft && access.data && JSON.stringify(draft) !== JSON.stringify(access.data))
  const updateAccount = (id, changes) => setDraft(current => { const source = current || structuredClone(access.data); return { ...source, accounts: source.accounts.map(item => item.ad_account.id === id ? { ...item, ...changes } : item) } })
  const toggleCampaign = (account, campaignId) => updateAccount(account.ad_account.id, { campaign_ids: account.campaign_ids.includes(campaignId) ? account.campaign_ids.filter(id => id !== campaignId) : [...account.campaign_ids, campaignId] })

  return <section className="client-hub-section portal-access-panel"><AsyncStateNotice queries={[access]}/>{model && <>
    <header className="portal-access-head"><div><span><ShieldCheck size={18}/></span><div><h2>Client portal access</h2><p>One shared visibility policy applies to every client user assigned to this client.</p></div></div>{model.portal_enabled && <a href={`/portal/preview/clients/${clientId}`} target="_blank" rel="noreferrer">Preview portal<ExternalLink size={13}/></a>}</header>
    <label className="portal-enable-row"><span><strong>Enable client portal</strong><small>Clients can sign in only after the portal is enabled and at least one account is visible.</small></span><input type="checkbox" checked={model.portal_enabled} onChange={event => setDraft({ ...structuredClone(model), portal_enabled: event.target.checked })}/><i/></label>
    <div className="portal-account-scopes">{model.accounts.map(account => { const term = searches[account.ad_account.id] || ''; const campaigns = account.campaigns.filter(campaign => campaign.name.toLowerCase().includes(term.toLowerCase())); return <article key={account.ad_account.id}>
      <header><div><strong>{account.ad_account.name}</strong><small>{account.ad_account.platform} · {account.ad_account.external_id} · {account.ad_account.currency}</small></div><select value={account.visibility_mode} onChange={event => updateAccount(account.ad_account.id, { visibility_mode: event.target.value, campaign_ids: event.target.value === 'selected_campaigns' ? account.campaign_ids : [] })}><option value="hidden">Hidden</option><option value="all_campaigns">Full account</option><option value="selected_campaigns">Selected campaigns</option></select></header>
      {account.visibility_mode === 'selected_campaigns' && <div className="portal-campaign-picker"><label><Search size={13}/><input value={term} onChange={event => setSearches({ ...searches, [account.ad_account.id]: event.target.value })} placeholder="Search campaigns"/></label><div>{campaigns.map(campaign => <button type="button" className={account.campaign_ids.includes(campaign.id) ? 'selected' : ''} key={campaign.id} onClick={() => toggleCampaign(account, campaign.id)}><span><strong>{campaign.name}</strong><small>{campaign.status} · {campaign.type || 'Campaign'}</small></span>{account.campaign_ids.includes(campaign.id) && <Check size={14}/>}</button>)}</div><footer>{account.campaign_ids.length} selected</footer></div>}
    </article>})}</div>
    <footer className="portal-access-actions"><button disabled={!dirty || save.isPending} onClick={() => setDraft(null)}>Discard</button><button className="ops-primary" disabled={!dirty || save.isPending || model.accounts.some(item => item.visibility_mode === 'selected_campaigns' && !item.campaign_ids.length)} onClick={() => save.mutate()}>{save.isPending ? 'Saving…' : 'Save client access'}</button></footer>
  </>}</section>
}
