import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { api } from '../api/client'
import ClientsPage from './ClientsPage'

vi.mock('../api/client', () => ({
  api: {
    getClients: vi.fn(),
    deleteClient: vi.fn(),
  },
}))
vi.mock('../context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, role: 'admin' } }) }))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  api.getClients.mockResolvedValue({ data: [{ id: 7, name: 'Acme', timezone: 'UTC', currency: 'USD', objective: 'ecommerce', active: true, ad_accounts_count: 2, incidents_count: 1, google_chat_webhook_configured: false }] })
  api.deleteClient.mockResolvedValue({ data: null })
})

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={queryClient}><MemoryRouter><ClientsPage/></MemoryRouter></QueryClientProvider>)
}

it('lets an admin delete a client only after confirming the cascade', async () => {
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Delete Acme' }))

  expect(screen.getByRole('alertdialog')).toHaveTextContent('This permanently deletes the client')
  expect(api.deleteClient).not.toHaveBeenCalled()

  fireEvent.click(screen.getByRole('button', { name: 'Delete client' }))
  await waitFor(() => expect(api.deleteClient).toHaveBeenCalledWith(7))
})
