import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { api } from '../api/client'
import PerformanceDrilldownPage from './PerformanceDrilldownPage'

vi.mock('../api/client', () => ({ api: {
  getPerformanceAccounts: vi.fn(), getPerformanceAccount: vi.fn(), getAccountPerformance: vi.fn(), getAccountCampaigns: vi.fn(),
  getCampaignAdGroups: vi.fn(), getAdGroupAds: vi.fn(), getAdPerformance: vi.fn(),
  getAlerts: vi.fn(), getDataQualityIssues: vi.fn(), getReportSchedules: vi.fn(), getReports: vi.fn(), getConnections: vi.fn(),
} }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 44, role: 'marketer' } }) }))
vi.mock('../components/PerformanceTrendChart', () => ({ default: ({ series, ariaLabel, columns }) => <section aria-label={ariaLabel} data-points={series?.length || 0} data-columns={columns.map(column => column.key).join(',')}>Daily performance trend</section> }))

const account = { id: 9, external_id: 'act_987', client: { id: 7, name: 'Acme', objective: 'lead_gen' }, name: 'Acme Meta', platform: 'meta', currency: 'USD', timezone: 'America/New_York' }
const metrics = { spend: 200, leads: 10, cpl: 20, purchases: 2, cpp: 100, roas: 3 }
const series = [{ date: '2026-10-27', metrics }]

function renderPath(path) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><MemoryRouter initialEntries={[path]}><LocationProbe/><Routes>
    <Route path="/command-center/clients/:clientId/ad-accounts/:accountId" element={<PerformanceDrilldownPage/>}/>
    <Route path="/command-center/clients/:clientId/ad-accounts/:accountId/campaigns/:campaignId" element={<PerformanceDrilldownPage/>}/>
    <Route path="/command-center/clients/:clientId/ad-accounts/:accountId/campaigns/:campaignId/ad-groups/:groupId" element={<PerformanceDrilldownPage/>}/>
    <Route path="/command-center/clients/:clientId/ad-accounts/:accountId/campaigns/:campaignId/ad-groups/:groupId/ads/:adId" element={<PerformanceDrilldownPage/>}/>
  </Routes></MemoryRouter></QueryClientProvider>)
}

function LocationProbe() {
  const location = useLocation()
  return <output data-testid="location">{location.pathname}{location.search}</output>
}

describe('performance drilldown', () => {
  afterEach(cleanup)
  beforeEach(() => {
    localStorage.clear()
    api.getPerformanceAccounts.mockResolvedValue({ data: { accounts: [account] } })
    api.getPerformanceAccount.mockResolvedValue({ data: account })
    api.getAccountPerformance.mockResolvedValue({ data: { account, metrics, series } })
    api.getAccountCampaigns.mockResolvedValue({ data: { campaigns: [{ campaign: { id: 3, external_id: 'cmp_3', name: 'Launch', status: 'ACTIVE', type: 'OUTCOME_SALES' }, metrics }] } })
    api.getCampaignAdGroups.mockResolvedValue({ data: { campaign: { id: 3, external_id: 'cmp_3', name: 'Launch' }, metrics, series, ad_groups: [{ ad_group: { id: 4, external_id: 'set_4', name: 'Prospecting', status: 'ACTIVE' }, metrics }] } })
    api.getAdGroupAds.mockResolvedValue({ data: { campaign: { id: 3, external_id: 'cmp_3', name: 'Launch' }, ad_group: { id: 4, external_id: 'set_4', name: 'Prospecting' }, metrics, series, ads: [{ ad: { id: 5, external_id: 'ad_5', name: 'Creative A', status: 'ACTIVE', type: 'IMAGE' }, metrics }] } })
    api.getAdPerformance.mockResolvedValue({ data: { campaign: { id: 3, external_id: 'cmp_3', name: 'Launch' }, ad_group: { id: 4, external_id: 'set_4', name: 'Prospecting' }, ad: { id: 5, external_id: 'ad_5', name: 'Creative A', status: 'ACTIVE', type: 'IMAGE' }, metrics, series } })
    api.getAlerts.mockResolvedValue({ data: { data: [] } })
    api.getDataQualityIssues.mockResolvedValue({ data: { data: [] } })
    api.getReportSchedules.mockResolvedValue({ data: { schedules: [], runs: [] } })
    api.getReports.mockResolvedValue({ data: { data: [] } })
    api.getConnections.mockResolvedValue({ data: [] })
  })

  it('keeps a custom account period in the URL while opening a campaign', async () => {
    renderPath('/command-center/clients/7/ad-accounts/9?range=custom&start_date=2026-09-01&end_date=2026-09-18')
    expect(await screen.findByText('Launch')).toBeInTheDocument()
    expect(screen.getByLabelText('Start date')).toHaveValue('2026-09-01')
    fireEvent.click(screen.getByText('Launch'))
    expect(await screen.findByText('Ad sets')).toBeInTheDocument()
    expect(api.getCampaignAdGroups).toHaveBeenCalledWith('9', '3', { start_date: '2026-09-01', end_date: '2026-09-18' })
    expect(screen.getByTestId('location')).toHaveTextContent('/command-center/clients/7/ad-accounts/9/campaigns/3?range=custom&start_date=2026-09-01&end_date=2026-09-18')
  })

  it('links the middle breadcrumb to the client page while preserving the account period', async () => {
    renderPath('/command-center/clients/7/ad-accounts/9?range=custom&start_date=2026-09-01&end_date=2026-09-18')
    const clientLink = await screen.findByRole('link', { name: 'Acme' }, { timeout: 5_000 })
    const accountLink = screen.getByRole('link', { name: 'Acme Meta' })

    expect(clientLink).toHaveAttribute('href', '/clients/7')
    expect(accountLink).toHaveAttribute('href', '/command-center/clients/7/ad-accounts/9?range=custom&start_date=2026-09-01&end_date=2026-09-18')
  })

  it('uses Meta ad-set terminology on campaign pages', async () => {
    renderPath('/command-center/clients/7/ad-accounts/9/campaigns/3?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    expect(await screen.findByRole('heading', { name: 'Ad sets' })).toBeInTheDocument()
    expect(await screen.findByText('Prospecting')).toBeInTheDocument()
  })

  it('shows the same objective-aware daily graph on campaigns, ad sets, and ads', async () => {
    renderPath('/command-center/clients/7/ad-accounts/9/campaigns/3?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    const campaignTrend = await screen.findByLabelText('Campaign daily performance trend')
    await waitFor(() => expect(campaignTrend).toHaveAttribute('data-points', '1'))
    expect(campaignTrend).toHaveAttribute('data-columns', 'spend,leads,cpl,ctr')

    cleanup()
    renderPath('/command-center/clients/7/ad-accounts/9/campaigns/3/ad-groups/4?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    const groupTrend = await screen.findByLabelText('Ad set daily performance trend')
    await waitFor(() => expect(groupTrend).toHaveAttribute('data-points', '1'))

    cleanup()
    renderPath('/command-center/clients/7/ad-accounts/9/campaigns/3/ad-groups/4/ads/5?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    const adTrend = await screen.findByLabelText('Ad daily performance trend')
    await waitFor(() => expect(adTrend).toHaveAttribute('data-points', '1'))
    expect(api.getAdPerformance).toHaveBeenCalledWith('9', '3', '4', '5', { start_date: '2026-09-28', end_date: '2026-10-27' })
  })

  it('opens account operations in place while preserving the performance period', async () => {
    renderPath('/command-center/clients/7/ad-accounts/9?range=custom&start_date=2026-09-01&end_date=2026-09-18')
    expect(await screen.findByRole('tab', { name: 'Details' })).toHaveAttribute('aria-selected', 'true')
    expect(await screen.findByRole('heading', { name: 'Campaigns' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('tab', { name: 'Reports' }))
    expect(await screen.findByRole('heading', { name: 'Reports' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Campaigns' })).not.toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Reports' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByTestId('location')).toHaveTextContent('/command-center/clients/7/ad-accounts/9?range=custom&start_date=2026-09-01&end_date=2026-09-18&section=reports')
    expect(api.getReportSchedules).toHaveBeenCalledWith({ client_id: '7', ad_account_id: 9 })
  })

  it('opens platform items directly without replacing the internal hierarchy links', async () => {
    renderPath('/command-center/clients/7/ad-accounts/9?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    const campaignLink = await screen.findByRole('link', { name: 'Open Launch in Meta Ads' })
    expect(campaignLink).toHaveAttribute('href', 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=987&selected_campaign_ids=cmp_3')
    expect(campaignLink).toHaveAttribute('target', '_blank')
    fireEvent.click(campaignLink)
    expect(screen.getByTestId('location')).toHaveTextContent('/command-center/clients/7/ad-accounts/9?')

    cleanup()
    renderPath('/command-center/clients/7/ad-accounts/9/campaigns/3/ad-groups/4/ads/5?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    const adLink = await screen.findByRole('link', { name: 'Open in Meta Ads' })
    expect(adLink).toHaveAttribute('href', 'https://adsmanager.facebook.com/adsmanager/manage/ads?act=987&selected_campaign_ids=cmp_3&selected_adset_ids=set_4&selected_ad_ids=ad_5')
  })

  it('gives ad groups and individual ads canonical URLs', async () => {
    renderPath('/command-center/clients/7/ad-accounts/9/campaigns/3?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    fireEvent.click(await screen.findByText('Prospecting'))
    expect(await screen.findByRole('heading', { name: 'Ads' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/command-center/clients/7/ad-accounts/9/campaigns/3/ad-groups/4?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    fireEvent.click(await screen.findByText('Creative A'))
    expect(await screen.findByText('Ad details')).toBeInTheDocument()
    expect(await screen.findByRole('heading', { name: 'Creative A' })).toBeInTheDocument()
    const details = screen.getByRole('heading', { name: 'Ad details' }).closest('section')
    expect(within(details).getByText('ACTIVE')).toBeInTheDocument()
    expect(within(details).getByText('IMAGE')).toBeInTheDocument()
    expect(within(details).getByText('Meta Ads')).toBeInTheDocument()
    expect(within(details).getByText('ad_5')).toBeInTheDocument()
    expect(within(details).getByRole('link', { name: 'Launch' })).toHaveAttribute('href', expect.stringContaining('/campaigns/3?'))
    expect(within(details).getByRole('link', { name: 'Prospecting' })).toHaveAttribute('href', expect.stringContaining('/ad-groups/4?'))
    expect(screen.getByTestId('location')).toHaveTextContent('/command-center/clients/7/ad-accounts/9/campaigns/3/ad-groups/4/ads/5?range=30d&start_date=2026-09-28&end_date=2026-10-27')
  })

  it('stores objective-aware campaign columns per user and account and restores their order', async () => {
    renderPath('/command-center/clients/7/ad-accounts/9?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    await screen.findByText('Launch')
    expect(screen.getByRole('columnheader', { name: 'Leads' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'CTR' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Leads' })).toHaveClass('numeric-header')
    expect(screen.getByRole('columnheader', { name: 'Campaign' })).not.toHaveClass('numeric-header')
    expect(screen.getByRole('columnheader', { name: 'Status' })).not.toHaveClass('numeric-header')
    expect(screen.queryByRole('columnheader', { name: 'Revenue' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Columns' }))
    fireEvent.click(screen.getByRole('button', { name: /Revenue/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Move Revenue up' }))
    expect(JSON.parse(localStorage.getItem('pulse:campaign-columns:44:9:v1'))).toEqual(['spend', 'leads', 'cpl', 'conversion_value', 'ctr'])
    expect(screen.getByRole('columnheader', { name: 'Revenue' })).toBeInTheDocument()

    cleanup()
    renderPath('/command-center/clients/7/ad-accounts/9?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    await screen.findByText('Launch')
    const headings = screen.getAllByRole('columnheader').map(element => element.textContent)
    expect(headings.indexOf('Revenue')).toBeLessThan(headings.indexOf('CTR'))
  })

  it('ignores invalid saved columns and never allows the final metric to be hidden', async () => {
    localStorage.setItem('pulse:campaign-columns:44:9:v1', JSON.stringify(['retired_metric']))
    renderPath('/command-center/clients/7/ad-accounts/9?range=30d&start_date=2026-09-28&end_date=2026-10-27')
    await screen.findByText('Launch')
    fireEvent.click(screen.getByRole('button', { name: 'Columns' }))
    fireEvent.click(screen.getByRole('button', { name: 'Hide Leads' }))
    fireEvent.click(screen.getByRole('button', { name: 'Hide CPL' }))
    fireEvent.click(screen.getByRole('button', { name: 'Hide CTR' }))
    expect(screen.getByRole('button', { name: 'Hide Spend' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Restore defaults' }))
    expect(localStorage.getItem('pulse:campaign-columns:44:9:v1')).toBeNull()
  })
})
