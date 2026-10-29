import { expect, test } from '@playwright/test'

const metrics = { spend: 200, leads: 10, cpl: 20, purchases: 2, cpp: 100, roas: 3 }
const account = { id: 9, external_id: 'act_987', client: { id: 7, name: 'Acme' }, name: 'Acme Meta', platform: 'meta', currency: 'USD', timezone: 'America/New_York' }
const commandCenterAccount = { ...account, account_ids: [9], account_count: 1, result_type: 'sales', windows: { yesterday: { results: 2, cpr: 100, spend: 200 }, last_7_days: { results: 2, cpr: 100, spend: 200 }, last_30_days: { results: 2, cpr: 100, spend: 200 } } }

test('drills from account to campaign, ad set, and ads while preserving period', async ({ page }) => {
  let refreshRequests = 0
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path === '/api/me') return route.fulfill({ json: { user: { id: 1, name: 'Operator', role: 'admin' }, features: {} } })
    if (path === '/api/performance/refresh') {
      refreshRequests += 1
      return route.fulfill({ status: 202, json: { queued_accounts: 1, requested_at: '2026-10-29T05:00:00Z', refresh: { available: false, available_at: new Date(Date.now() + 300_000).toISOString(), remaining_seconds: 300 } } })
    }
    if (path === '/api/performance/accounts') return route.fulfill({ json: { accounts: [commandCenterAccount], refresh: refreshRequests > 0 ? { available: false, available_at: new Date(Date.now() + 300_000).toISOString(), remaining_seconds: 300 } : { available: true, available_at: null, remaining_seconds: 0 } } })
    if (path.endsWith('/overview')) return route.fulfill({ json: { account, metrics, series: [] } })
    if (path.endsWith('/campaigns')) return route.fulfill({ json: { campaigns: [{ campaign: { id: 3, external_id: 'cmp_3', name: 'Launch', status: 'ACTIVE', type: 'OUTCOME_SALES' }, metrics }] } })
    if (path.endsWith('/ad-groups')) return route.fulfill({ json: { campaign: { id: 3, external_id: 'cmp_3', name: 'Launch' }, metrics, ad_groups: [{ ad_group: { id: 4, external_id: 'set_4', name: 'Prospecting', status: 'ACTIVE' }, metrics }] } })
    if (path.endsWith('/ads')) return route.fulfill({ json: { campaign: { id: 3, external_id: 'cmp_3', name: 'Launch' }, ad_group: { id: 4, external_id: 'set_4', name: 'Prospecting' }, metrics, ads: [{ ad: { id: 5, external_id: 'ad_5', name: 'Creative A', status: 'ACTIVE' }, metrics }] } })
    return route.fulfill({ json: {} })
  })

  await page.goto('/command-center')
  await expect(page.getByRole('heading', { name: 'Client performance' })).toBeVisible()
  await page.getByRole('button', { name: 'Refresh' }).click()
  await expect(page.getByText('Refresh queued for 1 account; updating automatically.')).toBeVisible()
  await expect(page.getByRole('button', { name: /Refresh in/ })).toBeDisabled()
  expect(refreshRequests).toBe(1)
  await page.getByText('Acme').click()
  await expect(page).toHaveURL(/\/command-center\/clients\/7\/ad-accounts\/9/)
  await expect(page.getByRole('link', { name: 'Open Launch in Meta Ads' })).toHaveAttribute('href', /selected_campaign_ids=cmp_3/)
  await page.getByText('Launch').click()
  await expect(page.getByRole('heading', { name: 'Ad sets' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Open Prospecting in Meta Ads' })).toHaveAttribute('href', /selected_adset_ids=set_4/)
  await page.getByText('Prospecting').last().click()
  await expect(page.getByText('Creative A')).toBeVisible()
  await expect(page.getByRole('link', { name: 'Open Creative A in Meta Ads' })).toHaveAttribute('href', /selected_ad_ids=ad_5/)
  await expect(page).toHaveURL(/\/campaigns\/3\/ad-groups\/4\?range=30d/)
  await page.getByText('Creative A').click()
  await expect(page.getByRole('heading', { name: 'Creative A' })).toBeVisible()
  const details = page.getByRole('heading', { name: 'Ad details' }).locator('xpath=ancestor::section')
  await expect(details.getByText('ACTIVE')).toBeVisible()
  await expect(details.getByText('Meta Ads')).toBeVisible()
  await expect(details.getByText('ad_5')).toBeVisible()
  await expect(details.getByRole('link', { name: 'Launch' })).toHaveAttribute('href', /\/campaigns\/3\?/)
  await expect(details.getByRole('link', { name: 'Prospecting' })).toHaveAttribute('href', /\/ad-groups\/4\?/)
  await expect(page).toHaveURL(/\/campaigns\/3\/ad-groups\/4\/ads\/5\?range=30d/)
})

test('opens client and account operations without leaving the account hierarchy', async ({ page }) => {
  await page.route('**/api/**', async route => {
    const url = new URL(route.request().url())
    const path = url.pathname
    if (path === '/api/me') return route.fulfill({ json: { user: { id: 1, name: 'Operator', role: 'admin' }, features: { ad_library: true, ad_library_audits: true }, permissions: { manage_ad_library: true } } })
    if (path === '/api/clients/7') return route.fulfill({ json: { id: 7, name: 'Acme', timezone: 'UTC', currency: 'USD', objective: 'ecommerce', active: true, incidents_count: 1, report_schedules: [], ad_accounts: [account] } })
    if (path === '/api/clients') return route.fulfill({ json: [{ id: 7, name: 'Acme', ad_accounts: [account] }] })
    if (path === '/api/performance/accounts') return route.fulfill({ json: { accounts: [commandCenterAccount] } })
    if (path.endsWith('/overview')) return route.fulfill({ json: { account, metrics, series: [] } })
    if (path.endsWith('/campaigns')) return route.fulfill({ json: { campaigns: [] } })
    if (path === '/api/alerts') return route.fulfill({ json: { data: [{ id: 'alert-1', client_id: 7, ad_account_id: 9, title: 'Spend spike', severity: 'warning', status: 'open', client: { name: 'Acme' }, ad_account: { name: 'Acme Meta' } }] } })
    if (path === '/api/data-quality/issues') return route.fulfill({ json: { data: [] } })
    if (path === '/api/report-schedules') return route.fulfill({ json: { schedules: [], runs: [] } })
    if (path === '/api/reports') return route.fulfill({ json: { data: [] } })
    if (path === '/api/connections') return route.fulfill({ json: [] })
    return route.fulfill({ json: {} })
  })

  await page.goto('/clients/7?tab=accounts')
  await page.getByRole('link', { name: /Acme Meta/ }).click()
  await expect(page).toHaveURL(/\/command-center\/clients\/7\/ad-accounts\/9/)
  await expect(page.getByText('Spend spike')).toBeVisible()
  await page.getByRole('button', { name: 'Reports' }).click()
  await expect(page).toHaveURL(/\/command-center\/clients\/7\/ad-accounts\/9\?.*section=reports/)
  await expect(page.getByRole('heading', { name: 'Reports', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Connection' }).click()
  await expect(page).toHaveURL(/\/command-center\/clients\/7\/ad-accounts\/9\?.*section=connection/)
})
