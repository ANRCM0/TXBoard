<?php

namespace Tests\Feature;

use Tests\TestCase;

class AgentSelfConnectGuideTest extends TestCase
{
    public function test_self_connect_guide_is_public_version_matched_markdown(): void
    {
        $response = $this->get('/.well-known/txboard-agent-connect.md');

        $response->assertOk();
        $this->assertStringStartsWith(
            'text/markdown',
            (string) $response->headers->get('content-type')
        );

        $body = $response->getContent();

        $this->assertStringContainsString('TXBoard Agent Self-Connect Guide v1', $body);
        $this->assertStringContainsString('<PANEL_ORIGIN>/mcp', $body);
        $this->assertStringContainsString('txboard_system_status', $body);
        $this->assertStringContainsString('Do not install or start another TXBoard MCP server', $body);
        $this->assertStringContainsString('Do not disable TLS verification automatically', $body);
    }
}
