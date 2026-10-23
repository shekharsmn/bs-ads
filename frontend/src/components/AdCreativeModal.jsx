import { useEffect, useMemo, useState } from 'react'
import { Check, Copy, ExternalLink, Image as ImageIcon, PlayCircle, X } from 'lucide-react'
import { copyText } from '../utils/clipboard'

export default function AdCreativeModal({ open, onClose, creative }) {
  const [mediaIndex, setMediaIndex] = useState(0)
  const [copied, setCopied] = useState(false)

  useEffect(() => {
    if (!open) return undefined
    const onKeyDown = event => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [open, onClose])

  const media = useMemo(() => {
    if (!creative) return []
    const videos = (creative.video_urls || []).map((url, index) => ({ type: 'video', url, label: `Video ${index + 1}` }))
    const images = (creative.image_urls || []).map((url, index) => ({ type: 'image', url, label: `Image ${index + 1}` }))
    return videos.length > 0 ? videos : images
  }, [creative])

  if (!open || !creative) return null

  const selected = media[Math.min(mediaIndex, Math.max(media.length - 1, 0))]
  const rawJson = JSON.stringify(creative.raw_payload ?? creative, null, 2)
  const copyJson = async () => {
    await copyText(rawJson)
    setCopied(true)
    window.setTimeout(() => setCopied(false), 1800)
  }

  return <div className="ad-creative-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="ad-creative-title" onClick={onClose}>
    <div className="ad-creative-modal" onClick={event => event.stopPropagation()}>
      <header className="ad-creative-modal-header">
        <div>
          <div className="ad-creative-modal-kicker">
            <span className={`ad-library-media-tag ${creative.media_type}`}>{creative.media_type === 'video' ? <PlayCircle size={11} /> : <ImageIcon size={11} />}{creative.media_type === 'video' ? 'Video' : 'Static'}</span>
            {creative.variant_count > 1 && <span>{creative.variant_count} matching ads grouped</span>}
          </div>
          <h2 id="ad-creative-title">{creative.advertiser_name || 'Ad creative'}</h2>
        </div>
        <div className="ad-creative-header-actions">
          {creative.product_url && <a href={creative.product_url} target="_blank" rel="noreferrer">Open in Meta <ExternalLink size={13} /></a>}
          <button type="button" aria-label="Close creative details" onClick={onClose}><X size={18} /></button>
        </div>
      </header>

      <div className="ad-creative-layout">
        <section className="ad-creative-media-panel">
          <div className="ad-creative-stage">
            {!selected && <div className="ad-creative-empty-media"><ImageIcon size={34} /><span>No media URL was captured.</span></div>}
            {selected?.type === 'video' && <video key={selected.url} controls preload="metadata" poster={creative.image_urls?.[0]}><source src={selected.url} /></video>}
            {selected?.type === 'image' && <img src={selected.url} alt={`${creative.advertiser_name || 'Advertiser'} creative ${mediaIndex + 1}`} />}
          </div>
          {media.length > 1 && <div className="ad-creative-media-picker">
            {media.map((item, index) => <button key={`${item.type}-${item.url}`} type="button" className={index === mediaIndex ? 'active' : ''} onClick={() => setMediaIndex(index)}>
              {item.type === 'video' ? <PlayCircle size={13} /> : <ImageIcon size={13} />}{item.label}
            </button>)}
          </div>}
        </section>

        <aside className="ad-creative-details">
          {(creative.title || creative.body) && <section className="ad-creative-copy">
            {creative.title && <h3>{creative.title}</h3>}
            {creative.body && <p>{creative.body}</p>}
          </section>}

          <DetailSection title="Ad details">
            <Detail label="Library ID" value={creative.library_ad_id} mono />
            <Detail label="Status" value={creative.is_active == null ? null : creative.is_active ? 'Active' : 'Inactive'} />
            <Detail label="First shown" value={formatDate(creative.first_shown_at)} />
            <Detail label="Last shown" value={formatDate(creative.last_shown_at)} />
            <Detail label="Platforms" value={creative.platforms} />
            <Detail label="Display format" value={creative.display_format} />
            <Detail label="CTA" value={creative.cta_text || creative.cta_type} />
            <Detail label={creative.video_urls?.length ? 'Poster image' : 'Images'} value={creative.video_urls?.length ? (creative.image_urls?.length ? 'Available' : null) : creative.image_urls?.length} />
            <Detail label="Videos" value={creative.video_urls?.length} />
          </DetailSection>

          <DetailSection title="Advertiser">
            <Detail label="Name" value={creative.advertiser_name} />
            <Detail label="Page ID" value={creative.advertiser_page_id} mono />
            <Detail label="Page likes" value={formatNumber(creative.advertiser_likes)} />
            <Detail label="Instagram" value={creative.advertiser_ig_username} />
            <Detail label="IG followers" value={formatNumber(creative.advertiser_ig_followers)} />
            <Detail label="Verification" value={creative.advertiser_verification} />
          </DetailSection>

          {(creative.link_title || creative.link_description || creative.link_url || creative.cta_text) && <DetailSection title="Destination">
            <Detail label="Headline" value={creative.link_title} wide />
            <Detail label="Description" value={creative.link_description} wide />
            <Detail label="CTA" value={creative.cta_text} />
            <Detail label="Link" value={creative.link_url} wide link />
          </DetailSection>}

          {(creative.reach || creative.eu_reach || creative.uk_reach || creative.impressions || creative.spend || creative.target_ages || creative.target_gender || creative.target_locations || creative.paid_for_by || creative.payer || creative.beneficiary) && <DetailSection title="Reach and targeting">
            <Detail label="Impressions" value={creative.impressions} />
            <Detail label="Reach" value={creative.reach} />
            <Detail label="EU reach" value={formatNumber(creative.eu_reach)} />
            <Detail label="UK reach" value={formatNumber(creative.uk_reach)} />
            <Detail label="Spend" value={[creative.spend, creative.spend_currency].filter(Boolean).join(' ')} />
            <Detail label="Target ages" value={creative.target_ages} />
            <Detail label="Target gender" value={creative.target_gender} />
            <Detail label="Locations" value={creative.target_locations} wide />
            <Detail label="Paid for by" value={creative.paid_for_by} wide />
            <Detail label="Payer" value={creative.payer} />
            <Detail label="Beneficiary" value={creative.beneficiary} />
          </DetailSection>}

          {creative.variant_count > 1 && <DetailSection title={`Grouped ads (${creative.variant_count})`}>
            <div className="ad-creative-variants">
              {creative.variants.map(variant => <a key={variant.library_ad_id} href={variant.product_url} target="_blank" rel="noreferrer">
                <span>{variant.library_ad_id}</span><small>{[variant.is_active ? 'Active' : 'Inactive', variant.first_shown_at].filter(Boolean).join(' · ')}</small>
              </a>)}
            </div>
          </DetailSection>}

          <details className="ad-creative-raw">
            <summary>Raw provider JSON</summary>
            <div><button type="button" onClick={copyJson}>{copied ? <Check size={13} /> : <Copy size={13} />}{copied ? 'Copied' : 'Copy JSON'}</button></div>
            <pre>{rawJson}</pre>
          </details>
        </aside>
      </div>
    </div>
  </div>
}

function DetailSection({ title, children }) {
  const visible = Array.isArray(children) ? children.filter(Boolean) : children
  if (!visible || (Array.isArray(visible) && visible.length === 0)) return null
  return <section className="ad-creative-detail-section"><h3>{title}</h3><div className="ad-creative-detail-grid">{visible}</div></section>
}

function Detail({ label, value, mono = false, wide = false, link = false }) {
  if (value == null || value === '' || (Array.isArray(value) && value.length === 0)) return null
  const display = Array.isArray(value) ? value.map(item => typeof item === 'object' ? JSON.stringify(item) : item).join(', ') : String(value)
  return <div className={wide ? 'wide' : ''}><span>{label}</span>{link ? <a href={display} target="_blank" rel="noreferrer">{display}</a> : <strong className={mono ? 'mono' : ''}>{display}</strong>}</div>
}

function formatDate(value) {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
}

function formatNumber(value) {
  return value == null ? null : Number(value).toLocaleString()
}
