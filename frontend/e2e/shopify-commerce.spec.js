import { expect, test } from '@playwright/test'

const report = {
  store: { id: 1, client_id: 7, name: 'Acme Shopify', timezone: 'Asia/Kolkata', currency: 'INR' },
  orders: 8, revenue: [{ net_sales: '900.000000', currency: 'INR' }], trend: [],
  connection: { status: 'active' }, coverage: { complete: false, last_synced_at: null },
  range: { from: '2026-10-01', to: '2026-10-31' }, warnings: ['Historical import is incomplete.'],
  definitions: { revenue: 'Net merchandise sales exclude shipping and taxes.', orders: 'Placed orders include unpaid/COD.' },
}

test('Shopify reporting is available in client Connections only', async ({ page }) => {
  await page.route('**/api/**', route => {
    const path = new URL(route.request().url()).pathname
    const json = path === '/api/me' ? { user: { id: 1, name: 'Operator', role: 'viewer' }, features: { shopify: true } }
      : path === '/api/performance/accounts' ? { accounts: [] }
      : path === '/api/connections' ? []
      : path === '/api/clients/7' ? { id: 7, name: 'Acme', objective: 'ecommerce', ad_accounts: [] }
      : path === '/api/clients/7/shopify/report' ? report
      : path === '/api/clients/7/shopify' ? { store: report.store, registrations: [] } : {}
    return route.fulfill({ json })
  })
  await page.goto('/command-center')
  await expect(page.getByRole('heading', { name: 'No connected ad accounts yet.' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Shopify store performance' })).toHaveCount(0)
  await page.goto('/clients/7?tab=connections')
  await expect(page.getByRole('heading', { name: 'Shopify', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Connections', exact: true })).toHaveClass('active')
  await expect(page.getByRole('button', { name: 'Commerce', exact: true })).toHaveCount(0)
  await expect(page.getByText('Historical import is incomplete.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Generate linking code' })).toHaveCount(0)
  await page.setViewportSize({ width: 390, height: 844 })
  await expect(page.getByRole('heading', { name: 'Shopify', exact: true })).toBeVisible()
})
