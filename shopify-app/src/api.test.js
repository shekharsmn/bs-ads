import { afterEach, describe, expect, it, vi } from 'vitest'
import { merchantApi } from './api'
afterEach(() => { vi.unstubAllGlobals(); document.head.innerHTML = ''; delete window.shopify })
describe('Shopify-only authentication', () => {
  it('requires Shopify identity, never redirects to agency login', async () => {
    await expect(merchantApi('report')).rejects.toThrow('Shopify admin')
  })
  it('requests a current token and does not send agency cookies', async () => {
    document.head.innerHTML = '<meta name="shopify-registration" content="pilot">'
    window.shopify = { idToken: vi.fn().mockResolvedValue('short-lived-token') }
    const fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ orders: 2 }) }); vi.stubGlobal('fetch', fetch)
    expect(await merchantApi('report')).toEqual({ orders: 2 })
    expect(fetch.mock.calls[0][1]).toMatchObject({ credentials: 'omit', headers: { Authorization: 'Bearer short-lived-token', 'X-Shopify-Registration': 'pilot', 'ngrok-skip-browser-warning': '1' } })
  })
  it('surfaces reconnect errors rather than treating them as empty data', async () => {
    document.head.innerHTML = '<meta name="shopify-registration" content="pilot">'
    window.shopify = { idToken: async () => 'jwt' }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 401, json: async () => ({ message: 'reconnect_required' }) }))
    await expect(merchantApi('report')).rejects.toThrow('reconnect_required')
  })
  it('handles tunnel warning responses without leaking raw parser errors', async () => {
    document.head.innerHTML = '<meta name="shopify-registration" content="pilot">'
    window.shopify = { idToken: async () => 'jwt' }
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('You are about to visit a free tunnel', { status: 200 })))
    await expect(merchantApi('session', { method: 'POST' })).rejects.toThrow('The store connection returned an unexpected response. Please retry.')
  })
})
