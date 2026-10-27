<?php

namespace Tests\Unit;

use App\Support\CreativeFormatTaxonomy;
use Tests\TestCase;

class CreativeFormatTaxonomyTest extends TestCase
{
    public function test_it_exposes_only_the_eleven_canonical_formats(): void
    {
        $this->assertSame([
            'Problem-Agitation-Solution (PAS)', 'Native Ad', 'PR / Advertorial', 'Founder-Led', 'Comparison',
            'Before / After', 'Listicle', 'Feature Callout', 'Offer-Led', 'Review / Social Proof', 'Product Hero Shot',
        ], CreativeFormatTaxonomy::labels());
    }

    public function test_it_normalizes_legacy_free_text_without_creating_new_labels(): void
    {
        $this->assertSame('Founder-Led', CreativeFormatTaxonomy::normalize('VIDEO', 'Founder-led Educational Video'));
        $this->assertSame('Review / Social Proof', CreativeFormatTaxonomy::normalize('Static DCO', 'Testimonial / Review'));
        $this->assertSame('Comparison', CreativeFormatTaxonomy::normalize('Static Image (Comparison DCO)', 'Educational Product Comparison'));
        $this->assertSame('Problem-Agitation-Solution (PAS)', CreativeFormatTaxonomy::normalize('Video', 'Problem-Solution Educational Ad'));
        $this->assertSame('Feature Callout', CreativeFormatTaxonomy::normalize('Spoken video script', 'Educational Product Ad'));
        $this->assertSame('Product Hero Shot', CreativeFormatTaxonomy::normalize('IMAGE', 'Static Image Ad'));
    }
}
