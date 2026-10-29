import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import { ToastProvider } from '../context/ToastContext'
import UsersPage from './UsersPage'

vi.mock('../api/client', () => ({ api: {
  getUserDirectory: vi.fn(), getClients: vi.fn(), updateGovernanceUser: vi.fn(), updateUserInvitation: vi.fn(),
  inviteUser: vi.fn(), resendUserInvitation: vi.fn(), revokeUserInvitation: vi.fn(), getGovernanceUserHistory: vi.fn(),
} }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' } }) }))

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<QueryClientProvider client={client}><ToastProvider><UsersPage/></ToastProvider></QueryClientProvider>)
}

describe('user management', () => {
  afterEach(cleanup)
  beforeEach(() => {
    api.getUserDirectory.mockResolvedValue({ data: { summary: { active: 1, pending: 0, disabled: 0, admins: 1 }, data: [{ type: 'user', id: 2, name: 'Morgan', email: 'morgan@brandshark.com', role: 'viewer', status: 'active', clients_count: 1, clients: [{ id: 4, name: 'Acme' }], run_performance_audits: false, run_content_audits: false, use_data_inspector: false, last_login_at: null }] } })
    api.getClients.mockResolvedValue({ data: [{ id: 4, name: 'Acme' }, { id: 5, name: 'Beta' }] })
    api.updateGovernanceUser.mockResolvedValue({ data: {} })
    api.inviteUser.mockResolvedValue({ data: {} })
    api.getGovernanceUserHistory.mockResolvedValue({ data: { data: [] } })
  })

  it('shows the directory and updates an explicit audit permission', async () => {
    renderPage()
    expect(await screen.findByText('Morgan')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('switch', { name: 'Account audits' }))
    await waitFor(() => expect(api.updateGovernanceUser).toHaveBeenCalledWith(2, { run_performance_audits: true }))
  })

  it('creates an invitation with role, clients, and permissions', async () => {
    renderPage()
    await screen.findByText('Morgan')
    fireEvent.click(screen.getByRole('button', { name: 'Invite user' }))
    fireEvent.change(screen.getByLabelText('Workspace email'), { target: { value: 'new@brandshark.com' } })
    fireEvent.change(screen.getByLabelText('Role'), { target: { value: 'marketer' } })
    fireEvent.click(screen.getAllByText('Beta').at(-1))
    fireEvent.click(screen.getAllByRole('switch', { name: 'Ad Library audits' }).at(-1))
    fireEvent.click(screen.getAllByRole('switch', { name: 'Data Inspector' }).at(-1))
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }))

    await waitFor(() => expect(api.inviteUser).toHaveBeenCalledWith(expect.objectContaining({
      email: 'new@brandshark.com', role: 'marketer', client_ids: [5], run_content_audits: true, use_data_inspector: true,
    })))
  })
})
