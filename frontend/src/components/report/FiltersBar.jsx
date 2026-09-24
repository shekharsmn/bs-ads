const selectStyle = {
  padding: '6px 10px',
  borderRadius: '8px',
  border: '1px solid #e2e8f0',
  fontSize: '12px',
  fontFamily: 'inherit',
  background: '#fff',
  color: '#475569',
  minWidth: '140px',
}

/**
 * Section + severity filters for action plan / quick wins lists.
 */
export default function FiltersBar({
  sections,
  section,
  onSection,
  severity,
  onSeverity,
  onClear,
}) {
  const hasFilter = Boolean(section) || Boolean(severity)

  return (
    <div style={{
      display: 'flex', flexWrap: 'wrap', gap: '10px', alignItems: 'center',
      marginBottom: '14px', fontSize: '12px',
    }}
    >
      <span style={{ color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', fontSize: '10px', letterSpacing: '0.06em' }}>Filter</span>
      <select
        value={section}
        onChange={e => onSection(e.target.value)}
        style={selectStyle}
        aria-label="Section"
      >
        <option value="">All sections</option>
        {sections.map(s => (
          <option key={s} value={s}>{s}</option>
        ))}
      </select>
      <select
        value={severity}
        onChange={e => onSeverity(e.target.value)}
        style={selectStyle}
        aria-label="Severity"
      >
        <option value="">All severities</option>
        {['Critical', 'High', 'Medium', 'Low'].map(s => (
          <option key={s} value={s}>{s}</option>
        ))}
      </select>
      {hasFilter && (
        <button
          type="button"
          onClick={onClear}
          style={{
            padding: '6px 12px', borderRadius: '8px', border: '1px solid #e2e8f0',
            background: '#f8fafc', fontSize: '12px', fontWeight: 600, cursor: 'pointer',
            fontFamily: 'inherit', color: '#64748b',
          }}
        >Clear</button>
      )}
    </div>
  )
}
