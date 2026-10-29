import { describe, expect, it } from 'vitest'
import { isPublicRoute } from './publicRoutes'

describe('isPublicRoute', () => {
  it('recognizes the privacy policy and shared report routes', () => {
    expect(isPublicRoute('/privacy-policy')).toBe(true)
    expect(isPublicRoute('/privacy-policy/')).toBe(true)
    expect(isPublicRoute('/share/reports/token')).toBe(true)
    expect(isPublicRoute('/shared/reports/token')).toBe(true)
  })

  it('does not exempt similarly prefixed or protected routes', () => {
    expect(isPublicRoute('/privacy-policy-copy')).toBe(false)
    expect(isPublicRoute('/share')).toBe(false)
    expect(isPublicRoute('/command-center')).toBe(false)
  })
})
