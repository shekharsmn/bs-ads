<?php

namespace Tests\Unit;

use App\Services\AdLibraryAudit\SafePublicUrl;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafePublicUrlTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_rejects_private_reserved_and_credentialed_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SafePublicUrl::class)->assert($url);
    }

    public static function unsafeUrls(): array
    {
        return [
            ['http://127.0.0.1/internal'],
            ['http://10.0.0.2/admin'],
            ['http://169.254.169.254/latest/meta-data'],
            ['http://user:password@example.com/'],
            ['file:///etc/passwd'],
        ];
    }

    public function test_rejects_cross_origin_redirect_target(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SafePublicUrl::class)->assert('https://example.org/elsewhere', 'example.com');
    }
}
