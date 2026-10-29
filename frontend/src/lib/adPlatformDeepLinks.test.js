import { describe, expect, it } from 'vitest'
import { adPlatformEntityUrl } from './adPlatformDeepLinks'

describe('ad platform entity links', () => {
  it('targets exact Google Ads hierarchy entities', () => {
    expect(adPlatformEntityUrl({ platform: 'google', accountId: '123-456-7890', campaignId: '11' }))
      .toBe('https://ads.google.com/aw/campaigns?campaignId=11&__c=1234567890')
    expect(adPlatformEntityUrl({ platform: 'google', accountId: '123-456-7890', campaignId: '11', adGroupId: '22', adId: '33' }))
      .toBe('https://ads.google.com/aw/ads?campaignId=11&adGroupId=22&adId=33&__c=1234567890')
  })

  it('targets exact Meta Ads hierarchy entities and normalizes act IDs', () => {
    expect(adPlatformEntityUrl({ platform: 'meta', accountId: 'act_987', campaignId: '11', adGroupId: '22' }))
      .toBe('https://adsmanager.facebook.com/adsmanager/manage/adsets?act=987&selected_campaign_ids=11&selected_adset_ids=22')
    expect(adPlatformEntityUrl({ platform: 'meta', accountId: 'act_987', campaignId: '11', adGroupId: '22', adId: '33' }))
      .toBe('https://adsmanager.facebook.com/adsmanager/manage/ads?act=987&selected_campaign_ids=11&selected_adset_ids=22&selected_ad_ids=33')
  })

  it('does not create a misleading link without account and campaign IDs', () => {
    expect(adPlatformEntityUrl({ platform: 'meta', accountId: 'act_987' })).toBeNull()
  })
})
