import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { Plus, Trash2, Pencil, Globe, DollarSign, AlertCircle, Search } from 'lucide-react'
import { useMemo, useState } from 'react'

export default function AccountsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteError, setDeleteError] = useState('')

  const { data: metaAccounts = [], isLoading: metaLoading } = useQuery({
    queryKey: ['accounts'],
    queryFn: () => api.getAccounts().then(r => r.data),
  })

  const { data: googleAccounts = [], isLoading: googleLoading } = useQuery({
    queryKey: ['google-accounts'],
    queryFn: () => api.getGoogleAccounts().then(r => r.data),
  })

  const rows = useMemo(() => {
    const m = (metaAccounts || []).map(a => ({
      ...a,
      platform: 'meta',
      key: `meta-${a.id}`,
      displayId: a.account_id,
    }))
    const g = (googleAccounts || []).map(a => ({
      ...a,
      platform: 'google',
      key: `google-${a.id}`,
      displayId: a.customer_id,
    }))
    return [...m, ...g]
  }, [metaAccounts, googleAccounts])

  const isLoading = metaLoading || googleLoading

  const deleteMutation = useMutation({
    mutationFn: async ({ platform, id }) => {
      if (platform === 'meta') return api.deleteAccount(id)
      return api.deleteGoogleAccount(id)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounts'] })
      queryClient.invalidateQueries({ queryKey: ['google-accounts'] })
    },
    onError: (err) => setDeleteError(err.response?.data?.message || 'Failed to delete account.'),
  })

  const handleDelete = (row) => {
    const label = row.label || row.account_name || row.displayId
    if (confirm(`Remove "${label}"? This will not delete your reports.`)) {
      deleteMutation.mutate({ platform: row.platform, id: row.id })
    }
  }

  const connectGoogle = () => {
    window.location.href = '/api/oauth/google/redirect'
  }

  return (
    <div style={{ padding: '32px', maxWidth: '800px', margin: '0 auto' }}>
      <div className="fade-up" style={{ marginBottom: '28px', display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '12px' }}>
        <div>
          <h1 style={{ fontFamily: 'Syne, sans-serif', fontSize: '24px', fontWeight: 700, color: '#0f172a', margin: 0, letterSpacing: '-0.02em' }}>
            Connected accounts
          </h1>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: '13px' }}>
            Meta and Google Ads accounts you can audit
          </p>
        </div>
        <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
          <button
            onClick={connectGoogle}
            type="button"
            style={{
              display: 'flex', alignItems: 'center', gap: '8px',
              padding: '9px 18px', background: 'linear-gradient(135deg, #f59e0b, #ea580c)',
              border: 'none', borderRadius: '9px', color: 'white',
              fontSize: '13px', fontWeight: 600, fontFamily: 'Syne, sans-serif',
              cursor: 'pointer', boxShadow: '0 4px 14px rgba(245,158,11,0.25)',
            }}
          >
            <Search size={14} strokeWidth={2.5} />
            Connect Google Ads
          </button>
          <button
            onClick={() => navigate('/accounts/new')}
            style={{
              display: 'flex', alignItems: 'center', gap: '8px',
              padding: '9px 18px', background: 'linear-gradient(135deg, #3b82f6, #2563eb)',
              border: 'none', borderRadius: '9px', color: 'white',
              fontSize: '13px', fontWeight: 600, fontFamily: 'Syne, sans-serif',
              cursor: 'pointer', boxShadow: '0 4px 14px rgba(59,130,246,0.25)',
            }}
          >
            <Plus size={14} strokeWidth={2.5} />
            Add Meta account
          </button>
        </div>
      </div>

      {deleteError && (
        <div className="fade-up" style={{
          display: 'flex', alignItems: 'center', gap: '8px',
          background: '#fff1f2', border: '1px solid #fecdd3',
          borderRadius: '8px', padding: '10px 14px', marginBottom: '16px',
          color: '#f43f5e', fontSize: '13px',
        }}>
          <AlertCircle size={14} />{deleteError}
        </div>
      )}

      {isLoading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
          {[1, 2].map(i => (
            <div key={i} className="skeleton" style={{ height: 80, borderRadius: 12 }} />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <div className="fade-up-delay-1" style={{
          background: 'white', border: '2px dashed #e2e8f0',
          borderRadius: '12px', padding: '60px 24px', textAlign: 'center',
        }}>
          <div style={{
            width: 56, height: 56, background: '#f1f5f9', borderRadius: '50%',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            margin: '0 auto 16px',
          }}>
            <Globe size={24} color="#94a3b8" />
          </div>
          <p style={{ fontFamily: 'Syne, sans-serif', fontWeight: 700, fontSize: '15px', color: '#0f172a', margin: '0 0 6px' }}>
            No accounts connected
          </p>
          <p style={{ fontSize: '13px', color: '#94a3b8', margin: '0 0 20px' }}>
            Connect Meta credentials or sign in with Google Ads
          </p>
          <div style={{ display: 'flex', gap: '10px', justifyContent: 'center', flexWrap: 'wrap' }}>
            <button
              onClick={connectGoogle}
              type="button"
              style={{
                padding: '9px 20px', background: '#f59e0b', border: 'none',
                borderRadius: '8px', color: 'white', fontSize: '13px', fontWeight: 600,
                fontFamily: 'Syne, sans-serif', cursor: 'pointer',
              }}
            >Connect Google Ads</button>
            <button
              onClick={() => navigate('/accounts/new')}
              style={{
                padding: '9px 20px', background: '#3b82f6', border: 'none',
                borderRadius: '8px', color: 'white', fontSize: '13px', fontWeight: 600,
                fontFamily: 'Syne, sans-serif', cursor: 'pointer',
              }}
            >Add Meta account</button>
          </div>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
          {rows.map((account, i) => (
            <div
              key={account.key}
              className={`fade-up-delay-${Math.min(i + 1, 5)}`}
              style={{
                background: 'white', border: '1px solid #e2e8f0',
                borderRadius: '12px', padding: '20px 24px',
                display: 'flex', alignItems: 'center', gap: '16px',
              }}
            >
              <div style={{
                width: 44, height: 44, borderRadius: '10px',
                background: account.platform === 'google'
                  ? 'linear-gradient(135deg, #fffbeb, #fef3c7)'
                  : 'linear-gradient(135deg, #eff6ff, #dbeafe)',
                border: account.platform === 'google' ? '1px solid #fde68a' : '1px solid #bfdbfe',
                display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
              }}>
                {account.platform === 'google'
                  ? <Search size={18} color="#d97706" />
                  : <Globe size={18} color="#3b82f6" />}
              </div>

              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
                  <p style={{ margin: 0, fontWeight: 700, fontSize: '14px', color: '#0f172a' }}>
                    {account.label}
                  </p>
                  <span style={{
                    fontSize: '10px', fontWeight: 700, padding: '2px 8px', borderRadius: '6px',
                    background: account.platform === 'google' ? '#fef3c7' : '#eff6ff',
                    color: account.platform === 'google' ? '#b45309' : '#2563eb',
                  }}>{account.platform === 'google' ? 'Google Ads' : 'Meta'}</span>
                  {account.account_name && account.account_name !== account.label && (
                    <span style={{ fontSize: '12px', color: '#64748b' }}>· {account.account_name}</span>
                  )}
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '12px', marginTop: '4px' }}>
                  <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '11px', color: '#94a3b8' }}>
                    {account.displayId}
                  </span>
                  {account.currency && (
                    <span style={{ display: 'flex', alignItems: 'center', gap: '3px', fontSize: '11px', color: '#94a3b8' }}>
                      <DollarSign size={10} />{account.currency}
                    </span>
                  )}
                </div>
              </div>

              <div style={{ display: 'flex', gap: '8px', flexShrink: 0 }}>
                {account.platform === 'meta' && (
                  <button
                    onClick={() => navigate(`/accounts/${account.id}/edit`)}
                    style={{
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                      width: 34, height: 34, borderRadius: '8px',
                      border: '1px solid #e2e8f0', background: 'transparent',
                      cursor: 'pointer', color: '#94a3b8',
                    }}
                  >
                    <Pencil size={13} />
                  </button>
                )}
                <button
                  onClick={() => handleDelete(account)}
                  disabled={deleteMutation.isPending}
                  style={{
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    width: 34, height: 34, borderRadius: '8px',
                    border: '1px solid #e2e8f0', background: 'transparent',
                    cursor: 'pointer', color: '#94a3b8',
                  }}
                >
                  <Trash2 size={13} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
