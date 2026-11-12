import { render, screen } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import App from './App'
import { merchantApi } from './api'
vi.mock('./api', () => ({ merchantApi: vi.fn() }))
beforeEach(() => vi.clearAllMocks())
it('shows incomplete coverage alongside captured figures', async () => {
  merchantApi.mockImplementation(path => Promise.resolve(path === 'session' ? {} : {
    store: { name: 'Merchant store', timezone: 'Asia/Kolkata' }, orders: 3, revenue: [{ net_sales: '75.000000', currency: 'INR' }],
    connection: { status: 'active', run: { stage: 'import', records: 3 } }, coverage: { last_synced_at: null },
    warnings: ['Historical import is incomplete.'], range: { from: '2026-10-01', to: '2026-10-31' }, trend: [], definitions: { revenue: 'Net sales definition', orders: 'Placed orders definition' },
  }))
  render(<App/>); expect(await screen.findByText('Merchant store')).toBeInTheDocument(); expect(screen.getByText('Historical import is incomplete.')).toBeInTheDocument(); expect(screen.getByText('75.00 INR')).toBeInTheDocument()
})
it('surfaces authentication failure without redirecting to agency SSO', async () => {
  merchantApi.mockRejectedValue(new Error('reconnect_required')); render(<App/>); expect(await screen.findByRole('alert')).toHaveTextContent('reconnect_required')
})
