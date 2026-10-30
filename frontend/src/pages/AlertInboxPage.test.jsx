import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { api } from '../api/client'
import { ToastProvider } from '../context/ToastContext'
import AlertInboxPage from './AlertInboxPage'

vi.mock('../api/client', () => ({
  api: {
    getAlerts: vi.fn(),
    getAlert: vi.fn(),
    getClients: vi.fn(),
    alertAction: vi.fn(),
    bulkAlertAction: vi.fn(),
  },
}))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' } }) }))

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  api.getClients.mockResolvedValue({ data: [{ id: 4, name: 'Acme', ad_accounts: [{ id: 8, name: 'Acme Meta' }] }] })
  api.getAlerts.mockResolvedValue({ data: { data: [], last_page: 1 } })
  api.getAlert.mockResolvedValue({
    data: {
      id: 'incident-1', client_id: 4, ad_account_id: 8, campaign_id: 12, ad_group_id: 15, ad_id: 19,
      severity: 'critical', status: 'open', title: 'Spend without conversions', summary: 'Spend was recorded without conversions',
      currency: 'INR', observed_spend: '250.000000', baseline_spend: '175.000000', observed_value: '0.000000', baseline_value: '3.000000', threshold_value: null,
      observed_period_start: '2026-10-29T18:30:00Z', observed_period_end: '2026-10-30T18:29:59Z', baseline_period_start: '2026-10-28T18:30:00Z', baseline_period_end: '2026-10-29T18:29:59Z',
      client: { id: 4, name: 'Acme' }, ad_account: { id: 8, name: 'Acme Meta', platform: 'meta', currency: 'INR', timezone: 'Asia/Kolkata' },
      campaign: { id: 12, name: 'Launch' }, ad_group: { id: 15, name: 'Prospecting' }, ad: { id: 19, name: 'Creative A' }, rule: { metric: 'conversions' }, events: [],
    },
  })
})

it('shows spend, dated windows, and linked alert hierarchy', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(<QueryClientProvider client={queryClient}><ToastProvider><MemoryRouter initialEntries={['/alerts/incident-1?client_id=4']}><Routes><Route path="/alerts/:id" element={<AlertInboxPage/>}/></Routes></MemoryRouter></ToastProvider></QueryClientProvider>)

  expect(await screen.findByText('Spend without conversions')).toBeInTheDocument()
  expect(screen.getByText(/₹250\.00/)).toBeInTheDocument()
  expect(screen.getByText(/₹175\.00/)).toBeInTheDocument()
  expect(screen.getByText(/(?:Oct 30,|30 Oct) 2026/)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: /Client\s*Acme/ })).toHaveAttribute('href', '/clients/4')
  expect(screen.getByRole('link', { name: /Campaign\s*Launch/ })).toHaveAttribute('href', '/command-center/clients/4/ad-accounts/8/campaigns/12')
  expect(screen.getByRole('link', { name: /Ad set\s*Prospecting/ })).toHaveAttribute('href', '/command-center/clients/4/ad-accounts/8/campaigns/12/ad-groups/15')
  expect(screen.getByRole('link', { name: /Ad\s*Creative A/ })).toHaveAttribute('href', '/command-center/clients/4/ad-accounts/8/campaigns/12/ad-groups/15/ads/19')
})
