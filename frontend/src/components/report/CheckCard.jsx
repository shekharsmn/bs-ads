import { useState } from 'react'

const tabs = [
  { id: 'finding', label: 'Finding' },
  { id: 'evidence', label: 'Evidence' },
  { id: 'why', label: 'Why it matters' },
  { id: 'how', label: 'How we measured' },
  { id: 'fix', label: 'Fix' },
]

export default function CheckCard({ check, methodology, compare }) {
  const [open, setOpen] = useState(false)
  const [sub, setSub] = useState('finding')
  const m = methodology?.[check.check_id] || {}

  return (
    <div style={{ border: '1px solid #e2e8f0', borderRadius: '10px', background: '#fff', marginBottom: '10px' }}>
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        style={{
          width: '100%', textAlign: 'left', padding: '12px 14px', border: 'none', background: 'transparent',
          cursor: 'pointer', fontFamily: 'inherit',
        }}
      >
        <span style={{ fontFamily: 'JetBrains Mono, monospace', fontSize: '10px', color: '#94a3b8' }}>{check.check_id}</span>
        <span style={{ fontWeight: 700, fontSize: '14px', color: '#0f172a', marginLeft: '8px' }}>{check.name}</span>
        <span style={{ marginLeft: '8px', fontSize: '11px', color: '#64748b' }}>{check.status}{check.severity ? ` · ${check.severity}` : ''}</span>
        {Array.isArray(compare?.new_check_ids) && compare.new_check_ids.includes(check.check_id) && (
          <span style={{
            marginLeft: '6px', fontSize: '10px', fontWeight: 700, padding: '2px 6px', borderRadius: '4px',
            background: '#fef3c7', color: '#b45309',
          }}>new</span>
        )}
        {Array.isArray(compare?.resolved_check_ids) && compare.resolved_check_ids.includes(check.check_id) && (
          <span style={{
            marginLeft: '6px', fontSize: '10px', fontWeight: 700, padding: '2px 6px', borderRadius: '4px',
            background: '#f0fdf4', color: '#15803d', border: '1px solid #bbf7d0',
          }}>resolved</span>
        )}
        {check.impact_score != null && (
          <span style={{ float: 'right', fontSize: '11px', color: '#64748b' }}>impact {check.impact_score}</span>
        )}
      </button>
      {open && (
        <div style={{ borderTop: '1px solid #f1f5f9', padding: '10px 12px 14px' }}>
          <div style={{ display: 'flex', gap: '4px', flexWrap: 'wrap', marginBottom: '10px' }}>
            {tabs.map(t => (
              <button
                key={t.id}
                type="button"
                onClick={() => setSub(t.id)}
                style={{
                  padding: '4px 10px', borderRadius: '6px', border: 'none', cursor: 'pointer', fontSize: '11px', fontFamily: 'inherit',
                  background: sub === t.id ? '#0f172a' : '#f1f5f9', color: sub === t.id ? '#fff' : '#64748b', fontWeight: 600,
                }}
              >{t.label}</button>
            ))}
          </div>
          {sub === 'finding' && <p style={{ fontSize: '13px', color: '#475569', margin: 0 }}>{check.finding}</p>}
          {sub === 'evidence' && (
            <pre style={{
              fontSize: '11px', background: '#0f172a', color: '#e2e8f0', padding: '10px', borderRadius: '6px',
              overflow: 'auto', maxHeight: '220px', margin: 0,
            }}>{JSON.stringify(check.evidence || {}, null, 2)}</pre>
          )}
          {sub === 'why' && <p style={{ fontSize: '13px', color: '#475569', margin: 0 }}>{m.why_it_matters || '—'}</p>}
          {sub === 'how' && (
            <div style={{ fontSize: '13px', color: '#475569' }}>
              <p style={{ margin: '0 0 8px' }}>{m.how_we_measure || '—'}</p>
              {m.data_source && <p style={{ margin: 0, fontSize: '12px', color: '#94a3b8' }}><strong>Source:</strong> {m.data_source}</p>}
              {m.thresholds && <p style={{ margin: '8px 0 0', fontSize: '12px', color: '#94a3b8' }}><strong>Thresholds:</strong> {m.thresholds}</p>}
              {check.metrics && (
                <pre style={{ fontSize: '11px', marginTop: '10px', background: '#f8fafc', padding: '8px', borderRadius: '6px' }}>
                  {JSON.stringify(check.metrics, null, 2)}
                </pre>
              )}
            </div>
          )}
          {sub === 'fix' && <p style={{ fontSize: '13px', color: '#166534', margin: 0 }}>{check.recommendation || '—'}</p>}
        </div>
      )}
    </div>
  )
}
