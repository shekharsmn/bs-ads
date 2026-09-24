import { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api/client'
import { ArrowLeft, CheckCircle, AlertCircle, Eye, EyeOff, Info } from 'lucide-react'

const fields = [
  { key: 'label', label: 'Account Label', type: 'text', placeholder: 'My Meta Account', help: 'A friendly name to identify this account' },
  { key: 'account_id', label: 'Ad Account ID', type: 'text', placeholder: 'act_123456789', help: 'Found in Meta Ads Manager. Must include "act_" prefix' },
  { key: 'app_id', label: 'App ID', type: 'text', placeholder: '1234567890', help: 'From Meta Developer App' },
  { key: 'app_secret', label: 'App Secret', type: 'password', placeholder: 'Leave blank to keep current value', help: 'From Meta Developer App settings', sensitive: true },
  { key: 'access_token', label: 'Access Token', type: 'password', placeholder: 'Leave blank to keep current value', help: 'System user or page access token with ads_read permission', sensitive: true },
]

export default function EditAccountPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [showFields, setShowFields] = useState({})
  const [error, setError] = useState('')
  const [form, setForm] = useState(null)

  const { data: accounts, isLoading } = useQuery({
    queryKey: ['accounts'],
    queryFn: () => api.getAccounts().then(r => r.data),
  })

  useEffect(() => {
    if (!accounts || form) return
    const account = accounts.find(a => String(a.id) === String(id))
    if (account) {
      setForm({
        label: account.label || '',
        account_id: account.account_id || '',
        app_id: account.app_id || '',
        app_secret: '',
        access_token: '',
      })
    }
  }, [accounts, id])

  const mutation = useMutation({
    mutationFn: (data) => api.updateAccount(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounts'] })
      navigate('/accounts')
    },
    onError: (err) => {
      setError(err.response?.data?.error || err.response?.data?.message || 'Failed to update account.')
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    setError('')
    mutation.mutate(form)
  }

  if (isLoading || !form) {
    return (
      <div style={{ padding: '32px', maxWidth: '600px', margin: '0 auto' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
          <div className="skeleton" style={{ height: 32, width: 200, borderRadius: 8 }} />
          <div className="skeleton" style={{ height: 400, borderRadius: 14 }} />
        </div>
      </div>
    )
  }

  return (
    <div style={{ padding: '32px', maxWidth: '600px', margin: '0 auto' }}>
      <div className="fade-up" style={{ marginBottom: '28px' }}>
        <button
          onClick={() => navigate('/accounts')}
          style={{
            display: 'flex', alignItems: 'center', gap: '6px',
            background: 'none', border: 'none', cursor: 'pointer',
            color: '#64748b', fontSize: '13px', fontFamily: 'inherit',
            padding: '0 0 16px', transition: 'color 0.15s',
          }}
          onMouseEnter={e => e.currentTarget.style.color = '#0f172a'}
          onMouseLeave={e => e.currentTarget.style.color = '#64748b'}
        >
          <ArrowLeft size={14} />
          Back to Accounts
        </button>

        <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '24px', fontWeight: 700, color: '#0f172a', margin: 0, letterSpacing: '-0.02em' }}>
          Edit Meta Account
        </h1>
        <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: '13px' }}>
          Leave App Secret and Access Token blank to keep current values.
        </p>
      </div>

      {mutation.isSuccess && (
        <div className="fade-up" style={{
          display: 'flex', alignItems: 'center', gap: '10px',
          background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: '10px',
          padding: '14px 18px', marginBottom: '20px', color: '#16a34a',
        }}>
          <CheckCircle size={16} />
          <span style={{ fontSize: '13px', fontWeight: 600 }}>Account updated successfully! Redirecting...</span>
        </div>
      )}

      {error && (
        <div className="fade-up" style={{
          display: 'flex', alignItems: 'flex-start', gap: '10px',
          background: '#fff1f2', border: '1px solid #fecdd3', borderRadius: '10px',
          padding: '14px 18px', marginBottom: '20px', color: '#f43f5e',
        }}>
          <AlertCircle size={16} style={{ flexShrink: 0, marginTop: 1 }} />
          <span style={{ fontSize: '13px' }}>{error}</span>
        </div>
      )}

      <div className="fade-up-delay-1" style={{
        background: 'white', border: '1px solid #e2e8f0', borderRadius: '14px', padding: '32px',
      }}>
        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>
          {fields.map(({ key, label, type, placeholder, help, sensitive }) => {
            const isShown = showFields[key]
            const inputType = sensitive && !isShown ? 'password' : 'text'
            return (
              <div key={key}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '6px' }}>
                  <label style={{
                    fontSize: '12px', fontWeight: 600, color: '#475569',
                    letterSpacing: '0.04em', textTransform: 'uppercase',
                  }}>{label}</label>
                  <div style={{ position: 'relative' }} title={help}>
                    <Info size={11} color="#94a3b8" style={{ cursor: 'help' }} />
                  </div>
                </div>
                <div style={{ position: 'relative' }}>
                  <input
                    type={inputType}
                    required={key === 'account_id' || key === 'app_id'}
                    value={form[key]}
                    onChange={e => setForm(f => ({ ...f, [key]: e.target.value }))}
                    placeholder={placeholder}
                    style={{
                      width: '100%', padding: sensitive ? '10px 40px 10px 14px' : '10px 14px',
                      border: '1px solid #e2e8f0', borderRadius: '8px',
                      fontSize: '13px', fontFamily: key === 'account_id' || key === 'app_id' ? 'JetBrains Mono, monospace' : 'inherit',
                      color: '#0f172a', background: '#f8fafc', outline: 'none',
                      transition: 'border-color 0.15s, background 0.15s',
                      boxSizing: 'border-box',
                    }}
                    onFocus={e => { e.target.style.borderColor = '#3b82f6'; e.target.style.background = 'white' }}
                    onBlur={e => { e.target.style.borderColor = '#e2e8f0'; e.target.style.background = '#f8fafc' }}
                  />
                  {sensitive && (
                    <button
                      type="button"
                      onClick={() => setShowFields(s => ({ ...s, [key]: !s[key] }))}
                      style={{
                        position: 'absolute', right: '12px', top: '50%', transform: 'translateY(-50%)',
                        background: 'none', border: 'none', cursor: 'pointer',
                        color: '#94a3b8', padding: 0, display: 'flex', alignItems: 'center',
                      }}
                    >
                      {isShown ? <EyeOff size={14} /> : <Eye size={14} />}
                    </button>
                  )}
                </div>
                <p style={{ margin: '5px 0 0', fontSize: '11px', color: '#94a3b8' }}>{help}</p>
              </div>
            )
          })}

          <div style={{ display: 'flex', gap: '12px', paddingTop: '8px', borderTop: '1px solid #f1f5f9', marginTop: '4px' }}>
            <button
              type="button"
              onClick={() => navigate('/accounts')}
              style={{
                flex: 1, padding: '11px',
                background: '#f8fafc', border: '1px solid #e2e8f0',
                borderRadius: '9px', color: '#64748b', fontSize: '13px',
                fontWeight: 600, fontFamily: 'inherit', cursor: 'pointer',
              }}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={mutation.isPending}
              style={{
                flex: 2, padding: '11px',
                background: mutation.isPending ? 'rgba(59,130,246,0.6)' : 'linear-gradient(135deg, #3b82f6, #2563eb)',
                border: 'none', borderRadius: '9px', color: 'white',
                fontSize: '13px', fontWeight: 700, fontFamily: 'Syne, sans-serif',
                cursor: mutation.isPending ? 'not-allowed' : 'pointer',
                letterSpacing: '0.02em',
                boxShadow: mutation.isPending ? 'none' : '0 4px 14px rgba(59,130,246,0.25)',
              }}
            >
              {mutation.isPending ? 'Saving...' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
