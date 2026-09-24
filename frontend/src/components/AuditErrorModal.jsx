import { useEffect } from 'react'
import { X } from 'lucide-react'

/**
 * Modal for long audit / API error text (e.g. Google Ads INVALID_ARGUMENT JSON).
 */
export default function AuditErrorModal({ open, onClose, title = 'Error details', message }) {
  useEffect(() => {
    if (!open) return
    const onKey = (e) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open || !message) return null

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="audit-error-modal-title"
      style={{
        position: 'fixed', inset: 0, zIndex: 100,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        padding: '24px', background: 'rgba(15, 23, 42, 0.5)',
      }}
      onClick={onClose}
    >
      <div
        onClick={e => e.stopPropagation()}
        style={{
          width: '100%', maxWidth: '560px', maxHeight: 'min(80vh, 520px)',
          display: 'flex', flexDirection: 'column',
          background: 'white', borderRadius: '14px',
          border: '1px solid #e2e8f0', boxShadow: '0 25px 50px -12px rgba(0,0,0,0.25)',
          overflow: 'hidden',
        }}
      >
        <div style={{
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          padding: '16px 18px', borderBottom: '1px solid #f1f5f9',
        }}>
          <h2 id="audit-error-modal-title" style={{
            margin: 0, fontSize: '15px', fontWeight: 700, color: '#0f172a',
            fontFamily: 'Syne, sans-serif',
          }}>{title}</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            style={{
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              width: 36, height: 36, border: 'none', borderRadius: '8px',
              background: '#f8fafc', color: '#64748b', cursor: 'pointer',
            }}
          >
            <X size={18} />
          </button>
        </div>
        <pre style={{
          margin: 0, padding: '16px 18px', overflow: 'auto', flex: 1,
          fontSize: '11px', fontFamily: 'JetBrains Mono, monospace',
          whiteSpace: 'pre-wrap', wordBreak: 'break-word', color: '#be123c',
          background: '#fff1f2', lineHeight: 1.45,
        }}>{message}</pre>
        <div style={{ padding: '12px 18px', borderTop: '1px solid #f1f5f9', textAlign: 'right' }}>
          <button
            type="button"
            onClick={onClose}
            style={{
              padding: '8px 18px', borderRadius: '8px', border: '1px solid #e2e8f0',
              background: '#f8fafc', color: '#475569', fontSize: '13px', fontWeight: 600,
              fontFamily: 'inherit', cursor: 'pointer',
            }}
          >Close</button>
        </div>
      </div>
    </div>
  )
}
