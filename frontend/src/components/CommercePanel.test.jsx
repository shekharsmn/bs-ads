import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CommercePanel from './CommercePanel'

const { api, auth } = vi.hoisted(() => ({ api: { getShopifyConnection: vi.fn(), getShopifyReport: vi.fn(), connectShopify: vi.fn() }, auth: { user: { role: 'viewer' }, features: { shopify: true } } }))
vi.mock('../api/client', () => ({ api }))
vi.mock('../context/AuthContext', () => ({ useAuth: () => auth }))
function mount(ui) { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>) }
beforeEach(() => { vi.clearAllMocks(); auth.features.shopify = true; auth.user.role = 'viewer' })
afterEach(() => { cleanup(); vi.restoreAllMocks() })
describe('Shopify reporting boundaries', () => {
  it('does not fetch Shopify data when disabled', () => {
    auth.features.shopify = false; mount(<CommercePanel clientId="2"/>); expect(api.getShopifyConnection).not.toHaveBeenCalled(); expect(api.getShopifyReport).not.toHaveBeenCalled()
  })
  it('shows disconnected state without exposing admin setup to viewers', async () => {
    api.getShopifyConnection.mockResolvedValue({ data: { store: null, registrations: [] } }); api.getShopifyReport.mockResolvedValue({ data: { store: null } })
    mount(<CommercePanel clientId="2"/>); expect(await screen.findByText('Not connected')).toBeInTheDocument(); expect(screen.queryByText('Generate linking code')).not.toBeInTheDocument()
  })
  it('offers only Connect Shopify to a disconnected administrator', async () => {
    auth.user.role = 'admin'
    api.getShopifyConnection.mockResolvedValue({ data: { store: null, registrations: [] } }); api.getShopifyReport.mockResolvedValue({ data: { store: null } })
    mount(<CommercePanel clientId="2"/>);
    expect(await screen.findByRole('button', { name: 'Connect Shopify' })).toBeInTheDocument()
    expect(screen.queryByText('App registration')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Shopify client secret')).not.toBeInTheDocument()
    expect(screen.queryByText('No Shopify store is connected.')).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'Period' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Generate linking code' })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Replace connected store')).not.toBeInTheDocument()
  })
  it('shows a failed fetch as an error rather than zero sales', async () => {
    api.getShopifyConnection.mockRejectedValue(new Error('offline')); api.getShopifyReport.mockResolvedValue({ data: { store: null } }); mount(<CommercePanel clientId="2"/>); expect(await screen.findByRole('alert')).toHaveTextContent('could not be loaded')
  })
  it('opens a new tab immediately and navigates it after connection setup', async () => {
    auth.user.role = 'admin'
    api.getShopifyConnection.mockResolvedValue({ data: { store: null } }); api.getShopifyReport.mockResolvedValue({ data: { store: null } })
    let resolve
    api.connectShopify.mockReturnValue(new Promise(done => { resolve = done }))
    const tab = { opener: window, location: { replace: vi.fn() }, close: vi.fn() }
    const open = vi.spyOn(window, 'open').mockReturnValue(tab)
    mount(<CommercePanel clientId="2"/>)
    await screen.findByText('Not connected')
    fireEvent.click(screen.getByRole('button', { name: 'Connect Shopify' }))
    expect(open).toHaveBeenCalledWith('about:blank', '_blank')
    expect(tab.opener).toBeNull()
    expect(tab.location.replace).not.toHaveBeenCalled()
    resolve({ data: { url: 'https://admin.shopify.com/store/test/apps/sharkads' } })
    await waitFor(() => expect(tab.location.replace).toHaveBeenCalledWith('https://admin.shopify.com/store/test/apps/sharkads'))
    expect(tab.close).not.toHaveBeenCalled()
  })
  it('closes the new tab and shows the existing error when setup fails', async () => {
    auth.user.role = 'admin'
    api.getShopifyConnection.mockResolvedValue({ data: { store: null } }); api.getShopifyReport.mockResolvedValue({ data: { store: null } })
    api.connectShopify.mockRejectedValue({ response: { data: { message: 'Shopify setup is incomplete.' } } })
    const tab = { opener: window, location: { replace: vi.fn() }, close: vi.fn() }
    vi.spyOn(window, 'open').mockReturnValue(tab)
    mount(<CommercePanel clientId="2"/>)
    await screen.findByText('Not connected')
    fireEvent.click(screen.getByRole('button', { name: 'Connect Shopify' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Shopify setup is incomplete.')
    expect(tab.close).toHaveBeenCalled()
    expect(tab.location.replace).not.toHaveBeenCalled()
  })
})
