/** Meta Ads Manager deep links (act id without `act_` prefix in query). */
export function metaActParam(accountId) {
  if (accountId == null || accountId === '') return ''
  return String(accountId).replace(/^act_/i, '')
}

export function metaCampaignsUrl(accountId) {
  const act = metaActParam(accountId)
  if (!act) return 'https://adsmanager.facebook.com/adsmanager/manage/campaigns'
  return `https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=${encodeURIComponent(act)}`
}

function metaEntityUrl(level, accountId, identifiers = {}) {
  const params = new URLSearchParams()
  const act = metaActParam(accountId)
  if (act) params.set('act', act)
  Object.entries(identifiers).forEach(([key, value]) => {
    if (value != null && value !== '') params.set(key, String(value))
  })
  const query = params.toString()
  return `https://adsmanager.facebook.com/adsmanager/manage/${level}${query ? `?${query}` : ''}`
}

export function metaCampaignUrl(accountId, campaignId) {
  return metaEntityUrl('campaigns', accountId, { selected_campaign_ids: campaignId })
}

export function metaAdSetUrl(accountId, campaignId, adSetId) {
  return metaEntityUrl('adsets', accountId, {
    selected_campaign_ids: campaignId,
    selected_adset_ids: adSetId,
  })
}

export function metaAdUrl(accountId, campaignId, adSetId, adId) {
  return metaEntityUrl('ads', accountId, {
    selected_campaign_ids: campaignId,
    selected_adset_ids: adSetId,
    selected_ad_ids: adId,
  })
}

export function metaEventsManagerUrl() {
  return 'https://business.facebook.com/events_manager2'
}
