import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { expect, it, vi } from 'vitest'
import { PortalShell } from './ClientPortalPage'

it('keeps an admin preview pinned to the selected client without signing the admin out', () => {
  const logout = vi.fn()
  render(<MemoryRouter><PortalShell clients={[{ id: 7, name: 'Acme' }, { id: 8, name: 'Other' }]} clientId="7" logout={logout} preview><div>Client report</div></PortalShell></MemoryRouter>)

  expect(screen.getByText('Viewing as client')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Return to client' })).toHaveAttribute('href', '/clients/7')
  expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument()
  expect(screen.queryByRole('combobox', { name: 'Select client' })).not.toBeInTheDocument()
})
