export function discoveryErrorMessage(requestError) {
  if (requestError.response?.data?.message) return requestError.response.data.message
  if (requestError.response?.data?.error) return requestError.response.data.error
  if (requestError.code === 'ECONNABORTED') return 'Account discovery took too long. Please try again.'
  if (!requestError.response) return 'Could not reach the server during account discovery. Please try again.'
  return 'Account discovery failed.'
}
