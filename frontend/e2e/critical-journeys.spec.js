/* global process */
import { expect, test } from '@playwright/test'

test('login entry point is available', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByText(/sign in|continue with google/i).first()).toBeVisible()
})

test('invalid public report fails without redirecting to login', async ({ page }) => {
  await page.goto('/shared/reports/not-a-real-token')
  await expect(page).toHaveURL(/\/shared\/reports\//)
  await expect(page.getByText(/not found|unavailable|expired|could not/i).first()).toBeVisible()
})

test.describe('authenticated operator journeys', () => {
  test.skip(!process.env.PLAYWRIGHT_STORAGE_STATE, 'Set PLAYWRIGHT_STORAGE_STATE to a signed-in admin session.')

  for (const journey of [
    ['command center', '/', /command center|needs attention/i],
    ['client setup', '/clients', /clients/i],
    ['connection assignment', '/connections', /connections/i],
    ['alert lifecycle', '/alerts', /alerts/i],
    ['scheduled reports', '/scheduled-reports', /reports/i],
    ['ad library search', '/ad-library', /ad library/i],
    ['content audit lifecycle', '/ad-library/audits', /content audit/i],
    ['data health', '/data-health', /data health/i],
    ['governance', '/governance', /governance/i],
  ]) {
    test(`${journey[0]} loads without an application error`, async ({ page }) => {
      await page.goto(journey[1])
      await expect(page.getByText(journey[2]).first()).toBeVisible()
      await expect(page.getByText(/unable to load|application error/i)).toHaveCount(0)
    })
  }
})
