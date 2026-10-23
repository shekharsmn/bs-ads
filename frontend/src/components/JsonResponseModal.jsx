import { useEffect, useState } from 'react'
import { Check, Copy, X } from 'lucide-react'
import { copyText } from '../utils/clipboard'

export default function JsonResponseModal({ open, onClose, title, value }) {
  const [copied, setCopied] = useState(false)

  useEffect(() => {
    if (!open) return undefined
    const onKeyDown = event => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [open, onClose])

  if (!open) return null

  const json = JSON.stringify(value ?? null, null, 2)
  const copy = async () => {
    await copyText(json)
    setCopied(true)
    window.setTimeout(() => setCopied(false), 1800)
  }

  return <div role="dialog" aria-modal="true" aria-labelledby="json-response-title" style={backdrop} onClick={onClose}>
    <div style={modal} onClick={event => event.stopPropagation()}>
      <header style={header}>
        <div><p style={eyebrow}>Stored provider payload</p><h2 id="json-response-title" style={titleStyle}>{title}</h2></div>
        <button type="button" aria-label="Close JSON viewer" onClick={onClose} style={iconButton}><X size={17} /></button>
      </header>
      <pre style={code}>{json}</pre>
      <footer style={footer}>
        <span style={hint}>Formatted JSON · {new Blob([json]).size.toLocaleString()} bytes</span>
        <button type="button" onClick={copy} style={copyButton}>{copied ? <Check size={14} /> : <Copy size={14} />}{copied ? 'Copied' : 'Copy JSON'}</button>
      </footer>
    </div>
  </div>
}

const backdrop = { position: 'fixed', inset: 0, zIndex: 100, display: 'grid', placeItems: 'center', padding: 24, background: 'rgba(15,23,42,.58)', backdropFilter: 'blur(3px)' }
const modal = { width: 'min(860px, 100%)', maxHeight: 'min(84vh, 760px)', display: 'flex', flexDirection: 'column', overflow: 'hidden', border: '1px solid #334155', borderRadius: 14, background: '#0f172a', boxShadow: '0 28px 70px rgba(15,23,42,.35)' }
const header = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, padding: '15px 18px', borderBottom: '1px solid #263449', background: '#111c30' }
const eyebrow = { margin: '0 0 3px', color: '#60a5fa', fontSize: 9, fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase' }
const titleStyle = { margin: 0, color: '#f8fafc', fontFamily: 'Syne, sans-serif', fontSize: 15 }
const iconButton = { width: 32, height: 32, display: 'grid', placeItems: 'center', flex: '0 0 auto', border: '1px solid #334155', borderRadius: 7, background: '#1e293b', color: '#cbd5e1', cursor: 'pointer' }
const code = { flex: 1, minHeight: 260, margin: 0, padding: 18, overflow: 'auto', background: '#0f172a', color: '#cbd5e1', font: '11px/1.55 "JetBrains Mono", monospace', whiteSpace: 'pre-wrap', wordBreak: 'break-word', tabSize: 2 }
const footer = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, padding: '11px 18px', borderTop: '1px solid #263449', background: '#111c30' }
const hint = { color: '#64748b', fontSize: 10 }
const copyButton = { display: 'inline-flex', alignItems: 'center', gap: 6, minHeight: 34, padding: '7px 12px', border: '1px solid #3b82f6', borderRadius: 7, background: '#2563eb', color: '#fff', fontFamily: 'inherit', fontSize: 11, fontWeight: 700, cursor: 'pointer' }
