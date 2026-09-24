import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'

/**
 * Print-friendly stacked view (browser Print → Save as PDF).
 */
export default function PrintReportPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { data: report, isLoading } = useQuery({
    queryKey: ['report', id],
    queryFn: () => api.getReport(id).then(r => r.data),
  })

  if (isLoading || !report || report.status !== 'complete') {
    return <div style={{ padding: '40px' }}>Loading…</div>
  }

  const checks = report.checks || []
  const issues = checks.filter(c => c.status !== 'PASS' && c.status !== 'SKIP')

  return (
    <div style={{ padding: '24px', maxWidth: '900px', margin: '0 auto', fontFamily: 'system-ui, sans-serif', color: '#0f172a' }}>
      <style>{`@media print { button { display: none !important; } }`}</style>
      <div style={{ marginBottom: '20px' }}>
        <button type="button" onClick={() => navigate(-1)} style={{ marginBottom: '12px', cursor: 'pointer' }}>Close</button>
        <button type="button" onClick={() => window.print()} style={{ marginLeft: '8px', cursor: 'pointer' }}>Print / Save PDF</button>
      </div>
      <h1 style={{ fontSize: '22px', margin: '0 0 8px' }}>Audit report #{report.id}</h1>
      <p style={{ color: '#64748b', marginTop: 0 }}>{report.platform} · Score {report.health_score} · {report.grade}</p>

      <section style={{ marginTop: '28px' }}>
        <h2 style={{ fontSize: '16px', borderBottom: '1px solid #e2e8f0', paddingBottom: '6px' }}>Checks</h2>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '11px' }}>
          <thead>
            <tr>
              {['ID', 'Status', 'Section', 'Name', 'Finding'].map(h => (
                <th key={h} style={{ textAlign: 'left', borderBottom: '1px solid #e2e8f0', padding: '6px' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {checks.map((c, i) => (
              <tr key={i}>
                <td style={{ padding: '6px', borderBottom: '1px solid #f1f5f9' }}>{c.check_id}</td>
                <td style={{ padding: '6px', borderBottom: '1px solid #f1f5f9' }}>{c.status}</td>
                <td style={{ padding: '6px', borderBottom: '1px solid #f1f5f9' }}>{c.section}</td>
                <td style={{ padding: '6px', borderBottom: '1px solid #f1f5f9' }}>{c.name}</td>
                <td style={{ padding: '6px', borderBottom: '1px solid #f1f5f9' }}>{c.finding}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section style={{ marginTop: '28px' }}>
        <h2 style={{ fontSize: '16px', borderBottom: '1px solid #e2e8f0', paddingBottom: '6px' }}>Open issues ({issues.length})</h2>
        {issues.map((c, i) => (
          <div key={i} style={{ marginBottom: '14px', pageBreakInside: 'avoid' }}>
            <strong>{c.check_id}</strong> — {c.name} ({c.status})
            <div style={{ color: '#475569', marginTop: '4px' }}>{c.finding}</div>
            {c.recommendation && <div style={{ marginTop: '4px' }}><em>Fix:</em> {c.recommendation}</div>}
          </div>
        ))}
      </section>
    </div>
  )
}
