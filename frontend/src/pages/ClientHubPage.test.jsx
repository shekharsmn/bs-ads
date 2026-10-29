import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { api } from '../api/client'
import ClientHubPage from './ClientHubPage'

vi.mock('../api/client', () => ({ api: { getClient: vi.fn(), getAdLibrarySearches: vi.fn(), getAdLibraryAudits: vi.fn(), deleteAdAccount: vi.fn() } }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' } }) }))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  api.getClient.mockResolvedValue({ data: { id: 7, name: 'Acme', timezone: 'UTC', currency: 'USD', objective: 'ecommerce', active: true, incidents_count: 2, report_schedules: [], ad_accounts: [{ id: 9, name: 'Acme Meta', platform: 'meta', external_id: 'act_9', currency: 'USD', timezone: 'UTC', status: 'active' }] } })
  api.deleteAdAccount.mockResolvedValue({ data: null })
})

function renderHub() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={['/clients/7?tab=accounts']}><Routes><Route path="/clients/:clientId" element={<ClientHubPage/>}/></Routes></MemoryRouter></QueryClientProvider>)
}

it('shows linked accounts on the URL-backed client hub', async () => {
  renderHub()
  expect(await screen.findByRole('heading', { name: 'Acme' })).toBeInTheDocument()
  expect(screen.getByRole('link', { name: /Acme Meta/ })).toHaveAttribute('href', '/command-center/clients/7/ad-accounts/9')
  expect(screen.getByRole('button', { name: /Ad accounts/ })).toHaveClass('active')
})

it('requires confirmation before deleting an ad account', async () => {
  renderHub()
  fireEvent.click(await screen.findByRole('button', { name: 'Delete Acme Meta' }))
  expect(screen.getByRole('alertdialog')).toHaveTextContent('permanently removes')
  fireEvent.click(screen.getByRole('button', { name: 'Delete account' }))
  await waitFor(() => expect(api.deleteAdAccount).toHaveBeenCalledWith(9))
})
