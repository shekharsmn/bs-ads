import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, ExternalLink, MailPlus, RefreshCw, Search, ShieldCheck, X } from 'lucide-react'
import { api } from '../api/client'
import { useToast } from '../context/ToastContext'
import { AsyncStateNotice } from './ui/AsyncState'
import ConfirmDialog from './ui/ConfirmDialog'

function editablePolicy(value) {
  return {
    portal_enabled: Boolean(value?.portal_enabled),
    accounts: (value?.accounts || []).map(item => ({
      ad_account_id: item.ad_account.id,
      visibility_mode: item.visibility_mode,
      campaign_ids: [...item.campaign_ids].sort((left, right) => left - right),
    })),
  }
}

export default function ClientPortalAccessPanel({ clientId }) {
  const toast = useToast(); const queryClient = useQueryClient(); const [draft, setDraft] = useState(null); const [searches, setSearches] = useState({}); const [inviteForm, setInviteForm] = useState({ name: '', email: '' }); const [pendingRevoke, setPendingRevoke] = useState(null)
  const access = useQuery({ queryKey: ['client-portal-access', clientId], queryFn: () => api.getClientPortalAccess(clientId).then(response => response.data) })
  const model = draft || access.data
  const clientUsers = access.data?.client_users || []
  const clientInvitations = access.data?.client_invitations || []
  const refreshPeople = () => { queryClient.invalidateQueries({ queryKey: ['client-portal-access', clientId] }); queryClient.invalidateQueries({ queryKey: ['user-directory'] }) }
  const invite = useMutation({ mutationFn: () => api.inviteUser({ name: inviteForm.name, email: inviteForm.email, role: 'client', client_ids: [Number(clientId)], run_performance_audits: false, run_content_audits: false, use_data_inspector: false }), onSuccess: () => { setInviteForm({ name: '', email: '' }); refreshPeople(); toast.success('Client invitation sent.') }, onError: error => toast.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || 'Unable to send client invitation.') })
  const resend = useMutation({ mutationFn: id => api.resendUserInvitation(id), onSuccess: () => { refreshPeople(); toast.success('Client invitation sent again.') }, onError: error => toast.error(error.response?.data?.message || 'Unable to resend client invitation.') })
  const revoke = useMutation({ mutationFn: id => api.revokeUserInvitation(id), onSuccess: () => { setPendingRevoke(null); refreshPeople(); toast.success('Client invitation revoked.') }, onError: error => toast.error(error.response?.data?.message || 'Unable to revoke client invitation.') })
  const save = useMutation({ mutationFn: () => api.updateClientPortalAccess(clientId, { portal_enabled: model.portal_enabled, accounts: model.accounts.map(item => ({ ad_account_id: item.ad_account.id, visibility_mode: item.visibility_mode, campaign_ids: item.visibility_mode === 'selected_campaigns' ? item.campaign_ids : [] })) }), onSuccess: response => { setDraft(null); queryClient.setQueryData(['client-portal-access', clientId], response.data); queryClient.invalidateQueries({ queryKey: ['client', clientId] }); toast.success('Client portal access saved.') }, onError: error => toast.error(error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || 'Unable to save client access.') })
  const dirty = Boolean(draft && access.data && JSON.stringify(editablePolicy(draft)) !== JSON.stringify(editablePolicy(access.data)))
  const updateAccount = (id, changes) => setDraft(current => { const source = current || structuredClone(access.data); return { ...source, accounts: source.accounts.map(item => item.ad_account.id === id ? { ...item, ...changes } : item) } })
  const toggleCampaign = (account, campaignId) => updateAccount(account.ad_account.id, { campaign_ids: account.campaign_ids.includes(campaignId) ? account.campaign_ids.filter(id => id !== campaignId) : [...account.campaign_ids, campaignId] })

  return <section className="client-hub-section portal-access-panel"><AsyncStateNotice queries={[access]}/>{model && <>
    <header className="portal-access-head"><div><span><ShieldCheck size={18}/></span><div><h2>Client portal access</h2><p>One shared visibility policy applies to every client user assigned to this client.</p></div></div>{model.portal_enabled && <a href={`/portal/preview/clients/${clientId}`} target="_blank" rel="noreferrer">Login as client<ExternalLink size={13}/></a>}</header>
    <section className="client-email-access"><header><div><h3>Client email access</h3><p>Invite an external client contact. They will sign in using a secure email link and see only this client.</p></div><span>{clientUsers.length + clientInvitations.length} people</span></header>
      <form onSubmit={event => { event.preventDefault(); invite.mutate() }}><label>Contact name<input aria-label="Client contact name" value={inviteForm.name} onChange={event => setInviteForm({ ...inviteForm, name: event.target.value })} placeholder="Optional"/></label><label>Client email<input required aria-label="Client email address" type="email" value={inviteForm.email} onChange={event => setInviteForm({ ...inviteForm, email: event.target.value })} placeholder="name@client.com"/></label><button type="submit" disabled={invite.isPending || !inviteForm.email}><MailPlus size={14}/>{invite.isPending ? 'Sending…' : 'Send invitation'}</button></form>
      <div className="client-email-list">{[...clientUsers, ...clientInvitations].map(person => <article key={`${person.status}:${person.id}`}><span>{(person.name || person.email).slice(0, 1).toUpperCase()}</span><div><strong>{person.name || 'Client contact'}</strong><small>{person.email}</small>{person.delivery_error && <em title={person.delivery_error}>Delivery failed — check email settings, then resend.</em>}</div><i data-status={person.status}>{person.status}</i>{person.status === 'pending' && <div className="client-email-actions"><button type="button" aria-label={`Resend invitation to ${person.email}`} disabled={resend.isPending && resend.variables === person.id} onClick={() => resend.mutate(person.id)}><RefreshCw size={13}/></button><button type="button" aria-label={`Revoke invitation for ${person.email}`} disabled={revoke.isPending && revoke.variables === person.id} onClick={() => setPendingRevoke(person)}><X size={13}/></button></div>}</article>)}{!clientUsers.length && !clientInvitations.length && <p>No client contacts have access yet.</p>}</div>
    </section>
    <label className="portal-enable-row"><span><strong>Enable client portal</strong><small>Clients can sign in only after the portal is enabled and at least one account is visible.</small></span><input type="checkbox" checked={model.portal_enabled} onChange={event => setDraft({ ...structuredClone(model), portal_enabled: event.target.checked })}/><i/></label>
    <div className="portal-account-scopes">{model.accounts.map(account => { const term = searches[account.ad_account.id] || ''; const campaigns = account.campaigns.filter(campaign => campaign.name.toLowerCase().includes(term.toLowerCase())); return <article key={account.ad_account.id}>
      <header><div><strong>{account.ad_account.name}</strong><small>{account.ad_account.platform} · {account.ad_account.external_id} · {account.ad_account.currency}</small></div><select value={account.visibility_mode} onChange={event => updateAccount(account.ad_account.id, { visibility_mode: event.target.value, campaign_ids: event.target.value === 'selected_campaigns' ? account.campaign_ids : [] })}><option value="hidden">Hidden</option><option value="all_campaigns">Full account</option><option value="selected_campaigns">Selected campaigns</option></select></header>
      {account.visibility_mode === 'selected_campaigns' && <div className="portal-campaign-picker"><label><Search size={13}/><input value={term} onChange={event => setSearches({ ...searches, [account.ad_account.id]: event.target.value })} placeholder="Search campaigns"/></label><div>{campaigns.map(campaign => <button type="button" className={account.campaign_ids.includes(campaign.id) ? 'selected' : ''} key={campaign.id} onClick={() => toggleCampaign(account, campaign.id)}><span><strong>{campaign.name}</strong><small>{campaign.status} · {campaign.type || 'Campaign'}</small></span>{account.campaign_ids.includes(campaign.id) && <Check size={14}/>}</button>)}</div><footer>{account.campaign_ids.length} selected</footer></div>}
    </article>})}</div>
    <footer className="portal-access-actions"><button type="button" disabled={!dirty || save.isPending} onClick={() => setDraft(null)}>Discard changes</button><button type="button" className="ops-primary" disabled={!dirty || save.isPending || model.accounts.some(item => item.visibility_mode === 'selected_campaigns' && !item.campaign_ids.length)} onClick={() => save.mutate()}>{save.isPending ? 'Saving…' : 'Save client access'}</button></footer>
    <ConfirmDialog open={Boolean(pendingRevoke)} title="Revoke client invitation?" description={`${pendingRevoke?.email || 'This email'} will no longer be able to use this invitation. You can invite the address again later.`} confirmLabel="Revoke invitation" tone="danger" busy={revoke.isPending} onClose={() => setPendingRevoke(null)} onConfirm={() => pendingRevoke && revoke.mutate(pendingRevoke.id)}/>
  </>}</section>
}
