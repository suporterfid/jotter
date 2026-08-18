<?php

namespace Tests\Unit;

use App\Domain\Vault\ExternalEmbedPolicy;
use Tests\TestCase;

class ExternalEmbedPolicyTest extends TestCase
{
    public function test_configured_policy_allows_https_exact_hosts_and_subdomains_only(): void
    {
        config(['jotter.external_embed_domains' => ['YouTube.com', ' miro.com ', '']]);

        $policy = ExternalEmbedPolicy::configured();

        $this->assertSame(['youtube.com', 'miro.com'], $policy->allowedHosts());
        $this->assertTrue($policy->isAllowed('https://www.youtube.com/embed/abc'));
        $this->assertTrue($policy->isAllowed('https://boards.miro.com/board/abc'));
        $this->assertFalse($policy->isAllowed('https://evil-youtube.com/embed/abc'));
        $this->assertFalse($policy->isAllowed('http://youtube.com/embed/abc'));
        $this->assertFalse($policy->isAllowed('https://youtube.com@evil.example/embed/abc'));
        $this->assertFalse($policy->isAllowed('javascript:alert(1)'));
    }

    public function test_empty_config_disables_external_embeds(): void
    {
        config(['jotter.external_embed_domains' => []]);

        $policy = ExternalEmbedPolicy::configured();

        $this->assertSame([], $policy->allowedHosts());
        $this->assertFalse($policy->isAllowed('https://youtube.com/embed/abc'));
    }
}
