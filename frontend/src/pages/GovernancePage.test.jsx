import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import { ToastProvider } from '../context/ToastContext'
import GovernancePage from './GovernancePage'

vi.mock('../api/client', () => ({ api: {
  getGovernanceUsers: vi.fn(), getClients: vi.fn(), updateGovernanceUser: vi.fn(),
  getAuditEvents: vi.fn(), getNotificationDestinations: vi.fn(), getMethodologies: vi.fn(),
  createNotificationDestination: vi.fn(), deleteNotificationDestination: vi.fn(), testNotificationDestination: vi.fn(),
  getAuditEventsExportUrl: () => '/api/audit-events/export.csv',
} }))

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<QueryClientProvider client={client}><ToastProvider><GovernancePage/></ToastProvider></QueryClientProvider>)
}

describe('governance workspace', () => {
  afterEach(cleanup)

  beforeEach(() => {
    api.getGovernanceUsers.mockResolvedValue({ data: { data: [{ id: 2, name: 'Morgan', email: 'morgan@example.com', role: 'viewer', disabled_at: null, last_login_at: null, clients_count: 1, clients: [{ id: 4, name: 'Acme' }] }] } })
    api.getClients.mockResolvedValue({ data: [{ id: 4, name: 'Acme' }, { id: 5, name: 'Beta' }] })
    api.updateGovernanceUser.mockResolvedValue({ data: {} })
    api.getAuditEvents.mockResolvedValue({ data: { data: [] } })
    api.getNotificationDestinations.mockResolvedValue({ data: [] })
    api.getMethodologies.mockResolvedValue({ data: [] })
  })

  it('moves access management out of governance and defaults to the audit trail', async () => {
    renderPage()
    expect(await screen.findByRole('button', { name: 'Audit trail' })).toHaveClass('active')
    expect(screen.queryByRole('button', { name: 'Access' })).not.toBeInTheDocument()
    expect(api.getGovernanceUsers).not.toHaveBeenCalled()
  })

  it('retains the governance destinations workspace', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Destinations' }))
    expect(await screen.findByText('Add destination')).toBeInTheDocument()
  })
})
