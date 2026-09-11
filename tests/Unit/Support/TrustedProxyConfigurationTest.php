<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TrustedProxyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_empty_value_means_no_proxy_trusted(): void
    {
        $this->assertNull(TrustedProxyConfiguration::parse(''));
        $this->assertNull(TrustedProxyConfiguration::parse('   '));
        $this->assertNull(TrustedProxyConfiguration::parse(null));
        $this->assertFalse(TrustedProxyConfiguration::isConfigured(''));
    }

    public function test_wildcard_trusts_all_proxies(): void
    {
        $this->assertSame('*', TrustedProxyConfiguration::parse('*'));
        $this->assertTrue(TrustedProxyConfiguration::isConfigured('*'));
    }

    public function test_comma_separated_list_is_parsed_and_trimmed(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2/24'],
            TrustedProxyConfiguration::parse(' 10.0.0.1 , 10.0.0.2/24 ')
        );
    }

    #[DataProvider('blanklikeValues')]
    public function test_blank_like_lists_resolve_to_null(mixed $value): void
    {
        $this->assertNull(TrustedProxyConfiguration::parse($value));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function blanklikeValues(): array
    {
        return [
            'only commas' => [',,,'],
            'not a string' => [42],
            'array' => [['*']],
        ];
    }

    public function test_apply_does_not_throw_for_any_value(): void
    {
        TrustedProxyConfiguration::apply('*');
        TrustedProxyConfiguration::apply('');
        TrustedProxyConfiguration::apply('203.0.113.1');

        $this->addToAssertionCount(1);
    }
}
