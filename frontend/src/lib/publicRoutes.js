export function isPublicRoute(pathname) {
  return /^\/privacy-policy\/?$/.test(pathname)
    || /^\/client-login\/?$/.test(pathname)
    || pathname.startsWith('/share/')
    || pathname.startsWith('/shared/')
}
