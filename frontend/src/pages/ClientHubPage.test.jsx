import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { api } from '../api/client'
import ClientHubPage from './ClientHubPage'

vi.mock('../api/client', () => ({
  api: {
    getClient: vi.fn(),
    getClientPerformance: vi.fn(),
    getAdLibrarySearches: vi.fn(),
    getAdLibraryAudits: vi.fn(),
    deleteAdAccount: vi.fn(),
    getConnections: vi.fn(),
    getShopifyConnection: vi.fn(),
    getShopifyReport: vi.fn(),
  },
}))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' }, features: { shopify: true } }) }))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('recharts', () => ({ ResponsiveContainer: ({ children }) => <div>{children}</div>, ComposedChart: ({ children, data }) => <svg data-testid="client-trend-chart" data-first-spend={data?.[0]?.spend} data-last-spend={data?.at(-1)?.spend}>{children}</svg>, Area: () => null, CartesianGrid: () => null, Line: () => null, XAxis: () => null, YAxis: () => null, Tooltip: () => null }))

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  api.getClient.mockResolvedValue({ data: { id: 7, name: 'Acme', timezone: 'UTC', currency: 'USD', objective: 'ecommerce', active: true, portal_enabled: true, incidents_count: 2, report_schedules: [], ad_accounts: [{ id: 9, name: 'Acme Meta', platform: 'meta', external_id: 'act_9', currency: 'USD', timezone: 'UTC', status: 'active' }] } })
  api.getClientPerformance.mockResolvedValue({ data: { reporting_dates: { start: '2026-10-01', end: '2026-10-30' }, currency_groups: [{ currency: 'USD', freshness_timestamp: '2026-10-31T01:00:00Z', metrics: { spend: 350, purchases: 7, cpp: 50, conversion_value: 1400, roas: 4 }, series: [{ date: '2026-10-29', metrics: { spend: 200, purchases: 4, cpp: 50, conversion_value: 800, roas: 4 } }, { date: '2026-10-30', spend: 250 }] }], accounts: [{ account: { id: 9, name: 'Acme Meta', platform: 'meta', external_id: 'act_9', currency: 'USD', timezone: 'UTC', status: 'active' }, freshness_timestamp: '2026-10-31T01:00:00Z', metrics: { spend: 200, purchases: 4, cpp: 50, conversion_value: 800, roas: 4 } }, { account: { id: 10, name: 'Acme Google', platform: 'google', external_id: '10', currency: 'USD', timezone: 'UTC', status: 'active' }, freshness_timestamp: '2026-10-31T01:00:00Z', metrics: { spend: 150, purchases: 3, cpp: 50, conversion_value: 600, roas: 4 } }] } })
  api.deleteAdAccount.mockResolvedValue({ data: null })
  api.getConnections.mockResolvedValue({ data: [] })
  api.getShopifyConnection.mockResolvedValue({ data: { store: null } })
  api.getShopifyReport.mockResolvedValue({ data: { store: null } })
})

function renderHub(initialEntry = '/clients/7?tab=accounts') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[initialEntry]}><Routes><Route path="/clients/:clientId" element={<ClientHubPage/>}/></Routes></MemoryRouter></QueryClientProvider>)
}

it('shows linked accounts on the URL-backed client hub', async () => {
  renderHub()
  expect(await screen.findByRole('heading', { name: 'Acme' }, { timeout: 5_000 })).toBeInTheDocument()
  expect(screen.getByRole('link', { name: /Acme Meta/ })).toHaveAttribute('href', '/command-center/clients/7/ad-accounts/9')
  expect(screen.getByRole('button', { name: /Ad accounts/ })).toHaveClass('active')
  expect(screen.getByRole('link', { name: 'Login as client' })).toHaveAttribute('href', '/portal/preview/clients/7')
  expect(screen.getByRole('link', { name: 'Login as client' })).toHaveAttribute('target', '_blank')
})

it('keeps login as client visible but disabled until portal access is enabled', async () => {
  api.getClient.mockResolvedValueOnce({ data: { id: 7, name: 'Acme', timezone: 'UTC', currency: 'USD', objective: 'ecommerce', active: true, portal_enabled: false, incidents_count: 0, report_schedules: [], ad_accounts: [] } })
  renderHub()
  expect(await screen.findByRole('button', { name: 'Login as client' })).toBeDisabled()
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

it('replaces operational overview panels with objective-aware connected account performance', async () => {
  renderHub('/clients/7?range=custom&start_date=2026-10-01&end_date=2026-10-30')
  expect(await screen.findByRole('heading', { name: 'Account performance' })).toBeInTheDocument()
  expect(await screen.findByRole('heading', { name: 'Connected accounts' })).toBeInTheDocument()
  expect(screen.getAllByText('Purchases').length).toBeGreaterThan(0)
  expect(screen.getAllByText('Revenue').length).toBeGreaterThan(0)
  expect(await screen.findByLabelText('USD daily performance trend')).toBeInTheDocument()
  expect(screen.getByText('Daily performance trend')).toBeInTheDocument()
  expect(screen.getByTestId('client-trend-chart')).toHaveAttribute('data-first-spend', '200')
  expect(screen.getByTestId('client-trend-chart')).toHaveAttribute('data-last-spend', '250')
  expect(screen.getByRole('columnheader', { name: 'Spend' })).toHaveClass('numeric-header')
  expect(screen.getByRole('columnheader', { name: 'Status' })).not.toHaveClass('numeric-header')
  const totalRow = screen.getByText('USD total').closest('tr')
  expect(within(totalRow).getByText('$350.00')).toBeInTheDocument()
  expect(within(totalRow).getByText('2 accounts')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'ROAS' })).toHaveAttribute('aria-pressed', 'true')
  fireEvent.click(screen.getByRole('button', { name: 'ROAS' }))
  expect(screen.getByRole('button', { name: 'ROAS' })).toHaveAttribute('aria-pressed', 'false')
  expect(screen.queryByRole('heading', { name: 'Alerts' })).not.toBeInTheDocument()
  expect(api.getClientPerformance).toHaveBeenCalledWith('7', { start_date: '2026-10-01', end_date: '2026-10-30' })
  expect((await screen.findByText('Acme Meta')).closest('a')).toHaveAttribute('href', '/command-center/clients/7/ad-accounts/9?range=custom&start_date=2026-10-01&end_date=2026-10-30')
})
