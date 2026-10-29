import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useNavigate } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider, useAuth } from './AuthContext'
import { api } from '../api/client'

vi.mock('../api/client', () => ({
  api: {
    me: vi.fn(),
    logout: vi.fn(),
  },
}))

function Probe() {
  const navigate = useNavigate()
  const { user, loading } = useAuth()

  return <>
    <span data-testid="auth-state">{loading ? 'loading' : user?.email || 'anonymous'}</span>
    <button type="button" onClick={() => navigate('/command-center')}>Open app</button>
  </>
}

describe('AuthProvider', () => {
  beforeEach(() => {
    api.me.mockResolvedValue({ data: { user: { email: 'user@example.com' }, permissions: {}, features: {} } })
  })

  it('checks the session when navigation leaves a public route', async () => {
    render(
      <MemoryRouter initialEntries={['/privacy-policy']}>
        <AuthProvider><Probe /></AuthProvider>
      </MemoryRouter>,
    )

    expect(screen.getByTestId('auth-state')).toHaveTextContent('anonymous')
    expect(api.me).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Open app' }))

    await waitFor(() => expect(api.me).toHaveBeenCalledOnce())
    expect(await screen.findByText('user@example.com')).toBeInTheDocument()
  })
})
