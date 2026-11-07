import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { api } from '../api/client'
import ClientHubPage from './ClientHubPage'

vi.mock('../api/client', () => ({ api: { getClient: vi.fn(), getAdLibrarySearches: vi.fn(), getAdLibraryAudits: vi.fn(), deleteAdAccount: vi.fn(), getConnections: vi.fn(), getShopifyConnection: vi.fn(), getShopifyReport: vi.fn() } }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' }, features: { shopify: true } }) }))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  api.getClient.mockResolvedValue({ data: { id: 7, name: 'Acme', timezone: 'UTC', currency: 'USD', objective: 'ecommerce', active: true, incidents_count: 2, report_schedules: [], ad_accounts: [{ id: 9, name: 'Acme Meta', platform: 'meta', external_id: 'act_9', currency: 'USD', timezone: 'UTC', status: 'active' }] } })
  api.deleteAdAccount.mockResolvedValue({ data: null })
  api.getConnections.mockResolvedValue({ data: [] })
  api.getShopifyConnection.mockResolvedValue({ data: { store: null } })
  api.getShopifyReport.mockResolvedValue({ data: { store: null } })
})

function renderHub(entry = '/clients/7?tab=accounts') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[entry]}><Routes><Route path="/clients/:clientId" element={<ClientHubPage/>}/></Routes></MemoryRouter></QueryClientProvider>)
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

it('shows Shopify inside Connections without a Commerce tab', async () => {
  renderHub('/clients/7?tab=connections')
  expect(await screen.findByRole('heading', { name: 'Shopify' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Connections' })).toHaveClass('active')
  expect(screen.queryByRole('button', { name: 'Commerce' })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Connect Shopify' })).toBeInTheDocument()
})

it('opens Connections for old commerce links', async () => {
  renderHub('/clients/7?tab=commerce')
  expect(await screen.findByRole('heading', { name: 'Shopify' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Connections' })).toHaveClass('active')
  expect(screen.queryByRole('button', { name: 'Commerce' })).not.toBeInTheDocument()
})

it('does not load Shopify for lead-generation-only clients', async () => {
  api.getClient.mockResolvedValue({ data: { id: 7, name: 'Lead client', timezone: 'UTC', currency: 'USD', objective: 'lead_gen', ad_accounts: [] } })
  renderHub('/clients/7?tab=connections')
  expect(await screen.findByRole('heading', { name: 'Lead client' })).toBeInTheDocument()
  expect(screen.queryByRole('region', { name: 'Shopify connection' })).not.toBeInTheDocument()
  expect(api.getShopifyConnection).not.toHaveBeenCalled()
  expect(api.getShopifyReport).not.toHaveBeenCalled()
})
