import { fireEvent, render, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { discoveryErrorMessage } from '../lib/connectionErrors'
import { ConnectionSetupGuide } from './ConnectionsPage'

describe('connection setup guidance', () => {
  it('explains the Google authorization and account assignment flow', () => {
    const view = render(<ConnectionSetupGuide platform="google" />)
    const guide = within(view.container)

    expect(guide.getByText('Use a Google login with Ads access')).toBeInTheDocument()
    expect(guide.getByText(/Admin → Access and security/)).toBeInTheDocument()
    expect(guide.getByText(/Manager accounts appear only to show the hierarchy/)).toBeInTheDocument()
    expect(guide.getByRole('link', { name: /Google Ads access guide/ })).toHaveAttribute('href', expect.stringContaining('developers.google.com'))
  })

  it('shows the minimum Meta system-user setup and keeps it collapsible', () => {
    const view = render(<ConnectionSetupGuide platform="meta" />)
    const guide = within(view.container)

    expect(guide.getByText('Prepare a Meta System User token')).toBeInTheDocument()
    expect(guide.getByText('ads_read')).toBeInTheDocument()
    expect(guide.getByText(/View performance/)).toBeInTheDocument()

    const summary = guide.getByText('Setup steps')
    const disclosure = summary.closest('details')
    expect(disclosure).toHaveAttribute('open')
    fireEvent.click(summary)
    expect(disclosure).not.toHaveAttribute('open')
  })
})

describe('account discovery errors', () => {
  it('distinguishes timeouts and unreachable servers from provider errors', () => {
    expect(discoveryErrorMessage({ code: 'ECONNABORTED' })).toBe('Account discovery took too long. Please try again.')
    expect(discoveryErrorMessage({ request: {} })).toBe('Could not reach the server during account discovery. Please try again.')
    expect(discoveryErrorMessage({ response: { data: { message: 'Google Ads rejected the account.' } } })).toBe('Google Ads rejected the account.')
  })
})
