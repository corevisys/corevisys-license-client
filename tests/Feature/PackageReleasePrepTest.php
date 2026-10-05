<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\TestCase;

class PackageReleasePrepTest extends TestCase
{
    public function test_gitattributes_contains_required_export_ignores(): void
    {
        $gitattributesPath = dirname(__DIR__, 2) . '/.gitattributes';
        $this->assertFileExists($gitattributesPath);
        $content = file_get_contents($gitattributesPath);

        $this->assertStringContainsString('/tests', $content);
        $this->assertStringContainsString('/docs', $content);
        $this->assertStringContainsString('/AUDIT', $content);
        $this->assertStringContainsString('/.github', $content);
        $this->assertStringContainsString('export-ignore', $content);
    }

    public function test_changelog_has_v1_release_entry(): void
    {
        $changelogPath = dirname(__DIR__, 2) . '/CHANGELOG.md';
        $this->assertFileExists($changelogPath);
        $content = file_get_contents($changelogPath);

        $this->assertStringContainsString('## [1.0.0]', $content);
        $this->assertStringNotContainsString('Not yet done', $content);
    }

    public function test_client_configured_version_is_1_0_0(): void
    {
        $this->assertSame('1.0.0', config('corevisys-license.client_version'));
    }

    public function test_readme_documents_installation_and_feature_entitlements(): void
    {
        $readmePath = dirname(__DIR__, 2) . '/README.md';
        $this->assertFileExists($readmePath);
        $content = file_get_contents($readmePath);

        $this->assertStringContainsString('corevisys:license:install', $content);
        $this->assertStringContainsString('corevisys:license:doctor', $content);
        $this->assertStringContainsString('Feature entitlements are stored per license', $content);
        $this->assertStringContainsString('An empty list grants no feature.', $content);
    }

    public function test_ci_workflow_exists_and_contains_no_secrets(): void
    {
        $ciPath = dirname(__DIR__, 2) . '/.github/workflows/ci.yml';
        $this->assertFileExists($ciPath);
        $content = file_get_contents($ciPath);

        $this->assertStringContainsString('composer validate --strict', $content);
        $this->assertStringContainsString('composer audit', $content);
        $this->assertStringContainsString('vendor/bin/phpunit', $content);
        $this->assertStringNotContainsString('secrets.', $content);
        $this->assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $content);
    }

    public function test_phpstan_config_and_baseline_exist(): void
    {
        $baseDir = dirname(__DIR__, 2);
        $this->assertFileExists($baseDir . '/phpstan.neon');
        $this->assertFileExists($baseDir . '/phpstan-baseline.neon');
    }
}
