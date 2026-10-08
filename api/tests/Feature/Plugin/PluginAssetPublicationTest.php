<?php

namespace Tests\Feature\Plugin;

use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginAssetPublicationTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Phase3AssetFixture'));
        File::deleteDirectory(public_path('plugins/phase3_asset_fixture'));
        File::deleteDirectory(public_path('plugins/another_plugin'));
        parent::tearDown();
    }

    public function test_republish_is_idempotent_replaces_only_owned_assets_and_cleans_removed_files(): void
    {
        $source = base_path('plugins/Phase3AssetFixture/resources/assets');
        File::ensureDirectoryExists($source);
        File::put($source . '/version.txt', 'v1');
        File::put($source . '/old.txt', 'legacy');
        $other = public_path('plugins/another_plugin');
        File::ensureDirectoryExists($other);
        File::put($other . '/keep.txt', 'another module');

        $manager = app(PluginManager::class);
        $manager->publishAssets('phase3_asset_fixture');
        $published = public_path('plugins/phase3_asset_fixture');
        $this->assertSame('v1', File::get($published . '/version.txt'));

        File::delete($source . '/old.txt');
        File::put($source . '/version.txt', 'v2');
        $manager->publishAssets('phase3_asset_fixture');
        $manager->publishAssets('phase3_asset_fixture');

        $this->assertSame('v2', File::get($published . '/version.txt'));
        $this->assertFileDoesNotExist($published . '/old.txt');
        $this->assertSame('another module', File::get($other . '/keep.txt'));
    }

    public function test_an_invalid_plugin_identity_cannot_remove_other_modules_assets(): void
    {
        $other = public_path('plugins/another_plugin');
        File::ensureDirectoryExists($other);
        File::put($other . '/keep.txt', 'must survive');

        try {
            app(PluginManager::class)->publishAssets('../another_plugin');
            $this->fail('An unsafe plugin identifier must be rejected');
        } catch (\InvalidArgumentException) {
            $this->assertSame('must survive', File::get($other . '/keep.txt'));
        }
    }
}
