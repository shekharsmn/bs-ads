export async function merchantApi(path, options = {}) {
  const registration = document.querySelector('meta[name="shopify-registration"]')?.content
  if (!registration || !window.shopify?.idToken) throw new Error('Open SharkAds from Shopify admin to authenticate.')
  const token = await window.shopify.idToken()
  const response = await fetch(`/api/shopify/embedded/${path}`, {
    ...options, credentials: 'omit',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'ngrok-skip-browser-warning': '1', Authorization: `Bearer ${token}`, 'X-Shopify-Registration': registration, ...options.headers },
  })
  if (response.status === 204) return null
  let result
  try { result = await response.json() }
  catch { throw new Error('The store connection returned an unexpected response. Please retry.') }
  if (!response.ok) throw new Error(result?.message || 'The store request failed. Please retry.')
  return result
}
