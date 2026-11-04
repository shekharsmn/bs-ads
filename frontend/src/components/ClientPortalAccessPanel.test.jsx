import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import ClientPortalAccessPanel from './ClientPortalAccessPanel'

vi.mock('../api/client', () => ({ api: {
  getClientPortalAccess: vi.fn(), updateClientPortalAccess: vi.fn(), inviteUser: vi.fn(),
  resendUserInvitation: vi.fn(), revokeUserInvitation: vi.fn(),
} }))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

afterEach(cleanup)

const access = {
  client_id: 7,
  portal_enabled: true,
  client_users: [{ id: 2, name: 'Active Contact', email: 'active@client.example', status: 'active' }],
  client_invitations: [{ id: 12, name: 'Pending Contact', email: 'pending@client.example', status: 'pending', delivery_error: null }],
  accounts: [],
}

beforeEach(() => {
  vi.clearAllMocks()
  api.getClientPortalAccess.mockResolvedValue({ data: access })
  api.updateClientPortalAccess.mockResolvedValue({ data: access })
  api.inviteUser.mockResolvedValue({ data: {} })
  api.resendUserInvitation.mockResolvedValue({ data: {} })
  api.revokeUserInvitation.mockResolvedValue({ data: {} })
})

it('enables, discards, and saves only portal policy changes', async () => {
  renderPanel()
  const enablePortal = await screen.findByRole('checkbox', { name: /Enable client portal/ })
  const discard = screen.getByRole('button', { name: 'Discard changes' })
  const save = screen.getByRole('button', { name: 'Save client access' })
  expect(discard).toBeDisabled()
  expect(save).toBeDisabled()

  fireEvent.click(enablePortal)
  expect(discard).toBeEnabled()
  expect(save).toBeEnabled()
  fireEvent.click(discard)
  expect(enablePortal).toBeChecked()
  expect(discard).toBeDisabled()

  fireEvent.click(enablePortal)
  fireEvent.click(save)
  await waitFor(() => expect(api.updateClientPortalAccess).toHaveBeenCalledWith('7', { portal_enabled: false, accounts: [] }))
})

function renderPanel() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={queryClient}><ClientPortalAccessPanel clientId="7"/></QueryClientProvider>)
}

it('invites and manages client email access from the client access tab', async () => {
  renderPanel()
  expect(await screen.findByText('Active Contact')).toBeInTheDocument()
  expect(screen.getByText('Pending Contact')).toBeInTheDocument()

  fireEvent.change(screen.getByLabelText('Client contact name'), { target: { value: 'New Contact' } })
  fireEvent.change(screen.getByLabelText('Client email address'), { target: { value: 'new@outside.example' } })
  fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }))
  await waitFor(() => expect(api.inviteUser).toHaveBeenCalledWith({
    name: 'New Contact', email: 'new@outside.example', role: 'client', client_ids: [7],
    run_performance_audits: false, run_content_audits: false, use_data_inspector: false,
  }))

  fireEvent.click(screen.getByRole('button', { name: 'Resend invitation to pending@client.example' }))
  await waitFor(() => expect(api.resendUserInvitation).toHaveBeenCalledWith(12))
  fireEvent.click(screen.getByRole('button', { name: 'Revoke invitation for pending@client.example' }))
  fireEvent.click(screen.getByRole('button', { name: 'Revoke invitation' }))
  await waitFor(() => expect(api.revokeUserInvitation).toHaveBeenCalledWith(12))
})
