<?php

namespace App\Support;

class CreativeFormatTaxonomy
{
    public static function labels(): array
    {
        return array_keys(config('ad_library_audit.creative_formats', []));
    }

    public static function guidance(): string
    {
        return collect(config('ad_library_audit.creative_formats', []))
            ->map(fn (string $description, string $label) => "{$label}: {$description}")
            ->implode(' ');
    }

    public static function normalize(?string $format, ?string $type = null, ?string $remarks = null): string
    {
        $format = trim((string) $format);
        foreach (self::labels() as $label) {
            if (mb_strtolower($format) === mb_strtolower($label)) {
                return $label;
            }
        }

        $value = mb_strtolower(implode(' ', array_filter([$format, $type, $remarks])));
        $rules = [
            'Founder-Led' => ['founder'],
            'Comparison' => ['comparison', 'versus', ' vs '],
            'Before / After' => ['before / after', 'before-and-after', 'before and after'],
            'Review / Social Proof' => ['review', 'testimonial', 'social proof', 'star rating', 'customer quote'],
            'Offer-Led' => ['offer', 'promotion', 'promotional', 'discount', 'bogo', 'bundle price', 'free shipping', 'sale'],
            'Listicle' => ['listicle', ' reasons ', ' ways ', ' tips '],
            'PR / Advertorial' => ['advertorial', 'press feature', 'news article', 'media feature'],
            'Native Ad' => ['native ad', 'user-generated', 'user generated', 'ugc'],
            'Problem-Agitation-Solution (PAS)' => ['problem-solution', 'problem solution', 'problem-agitation-solution', ' pain point ', 'agitation'],
            'Feature Callout' => ['feature', 'ingredient', 'benefit', 'demonstration', ' demo', 'educational', 'infographic', 'how it works'],
            'Product Hero Shot' => ['hero shot', 'product showcase', 'product ad', 'static image', ' image', 'video', 'dco', 'dpa'],
        ];

        foreach ($rules as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($value, $needle)) {
                    return $label;
                }
            }
        }

        return $format !== '' ? 'Product Hero Shot' : '';
    }
}
