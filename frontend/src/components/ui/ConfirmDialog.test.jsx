import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ConfirmDialog from './ConfirmDialog'

function Harness({ busy = false, onConfirm = vi.fn() }) {
  const [open, setOpen] = useState(false)
  return <><button onClick={() => setOpen(true)}>Open dialog</button><ConfirmDialog open={open} title="Delete item?" description="This cannot be undone." busy={busy} onClose={() => setOpen(false)} onConfirm={onConfirm}/></>
}

describe('ConfirmDialog', () => {
  afterEach(() => { cleanup(); document.body.innerHTML = '' })

  it('traps focus, makes the app inert, and restores focus after closing', async () => {
    const root = document.createElement('div')
    root.id = 'root'
    document.body.appendChild(root)
    render(<Harness/>, { container: root })
    const trigger = screen.getByRole('button', { name: 'Open dialog' })
    trigger.focus()
    fireEvent.click(trigger)

    const cancel = await screen.findByRole('button', { name: 'Cancel' })
    const confirm = screen.getByRole('button', { name: 'Confirm' })
    await waitFor(() => expect(cancel).toHaveFocus())
    expect(root.inert).toBe(true)
    confirm.focus()
    fireEvent.keyDown(document, { key: 'Tab' })
    expect(cancel).toHaveFocus()

    fireEvent.keyDown(document, { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(trigger).toHaveFocus()
    expect(root.inert).toBe(false)
  })

  it('does not close from Escape while busy', async () => {
    render(<Harness busy/>)
    fireEvent.click(screen.getByRole('button', { name: 'Open dialog' }))
    await screen.findByRole('alertdialog')
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.getByRole('alertdialog')).toBeInTheDocument()
  })
})
