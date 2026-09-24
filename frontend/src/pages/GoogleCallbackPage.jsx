import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../api/client'
import { AlertCircle, CheckCircle } from 'lucide-react'

export default function GoogleCallbackPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [customerId, setCustomerId] = useState('')
  const [loginCustomerId, setLoginCustomerId] = useState('')
  const [label, setLabel] = useState('')
  const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '')
  const oauthErr = params.get('error')
  const [err, setErr] = useState(() => oauthErr || '')

  const { data: pending, isLoading } = useQuery({
    queryKey: ['google-oauth-pending'],
    queryFn: () => api.getGoogleOAuthPending().then(r => r.data),
    retry: 1,
  })

  const saveMutation = useMutation({
    mutationFn: (body) => api.createGoogleAccount(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['google-accounts'] })
      navigate('/accounts')
    },
    onError: (e) => {
      setErr(e.response?.data?.error || 'Failed to save Google Ads account.')
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    setErr('')
    if (!/^\d{10}$/.test(customerId)) {
      setErr('Customer ID must be 10 digits (no dashes).')
      return
    }
    if (loginCustomerId && !/^\d{10}$/.test(loginCustomerId)) {
      setErr('Manager (MCC) ID must be 10 digits or empty.')
      return
    }
    saveMutation.mutate({
      customer_id: customerId,
      login_customer_id: loginCustomerId || undefined,
      label: label || undefined,
    })
  }

  if (isLoading) {
    return (
      <div style={{ padding: '48px', textAlign: 'center', maxWidth: 520, margin: '0 auto' }}>
        <p style={{ color: '#64748b', fontSize: '14px' }}>Loading OAuth session…</p>
      </div>
    )
  }

  if (oauthErr || err) {
    return (
      <div style={{ padding: '32px', maxWidth: 520, margin: '0 auto' }}>
        <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '22px', color: '#0f172a' }}>Google connection failed</h1>
        <p style={{ color: '#f43f5e', fontSize: '14px', marginTop: '12px' }}>{err || oauthErr}</p>
        <button
          type="button"
          onClick={() => navigate('/accounts')}
          style={{
            marginTop: '20px', padding: '10px 20px', borderRadius: '8px', border: '1px solid #e2e8f0',
            background: '#f8fafc', cursor: 'pointer', fontFamily: 'inherit', fontSize: '13px',
          }}
        >Back to accounts</button>
      </div>
    )
  }

  if (!pending?.pending || !(pending.customer_ids || []).length) {
    return (
      <div style={{ padding: '32px', maxWidth: 520, margin: '0 auto' }}>
        <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '22px', color: '#0f172a' }}>No pending Google session</h1>
        <p style={{ color: '#64748b', fontSize: '14px', marginTop: '8px' }}>
          Start from Accounts and click <strong>Connect Google Ads</strong> again.
        </p>
        <button
          type="button"
          onClick={() => navigate('/accounts')}
          style={{
            marginTop: '20px', padding: '10px 20px', borderRadius: '8px', border: 'none',
            background: 'linear-gradient(135deg, #3b82f6, #2563eb)', color: 'white',
            cursor: 'pointer', fontFamily: 'inherit', fontSize: '13px', fontWeight: 600,
          }}
        >Go to Accounts</button>
      </div>
    )
  }

  return (
    <div style={{ padding: '32px', maxWidth: 560, margin: '0 auto' }}>
      <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '24px', fontWeight: 700, color: '#0f172a', margin: '0 0 8px' }}>
        Finish Google Ads setup
      </h1>
      <p style={{ margin: '0 0 24px', fontSize: '13px', color: '#64748b' }}>
        Pick the Google Ads <strong>customer ID</strong> to link. Optional: MCC manager ID if you access this account through a manager.
      </p>

      <div style={{
        background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: '10px',
        padding: '12px 14px', marginBottom: '20px', display: 'flex', gap: '10px', alignItems: 'flex-start',
      }}>
        <CheckCircle size={18} color="#059669" style={{ flexShrink: 0, marginTop: 2 }} />
        <p style={{ margin: 0, fontSize: '13px', color: '#166534' }}>
          Google authorized successfully. {pending.customer_ids.length} accessible account(s) found.
        </p>
      </div>

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
        <div>
          <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#475569', marginBottom: '6px' }}>
            Customer ID (10 digits)
          </label>
          <select
            required
            value={customerId}
            onChange={e => setCustomerId(e.target.value)}
            style={{
              width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid #e2e8f0',
              fontFamily: 'JetBrains Mono, monospace', fontSize: '13px',
            }}
          >
            <option value="">Select…</option>
            {pending.customer_ids.map((id) => (
              <option key={id} value={id}>{id}</option>
            ))}
          </select>
        </div>

        <div>
          <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#475569', marginBottom: '6px' }}>
            Manager (MCC) ID — optional
          </label>
          <input
            value={loginCustomerId}
            onChange={e => setLoginCustomerId(e.target.value.replace(/\D/g, '').slice(0, 10))}
            placeholder="10-digit MCC if required"
            style={{
              width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid #e2e8f0',
              fontFamily: 'JetBrains Mono, monospace', fontSize: '13px',
            }}
          />
        </div>

        <div>
          <label style={{ display: 'block', fontSize: '12px', fontWeight: 600, color: '#475569', marginBottom: '6px' }}>
            Label — optional
          </label>
          <input
            value={label}
            onChange={e => setLabel(e.target.value)}
            placeholder="e.g. Main US account"
            style={{
              width: '100%', padding: '10px 12px', borderRadius: '8px', border: '1px solid #e2e8f0', fontSize: '13px',
            }}
          />
        </div>

        {err && !oauthErr && (
          <div style={{ display: 'flex', gap: '8px', alignItems: 'center', color: '#f43f5e', fontSize: '13px' }}>
            <AlertCircle size={14} />{err}
          </div>
        )}

        <div style={{ display: 'flex', gap: '10px' }}>
          <button
            type="submit"
            disabled={saveMutation.isPending}
            style={{
              flex: 1, padding: '12px', borderRadius: '10px', border: 'none', color: 'white', fontWeight: 700,
              fontFamily: 'Syne, sans-serif', cursor: saveMutation.isPending ? 'not-allowed' : 'pointer',
              background: saveMutation.isPending ? '#94a3b8' : 'linear-gradient(135deg, #f59e0b, #ea580c)',
            }}
          >Save account</button>
          <button
            type="button"
            onClick={() => navigate('/accounts')}
            style={{
              padding: '12px 18px', borderRadius: '10px', border: '1px solid #e2e8f0',
              background: 'white', cursor: 'pointer', fontFamily: 'inherit', fontSize: '13px', color: '#64748b',
            }}
          >Cancel</button>
        </div>
      </form>
    </div>
  )
}
