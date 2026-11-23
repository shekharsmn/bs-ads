import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import CreativePerformancePanel from './CreativePerformancePanel'
import { api } from '../api/client'

vi.mock('../api/client', () => ({ api: {
  getAccountCreativePerformance: vi.fn(), getClientCreativePerformance: vi.fn(),
  getPortalAccountCreativePerformance: vi.fn(), getPortalClientCreativePerformance: vi.fn(),
  getCreativeMediaProxyUrl: vi.fn(), getPortalCreativeMediaProxyUrl: vi.fn(),
} }))

afterEach(() => { cleanup(); vi.clearAllMocks() })

it('lazy-loads paginated creatives and defers video playback until requested', async () => {
  const row = {
    ad: { id: 9, name: 'Demo video', creative: { entity_kind: 'ad', primary_texts: ['Primary copy'], headlines: ['Headline'], media: [{ type: 'video', url: 'https://video.xx.fbcdn.net/poster.jpg', preview_url: 'https://video.xx.fbcdn.net/poster.jpg', source_url: 'https://video.xx.fbcdn.net/video.mp4', downloadable: true }] } },
    campaign: { id: 4, name: 'Campaign' }, ad_group: { id: 5, name: 'Group' },
    ad_account: { id: 3, name: 'Account', platform: 'meta', currency: 'USD' },
    metrics: { spend: 100, results: 5, result_key: 'leads', cost_per_result: 20, data_days: 5, impressions: 1500 },
    benchmark: { cost_per_result: 25, result_key: 'leads', source: '90_day_peer_median' },
    verdict: { key: 'winner', label: 'Winner', reason: 'Efficient.' },
  }
  api.getAccountCreativePerformance.mockResolvedValue({ data: { data: [row], meta: { current_page: 1, last_page: 1, per_page: 24, total: 1 } } })
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(<QueryClientProvider client={queryClient}><MemoryRouter><CreativePerformancePanel accountId={3} clientId={2} query="start_date=2026-11-01&end_date=2026-11-07" data={{ counts: { winners: 1, did_not_work: 0, not_enough_data: 0 }, winners: [row], did_not_work: [], methodology: {} }}/></MemoryRouter></QueryClientProvider>)

  fireEvent.click(screen.getByRole('button', { name: 'View creative Demo video' }))
  const dialog = screen.getByRole('dialog', { name: 'Creative performance results' })
  expect(dialog).toBeInTheDocument()
  expect(within(dialog).getByLabelText(/Why winner: 5 leads across 5 active days/)).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Close creative browser' }))

  fireEvent.click(screen.getByRole('button', { name: /1Winners/i }))
  await waitFor(() => expect(api.getAccountCreativePerformance).toHaveBeenCalled())
  expect(api.getAccountCreativePerformance.mock.calls[0][1]).toMatchObject({ verdict: 'winner', page: 1, per_page: 24, start_date: '2026-11-01' })
  fireEvent.click(await screen.findByRole('button', { name: /Demo videoAccount/i }))
  expect(screen.queryByRole('video')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: /Play video/i }))
  expect(document.querySelector('video')).toHaveAttribute('src', 'https://video.xx.fbcdn.net/video.mp4')
  expect(screen.getAllByText('Primary copy')).not.toHaveLength(0)
})
