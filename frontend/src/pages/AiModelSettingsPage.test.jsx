import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { api } from '../api/client'
import AiModelSettingsPage from './AiModelSettingsPage'

vi.mock('../api/client', () => ({
  api: {
    getAiModels: vi.fn(),
    createAiModel: vi.fn(),
    updateAiModel: vi.fn(),
    deleteAiModel: vi.fn(),
    getEmailIntegration: vi.fn(),
    updateEmailIntegration: vi.fn(),
    testEmailIntegration: vi.fn(),
  },
}))
vi.mock('../context/ToastContext', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

afterEach(cleanup)
beforeEach(() => {
  vi.clearAllMocks()
  api.getAiModels.mockResolvedValue({ data: { models: [], providers: [], capabilities: [] } })
  api.getEmailIntegration.mockResolvedValue({
    data: {
      provider: 'amazon_ses',
      provider_label: 'Amazon SES',
      enabled: false,
      configured: false,
      region: 'ap-south-1',
      endpoint: 'email-smtp.ap-south-1.amazonaws.com',
      port: 587,
      smtp_username_configured: false,
      smtp_username_hint: null,
      smtp_password_configured: false,
      from_address: 'notifications@example.com',
      from_name: 'SharkAds',
      last_tested_at: null,
      last_test_status: null,
      last_test_error: null,
    },
  })
})

function renderSettings(path) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[path]}><Routes><Route path="/settings/:section" element={<AiModelSettingsPage/>}/></Routes></MemoryRouter></QueryClientProvider>)
}

it('presents AI models inside the generic Settings tabs', async () => {
  renderSettings('/settings/ai-models')

  expect(screen.getByRole('heading', { name: 'Settings' })).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'AI models' })).toHaveClass('active')
  expect(await screen.findByText('Provider keys')).toBeInTheDocument()
})

it('presents Amazon SES in the Email integration tab', async () => {
  renderSettings('/settings/email-integration')

  expect(screen.getByRole('link', { name: 'Email integration' })).toHaveClass('active')
  expect(await screen.findByText('Amazon Simple Email Service')).toBeInTheDocument()
  expect(screen.getByLabelText('AWS region')).toHaveValue('ap-south-1')
  expect(screen.getByLabelText('From email')).toHaveValue('notifications@example.com')
  expect(screen.getByRole('button', { name: 'Send test' })).toBeDisabled()
})
