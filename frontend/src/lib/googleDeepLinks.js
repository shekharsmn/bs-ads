/**
 * Deep links into Google Ads UI. __c is the customer id (no dashes).
 * @param {string|number} customerId
 */
export function googleCustomerParam(customerId) {
  if (customerId == null || customerId === '') return ''
  const raw = String(customerId).replace(/\D/g, '')
  return raw ? `?__c=${raw}` : ''
}

export function googleCampaignsUrl(customerId) {
  return `https://ads.google.com/aw/campaigns${googleCustomerParam(customerId)}`
}

export function googleKeywordsUrl(customerId) {
  return `https://ads.google.com/aw/keywords${googleCustomerParam(customerId)}`
}

export function googleSearchTermsUrl(customerId) {
  return `https://ads.google.com/aw/searchterms${googleCustomerParam(customerId)}`
}

export function googleAdsUrl(customerId) {
  return `https://ads.google.com/aw/adgroups${googleCustomerParam(customerId)}`
}

export function googleConversionsUrl(customerId) {
  return `https://ads.google.com/aw/conversions${googleCustomerParam(customerId)}`
}

export function googleNegativeKeywordListsUrl(customerId) {
  return `https://ads.google.com/aw/sharedlibrary/negativekeywordlists${googleCustomerParam(customerId)}`
}
