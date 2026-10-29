import { googleAdGroupUrl, googleAdUrl, googleCampaignUrl } from './googleDeepLinks'
import { metaAdSetUrl, metaAdUrl, metaCampaignUrl } from './metaDeepLinks'

export function adPlatformLabel(platform) {
  return platform === 'google' ? 'Google Ads' : 'Meta Ads'
}

export function adPlatformEntityUrl({ platform, accountId, campaignId, adGroupId, adId }) {
  if (!accountId || !campaignId) return null

  if (platform === 'google') {
    if (adId && adGroupId) return googleAdUrl(accountId, campaignId, adGroupId, adId)
    if (adGroupId) return googleAdGroupUrl(accountId, campaignId, adGroupId)
    return googleCampaignUrl(accountId, campaignId)
  }

  if (platform === 'meta') {
    if (adId && adGroupId) return metaAdUrl(accountId, campaignId, adGroupId, adId)
    if (adGroupId) return metaAdSetUrl(accountId, campaignId, adGroupId)
    return metaCampaignUrl(accountId, campaignId)
  }

  return null
}
