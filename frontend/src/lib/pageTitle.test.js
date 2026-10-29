import { describe, expect, it } from 'vitest'
import { pageTitle } from './pageTitle'

describe('pageTitle', () => {
  it.each([
    ['/login', 'Sign in · SharkAds'],
    ['/command-center', 'Command Center · SharkAds'],
    ['/reports/new', 'Account Audits · SharkAds'],
    ['/reports/42', 'Account Audit Report · SharkAds'],
    ['/ad-library/audits/7/report', 'Content Audit Report · SharkAds'],
    ['/users', 'Users · SharkAds'],
  ])('maps %s to %s', (path, expected) => expect(pageTitle(path)).toBe(expected))

  it('uses the product name for unknown routes', () => {
    expect(pageTitle('/unknown')).toBe('SharkAds')
  })
})
