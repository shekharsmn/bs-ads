import { useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'

export default function ConfirmDialog({ open, title, description, confirmLabel = 'Confirm', tone = 'danger', busy = false, onConfirm, onClose }) {
  const cancelRef = useRef(null)
  const dialogRef = useRef(null)
  const onCloseRef = useRef(onClose)
  const busyRef = useRef(busy)
  const titleId = useId()
  const descriptionId = useId()

  useEffect(() => {
    onCloseRef.current = onClose
    busyRef.current = busy
  }, [busy, onClose])

  useEffect(() => {
    if (!open) return undefined
    const previouslyFocused = document.activeElement
    const root = document.getElementById('root')
    const previousOverflow = document.body.style.overflow
    const previousInert = root?.inert
    if (root) root.inert = true
    document.body.style.overflow = 'hidden'

    const frame = requestAnimationFrame(() => cancelRef.current?.focus())
    const handleKeyDown = event => {
      if (event.key === 'Escape' && !busyRef.current) {
        event.preventDefault()
        onCloseRef.current()
        return
      }
      if (event.key !== 'Tab') return
      const focusable = [...(dialogRef.current?.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])') || [])]
      if (!focusable.length) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault(); last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus()
      }
    }
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      cancelAnimationFrame(frame)
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = previousOverflow
      if (root) root.inert = previousInert || false
      previouslyFocused?.focus?.()
    }
  }, [open])
  if (!open) return null

  return createPortal(<div className="dialog-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && !busy && onClose()}>
    <section ref={dialogRef} className="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={descriptionId}>
      <p className="ops-eyebrow">Please confirm</p>
      <h2 id={titleId}>{title}</h2>
      <div id={descriptionId} className="confirm-description">{description}</div>
      <div><button ref={cancelRef} onClick={onClose} disabled={busy}>Cancel</button><button className={tone} onClick={onConfirm} disabled={busy}>{busy ? 'Working…' : confirmLabel}</button></div>
    </section>
  </div>, document.body)
}
