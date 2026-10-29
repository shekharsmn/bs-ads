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

function googleEntityUrl(path, customerId, identifiers = {}) {
  const params = new URLSearchParams()
  Object.entries(identifiers).forEach(([key, value]) => {
    if (value != null && value !== '') params.set(key, String(value))
  })
  const customer = String(customerId ?? '').replace(/\D/g, '')
  if (customer) params.set('__c', customer)
  const query = params.toString()
  return `https://ads.google.com/aw/${path}${query ? `?${query}` : ''}`
}

export function googleCampaignUrl(customerId, campaignId) {
  return googleEntityUrl('campaigns', customerId, { campaignId })
}

export function googleAdGroupUrl(customerId, campaignId, adGroupId) {
  return googleEntityUrl('adgroups', customerId, { campaignId, adGroupId })
}

export function googleAdUrl(customerId, campaignId, adGroupId, adId) {
  return googleEntityUrl('ads', customerId, { campaignId, adGroupId, adId })
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
