import { useState, useEffect, useRef } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../api/client'
import AuditErrorModal from '../components/AuditErrorModal'
import { useAuth } from '../context/AuthContext'
import { ArrowLeft, PlayCircle, CheckCircle, AlertCircle, Clock, ShieldAlert, Square } from 'lucide-react'

const PERIODS = [
  { value: 'last_7d', label: 'Last 7 Days' },
  { value: 'last_30d', label: 'Last 30 Days' },
  { value: 'last_90d', label: 'Last 90 Days' },
]

const STATUS_MESSAGES = {
  pending: 'Job queued — waiting for worker...',
  running: 'Fetching account data and running audit checks...',
  complete: 'Audit complete!',
  failed: 'Audit failed.',
  cancelled: 'This audit was stopped.',
}

export default function RunAuditPage() {
  const { permissions } = useAuth()
  const navigate = useNavigate()
  const [search] = useSearchParams()
  const queryClient = useQueryClient()
  const [form, setForm] = useState({
    platform: 'meta',
    meta_account_id: '',
    google_account_id: '',
    ad_account_id: search.get('ad_account_id') || '',
    period: 'last_30d',
  })
  const [reportId, setReportId] = useState(null)
  const [jobStatus, setJobStatus] = useState(null)
  const [auditErrorOpen, setAuditErrorOpen] = useState(false)
  const [dots, setDots] = useState('')
  const pollRef = useRef(null)

  const { data: metaAccounts = [], isLoading: metaLoading } = useQuery({
    queryKey: ['accounts'],
    queryFn: () => api.getAccounts().then(r => r.data),
  })

  const { data: googleAccounts = [], isLoading: googleLoading } = useQuery({
    queryKey: ['google-accounts'],
    queryFn: () => api.getGoogleAccounts().then(r => r.data),
  })

  const { data: clients = [], isLoading: clientsLoading } = useQuery({
    queryKey: ['clients'],
    queryFn: () => api.getClients().then(r => r.data),
  })

  const operationalAccounts = clients.flatMap(client => (client.ad_accounts || []).map(account => ({ ...account, client_name: client.name, normalized: true })))
  const selectedOperationalAccount = operationalAccounts.find(account => String(account.id) === String(form.ad_account_id))
  const selectedPlatform = selectedOperationalAccount?.platform || form.platform
  const legacyAccounts = (selectedPlatform === 'meta' ? metaAccounts : googleAccounts).map(account => ({ ...account, normalized: false }))
  const accountsLoading = metaLoading || googleLoading || clientsLoading
  const accounts = [...operationalAccounts.filter(account => account.platform === selectedPlatform), ...legacyAccounts]

  const startMutation = useMutation({
    mutationFn: (data) => api.createReport(data),
    onSuccess: (res) => {
      setReportId(res.data.report_id)
      setJobStatus({ status: 'pending' })
      setAuditErrorOpen(false)
    },
    onError: (err) => {
      setJobStatus({ status: 'failed', error: err.response?.data?.message || err.response?.data?.error || 'Failed to start audit.' })
    },
  })

  const cancelMutation = useMutation({
    mutationFn: () => {
      if (!reportId) return Promise.reject(new Error('No report'))
      return api.cancelReport(reportId)
    },
    onSuccess: async (res) => {
      queryClient.invalidateQueries({ queryKey: ['reports'] })
      if (res.data?.status === 'cancelled') {
        clearInterval(pollRef.current)
        const st = await api.getReportStatus(reportId)
        setJobStatus(st.data)
      }
      // If still running, polling continues until the worker sets status to cancelled.
    },
  })

  useEffect(() => {
    if (jobStatus?.status === 'running' || jobStatus?.status === 'pending') {
      const t = setInterval(() => setDots(d => d.length >= 3 ? '' : d + '.'), 500)
      return () => clearInterval(t)
    }
  }, [jobStatus?.status])

  useEffect(() => {
    if (!reportId) return
    const poll = async () => {
      try {
        const res = await api.getReportStatus(reportId)
        const data = res.data
        setJobStatus(data)
        if (data.status === 'complete') {
          clearInterval(pollRef.current)
          setTimeout(() => navigate(`/reports/${reportId}`), 1800)
        }
        if (data.status === 'failed' || data.status === 'cancelled') {
          clearInterval(pollRef.current)
        }
      } catch {
        clearInterval(pollRef.current)
      }
    }
    pollRef.current = setInterval(poll, 3000)
    poll()
    return () => clearInterval(pollRef.current)
  }, [reportId, navigate])

  const handleSubmit = (e) => {
    e.preventDefault()
    const period = form.period
    if (form.ad_account_id) {
      startMutation.mutate({ platform: selectedPlatform, ad_account_id: parseInt(form.ad_account_id, 10), period })
    } else if (selectedPlatform === 'meta') {
      startMutation.mutate({
        platform: 'meta',
        meta_account_id: parseInt(form.meta_account_id, 10),
        period,
      })
    } else {
      startMutation.mutate({
        platform: 'google',
        google_account_id: parseInt(form.google_account_id, 10),
        period,
      })
    }
  }

  const isRunning = jobStatus?.status === 'pending' || jobStatus?.status === 'running'
  const isDone = jobStatus?.status === 'complete'
  const isFailed = jobStatus?.status === 'failed'
  const isCancelled = jobStatus?.status === 'cancelled'

  const accountOk = selectedPlatform === 'meta'
    ? !!form.meta_account_id || !!form.ad_account_id
    : !!form.google_account_id || !!form.ad_account_id

  if (!permissions?.run_performance_audits) return <main className="audit-permission-empty"><ShieldAlert/><h1>Performance audits are restricted</h1><p>An administrator can grant this permission and assign the clients you need from User management.</p><button onClick={() => navigate('/command-center')}><ArrowLeft size={14}/> Back to Command Center</button></main>

  return (
    <div style={{ padding: '32px', maxWidth: '560px', margin: '0 auto' }}>
      <div className="fade-up">
        <button
          onClick={() => navigate('/')}
          style={{
            display: 'flex', alignItems: 'center', gap: '6px',
            background: 'none', border: 'none', cursor: 'pointer',
            color: '#64748b', fontSize: '13px', fontFamily: 'inherit',
            padding: '0 0 16px',
          }}
        >
          <ArrowLeft size={14} />Back to Dashboard
        </button>
        <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '24px', fontWeight: 700, color: '#0f172a', margin: 0, letterSpacing: '-0.02em' }}>
          Run Audit
        </h1>
        <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: '13px' }}>
          Meta: live Graph API + checks + AI creative. Google: Google Ads API + checklist scoring (no creative AI in v1).
        </p>
      </div>

      {!reportId && (
        <div className="fade-up-delay-1" style={{
          background: 'white', border: '1px solid #e2e8f0',
          borderRadius: '14px', padding: '32px', marginTop: '28px',
        }}>
          {accounts.length === 0 && !accountsLoading && (
            <div style={{
              background: '#fffbeb', border: '1px solid #fde68a',
              borderRadius: '8px', padding: '12px 14px', marginBottom: '20px',
              fontSize: '13px', color: '#92400e',
              display: 'flex', alignItems: 'center', gap: '8px',
            }}>
              <AlertCircle size={14} />
              No {selectedPlatform === 'meta' ? 'Meta' : 'Google'} accounts connected.{' '}
              <button
                onClick={() => navigate('/connections')}
                style={{ background: 'none', border: 'none', color: '#d97706', fontWeight: 700, cursor: 'pointer', padding: 0, fontFamily: 'inherit', fontSize: '13px' }}
              >Connect one →</button>
            </div>
          )}

          <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
            <div>
              <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#475569', marginBottom: '8px', letterSpacing: '0.04em', textTransform: 'uppercase' }}>
                Platform
              </label>
              <div style={{ display: 'flex', gap: '8px' }}>
                {[
                  { id: 'meta', label: 'Meta Ads' },
                  { id: 'google', label: 'Google Ads' },
                ].map(({ id, label }) => (
                  <button
                    key={id}
                    type="button"
                    onClick={() => setForm(f => ({
                      ...f,
                      platform: id,
                      meta_account_id: '',
                      google_account_id: '',
                      ad_account_id: '',
                    }))}
                    style={{
                      flex: 1, padding: '10px',
                      border: selectedPlatform === id ? '2px solid #3b82f6' : '1px solid #e2e8f0',
                      borderRadius: '8px', fontSize: '13px', fontWeight: 600,
                      fontFamily: 'inherit', cursor: 'pointer',
                      background: selectedPlatform === id ? '#eff6ff' : '#f8fafc',
                      color: selectedPlatform === id ? '#2563eb' : '#64748b',
                    }}
                  >{label}</button>
                ))}
              </div>
            </div>

            <div>
              <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#475569', marginBottom: '6px', letterSpacing: '0.04em', textTransform: 'uppercase' }}>
                {selectedPlatform === 'meta' ? 'Meta account' : 'Google Ads account'}
              </label>
              <select
                required
                value={form.ad_account_id ? `ops:${form.ad_account_id}` : (selectedPlatform === 'meta' ? form.meta_account_id : form.google_account_id)}
                onChange={e => setForm(f => {
                  if (e.target.value.startsWith('ops:')) return { ...f, ad_account_id: e.target.value.slice(4), meta_account_id: '', google_account_id: '' }
                  return f.platform === 'meta'
                    ? { ...f, ad_account_id: '', meta_account_id: e.target.value }
                    : { ...f, ad_account_id: '', google_account_id: e.target.value }
                })}
                disabled={accountsLoading}
                style={{
                  width: '100%', padding: '10px 14px',
                  border: '1px solid #e2e8f0', borderRadius: '8px',
                  fontSize: '13px', color: '#0f172a', background: '#f8fafc',
                  fontFamily: 'inherit', outline: 'none', cursor: 'pointer',
                }}
              >
                <option value="">Select an account...</option>
                {accounts.map(a => <option key={`${a.normalized ? 'ops' : 'legacy'}-${a.id}`} value={a.normalized ? `ops:${a.id}` : a.id}>
                  {a.normalized ? `${a.client_name} · ${a.name} · ${a.external_id}` : `${a.label}${a.account_name && a.account_name !== a.label ? ` (${a.account_name})` : ''}${a.customer_id ? ` · ${a.customer_id}` : ''}`}
                </option>)}
              </select>
            </div>

            <div>
              <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#475569', marginBottom: '8px', letterSpacing: '0.04em', textTransform: 'uppercase' }}>
                Period
              </label>
              <div style={{ display: 'flex', gap: '8px' }}>
                {PERIODS.map(({ value, label }) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setForm(f => ({ ...f, period: value }))}
                    style={{
                      flex: 1, padding: '9px',
                      border: form.period === value ? '2px solid #3b82f6' : '1px solid #e2e8f0',
                      borderRadius: '8px', fontSize: '12px', fontWeight: 600,
                      fontFamily: 'inherit', cursor: 'pointer',
                      background: form.period === value ? '#eff6ff' : '#f8fafc',
                      color: form.period === value ? '#2563eb' : '#64748b',
                    }}
                  >{label}</button>
                ))}
              </div>
            </div>

            <div style={{
              background: '#f8fafc', border: '1px solid #e2e8f0',
              borderRadius: '8px', padding: '12px 14px',
              display: 'flex', alignItems: 'center', gap: '10px',
            }}>
              <Clock size={13} color="#94a3b8" />
              <p style={{ margin: 0, fontSize: '12px', color: '#64748b' }}>
                <strong style={{ color: '#0f172a' }}>Estimated time:</strong>{' '}
                {selectedPlatform === 'meta' ? '2–5 minutes (includes AI creative).' : '1–4 minutes (API fetch + checklist).'}
              </p>
            </div>

            <button
              type="submit"
              disabled={!accountOk || accounts.length === 0}
              style={{
                padding: '13px', marginTop: '4px',
                background: (!accountOk || accounts.length === 0)
                  ? '#e2e8f0'
                  : 'linear-gradient(135deg, #3b82f6, #2563eb)',
                border: 'none', borderRadius: '10px', color: 'white',
                fontSize: '14px', fontWeight: 700, fontFamily: 'Syne, sans-serif',
                cursor: (!accountOk || accounts.length === 0) ? 'not-allowed' : 'pointer',
                display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '8px',
              }}
            >
              <PlayCircle size={16} />
              Start Audit
            </button>
          </form>
        </div>
      )}

      {reportId && (
        <div className="fade-up" style={{
          background: 'white', border: '1px solid #e2e8f0',
          borderRadius: '14px', padding: '40px 32px', marginTop: '28px',
          textAlign: 'center',
        }}>
          <div style={{ marginBottom: '24px', display: 'flex', justifyContent: 'center' }}>
            {isDone ? (
              <div style={{
                width: 72, height: 72, borderRadius: '50%',
                background: 'linear-gradient(135deg, #10b981, #059669)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}>
                <CheckCircle size={32} color="white" strokeWidth={2} />
              </div>
            ) : isCancelled ? (
              <div style={{
                width: 72, height: 72, borderRadius: '50%',
                background: '#f1f5f9',
                border: '2px solid #cbd5e1',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}>
                <Square size={28} color="#64748b" fill="#64748b" strokeWidth={1.5} />
              </div>
            ) : isFailed ? (
              <div style={{
                width: 72, height: 72, borderRadius: '50%',
                background: 'linear-gradient(135deg, #f43f5e, #e11d48)',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}>
                <AlertCircle size={32} color="white" strokeWidth={2} />
              </div>
            ) : (
              <div style={{ width: 72, height: 72, borderRadius: '50%', border: '4px solid #e2e8f0', borderTopColor: '#3b82f6', animation: 'spin-slow 1s linear infinite' }} />
            )}
          </div>

          <h2 style={{ fontFamily: 'Syne, sans-serif', fontSize: '20px', fontWeight: 700, color: '#0f172a', margin: '0 0 8px' }}>
            {isDone ? 'Audit Complete!' : isCancelled ? 'Audit Stopped' : isFailed ? 'Audit Failed' : `Running Audit${dots}`}
          </h2>
          <p style={{ fontSize: '13px', color: '#64748b', margin: '0 0 16px', minHeight: '20px' }}>
            {(jobStatus?.error_message && (isCancelled || isFailed))
              ? jobStatus.error_message
              : (STATUS_MESSAGES[jobStatus?.status] || '')}
          </p>
          {isFailed && (jobStatus?.error_message || jobStatus?.error) && (
            <button
              type="button"
              onClick={() => setAuditErrorOpen(true)}
              style={{
                marginBottom: '20px', padding: '8px 16px', borderRadius: '8px',
                border: '1px solid #fecdd3', background: '#fff1f2', color: '#be123c',
                fontSize: '12px', fontWeight: 600, fontFamily: 'inherit', cursor: 'pointer',
              }}
            >View error details</button>
          )}

          {isRunning && (
            <div style={{ marginBottom: '16px' }}>
              <button
                type="button"
                disabled={cancelMutation.isPending}
                onClick={() => {
                  if (!window.confirm('Stop this audit? It will end after the current fetch step.')) return
                  cancelMutation.mutate()
                }}
                style={{
                  padding: '8px 16px', borderRadius: '8px',
                  border: '1px solid #fecdd3', background: '#fff1f2',
                  color: '#be123c', fontSize: '12px', fontWeight: 600,
                  fontFamily: 'inherit', cursor: cancelMutation.isPending ? 'wait' : 'pointer',
                  display: 'inline-flex', alignItems: 'center', gap: '6px',
                }}
              >
                <Square size={10} fill="currentColor" />Stop audit
              </button>
            </div>
          )}

          {isRunning && (
            <div style={{
              background: '#f8fafc', border: '1px solid #e2e8f0',
              borderRadius: '10px', padding: '16px',
              display: 'flex', flexDirection: 'column', gap: '10px', textAlign: 'left',
            }}>
              {(selectedPlatform === 'meta'
                ? [
                    { label: 'Fetching campaigns, ads, and creatives', done: true },
                    { label: 'Running Meta audit checks', done: jobStatus?.status === 'running' },
                    { label: 'AI visual analysis on creatives', done: false },
                    { label: 'Saving results to database', done: false },
                  ]
                : [
                    { label: 'Fetching Google Ads data (GAQL)', done: true },
                    { label: 'Running Google checklist checks', done: jobStatus?.status === 'running' },
                    { label: 'Saving results to database', done: false },
                  ]
              ).map(({ label, done }, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                  <div style={{
                    width: 20, height: 20, borderRadius: '50%', flexShrink: 0,
                    background: done ? '#10b981' : '#e2e8f0',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                  }}>
                    {done
                      ? <CheckCircle size={11} color="white" strokeWidth={3} />
                      : <span style={{ width: 6, height: 6, borderRadius: '50%', background: '#94a3b8', display: 'block' }} />}
                  </div>
                  <span style={{ fontSize: '12px', color: done ? '#10b981' : '#94a3b8', fontWeight: done ? 600 : 400 }}>{label}</span>
                </div>
              ))}
            </div>
          )}

          {isDone && (
            <button
              onClick={() => navigate(`/reports/${reportId}`)}
              style={{
                padding: '11px 28px',
                background: 'linear-gradient(135deg, #3b82f6, #2563eb)',
                border: 'none', borderRadius: '10px', color: 'white',
                fontSize: '14px', fontWeight: 700, fontFamily: 'Syne, sans-serif',
                cursor: 'pointer',
              }}
            >View Report →</button>
          )}

          {(isFailed || isCancelled) && (
            <button
              onClick={() => { setReportId(null); setJobStatus(null); setAuditErrorOpen(false) }}
              style={{
                padding: '10px 24px', background: '#f8fafc',
                border: '1px solid #e2e8f0', borderRadius: '8px',
                color: '#64748b', fontSize: '13px', fontWeight: 600,
                fontFamily: 'inherit', cursor: 'pointer',
              }}
            >{isCancelled ? 'Back' : 'Try Again'}</button>
          )}
        </div>
      )}

      <AuditErrorModal
        open={auditErrorOpen}
        onClose={() => setAuditErrorOpen(false)}
        title="Audit failed"
        message={jobStatus?.error_message || jobStatus?.error || ''}
      />
    </div>
  )
}
