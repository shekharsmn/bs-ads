import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Cpu, Pencil, Plus, Trash2, X } from 'lucide-react'
import { api } from '../api/client'
import { Page } from './ClientsPage'

const emptyForm = {
  provider: 'gemini',
  model: '',
  label: '',
  capabilities: ['text', 'structured_json'],
  input_price: '0',
  output_price: '0',
  enabled: true,
  is_default: false,
}

const capabilityLabels = {
  text: 'Text',
  vision: 'Images',
  audio: 'Audio / video',
  structured_json: 'Structured JSON',
}

export default function AiModelSettingsPage() {
  const queryClient = useQueryClient()
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [error, setError] = useState('')

  const query = useQuery({
    queryKey: ['ai-model-settings'],
    queryFn: () => api.getAiModels().then(response => response.data),
  })

  const closeForm = () => {
    setEditingId(null)
    setForm(emptyForm)
    setError('')
  }

  const save = useMutation({
    mutationFn: payload => editingId === 'new' ? api.createAiModel(payload) : api.updateAiModel(editingId, payload),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['ai-model-settings'] })
      await queryClient.invalidateQueries({ queryKey: ['ad-library-audit-models'] })
      closeForm()
    },
    onError: requestError => setError(apiError(requestError, 'Could not save this model.')),
  })

  const remove = useMutation({
    mutationFn: id => api.deleteAiModel(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['ai-model-settings'] }),
    onError: requestError => setError(apiError(requestError, 'Could not delete this model.')),
  })

  const providers = query.data?.providers || []
  const models = query.data?.models || []
  const capabilities = query.data?.capabilities || []

  const edit = model => {
    setEditingId(model.id)
    setForm({
      provider: model.provider,
      model: model.model,
      label: model.label,
      capabilities: model.capabilities,
      input_price: String(model.input_price),
      output_price: String(model.output_price),
      enabled: model.enabled,
      is_default: model.is_default,
    })
    setError('')
  }

  const toggleCapability = capability => {
    setForm(current => ({
      ...current,
      capabilities: current.capabilities.includes(capability)
        ? current.capabilities.filter(item => item !== capability)
        : [...current.capabilities, capability],
    }))
  }

  const submit = event => {
    event.preventDefault()
    setError('')
    save.mutate({
      ...form,
      input_price: Number(form.input_price),
      output_price: Number(form.output_price),
    })
  }

  return <Page title="AI model settings" subtitle="Control which models operators can select for content audits.">
    <div className="ai-settings-providers">
      <span>Provider keys</span>
      {providers.map(provider => <div key={provider.key} className={provider.configured ? 'ready' : 'missing'}>
        <i/>{provider.label}<small>{provider.configured ? 'Ready' : 'API key missing'}</small>
      </div>)}
    </div>

    <div className="ops-toolbar ai-settings-toolbar">
      <p>Prices are USD per 1 million tokens. API keys stay in the backend environment.</p>
      {!editingId && <button className="ops-primary" onClick={() => { setEditingId('new'); setForm(emptyForm); setError('') }}><Plus size={15}/> Add model</button>}
    </div>

    {editingId && <form className="ai-model-form fade-up" onSubmit={submit}>
      <div className="ai-model-form-head">
        <div><Cpu size={17}/><span><strong>{editingId === 'new' ? 'Add AI model' : 'Edit AI model'}</strong><small>Use the exact model identifier accepted by the provider API.</small></span></div>
        <button type="button" className="ai-icon-button" onClick={closeForm} aria-label="Close model form"><X size={15}/></button>
      </div>

      <div className="ai-model-fields">
        <Field label="Provider"><select value={form.provider} onChange={event => setForm({ ...form, provider: event.target.value })}>{providers.map(provider => <option key={provider.key} value={provider.key}>{provider.label}</option>)}</select></Field>
        <Field label="Display name"><input required value={form.label} onChange={event => setForm({ ...form, label: event.target.value })} placeholder="Gemini 2.5 Flash Lite"/></Field>
        <Field label="Model identifier"><input required value={form.model} onChange={event => setForm({ ...form, model: event.target.value })} placeholder="gemini-2.5-flash-lite"/></Field>
        <Field label="Input price"><div className="ai-price-input"><span>$</span><input required min="0" step="0.000001" type="number" value={form.input_price} onChange={event => setForm({ ...form, input_price: event.target.value })}/></div></Field>
        <Field label="Output price"><div className="ai-price-input"><span>$</span><input required min="0" step="0.000001" type="number" value={form.output_price} onChange={event => setForm({ ...form, output_price: event.target.value })}/></div></Field>
      </div>

      <div className="ai-capability-field"><span>Capabilities</span><div>{capabilities.map(capability => <label key={capability} className={form.capabilities.includes(capability) ? 'selected' : ''}><input type="checkbox" checked={form.capabilities.includes(capability)} onChange={() => toggleCapability(capability)}/><Check size={12}/>{capabilityLabels[capability] || capability}</label>)}</div></div>

      <div className="ai-model-form-foot">
        <label><input type="checkbox" checked={form.enabled} onChange={event => setForm({ ...form, enabled: event.target.checked })}/><span><strong>Enabled</strong><small>Available in the audit model selector</small></span></label>
        <label><input type="checkbox" checked={form.is_default} onChange={event => setForm({ ...form, is_default: event.target.checked })}/><span><strong>Default model</strong><small>Selected first for compatible audits</small></span></label>
        <div><button type="button" onClick={closeForm}>Cancel</button><button className="ops-primary" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save model'}</button></div>
      </div>
      {error && <p className="ops-form-error">{error}</p>}
    </form>}

    {!editingId && error && <p className="ops-form-error ai-settings-error">{error}</p>}

    <div className="ai-model-list">
      <div className="ai-model-list-head"><span>Model</span><span>Capabilities</span><span>Pricing</span><span>Status</span><span/></div>
      {query.isLoading && <div className="ai-model-empty">Loading models…</div>}
      {!query.isLoading && !models.length && <div className="ai-model-empty">No models configured. Add one to enable content audits.</div>}
      {models.map(model => <div className="ai-model-row" key={model.id}>
        <div className="ai-model-identity"><span className={`ai-provider-mark ${model.provider}`}>{model.provider.slice(0, 1).toUpperCase()}</span><span><strong>{model.label}</strong><small>{model.provider}:{model.model}</small></span></div>
        <div className="ai-model-capabilities">{model.capabilities.map(capability => <span key={capability}>{capabilityLabels[capability] || capability}</span>)}</div>
        <div className="ai-model-pricing"><span>${formatPrice(model.input_price)} input</span><span>${formatPrice(model.output_price)} output</span></div>
        <div className="ai-model-status"><span className={model.enabled && model.provider_configured ? 'enabled' : 'disabled'}>{!model.enabled ? 'Disabled' : model.provider_configured ? 'Enabled' : 'Key missing'}</span>{model.is_default && <small>Default</small>}</div>
        <div className="ai-model-actions"><button title={`Edit ${model.label}`} onClick={() => edit(model)}><Pencil size={14}/></button><button title={`Delete ${model.label}`} disabled={remove.isPending} onClick={() => { if (window.confirm(`Delete ${model.label}?`)) remove.mutate(model.id) }}><Trash2 size={14}/></button></div>
      </div>)}
    </div>
  </Page>
}

function Field({ label, children }) { return <label className="ai-settings-field"><span>{label}</span>{children}</label> }
function formatPrice(value) { return Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 }) }
function apiError(error, fallback) { const errors = error.response?.data?.errors; return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || fallback }
