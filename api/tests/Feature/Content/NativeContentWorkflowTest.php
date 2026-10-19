<?php

namespace Tests\Feature\Content;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Content\Contracts\ContentListQuery;
use App\Domains\Content\NativeContentWorkflow;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\InternalApi\OperationSchemas;
use App\Domains\Notifications\Internal\EventJournal;
use App\Domains\Subscription\Internal\LockedEntitlementQueries;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeContentWorkflowTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors;

    private NativeContentWorkflow $workflow;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate();
        $this->workflow = app(NativeContentWorkflow::class);
        $this->admin = $this->workflowActor(true, ['content.manage', 'content.read']);
        $this->authenticateWorkflowActor($this->admin);
    }

    public function test_publication_schedule_audience_and_access_markers_are_enforced_before_bodies_leave_owner(): void
    {
        $public = $this->createContent();
        $subscriberOnly = $this->createContent(['title' => 'Subscriber only', 'audience' => 'subscribed']);
        $this->createContent(['title' => 'Future', 'publish_at' => time() + 3600]);
        $this->createContent(['title' => 'Expired', 'expire_at' => time() - 60]);
        $this->createContent(['title' => 'Draft', 'show' => false]);
        $article = $this->createContent(['title' => 'Article', 'body' => 'Public <!--access start-->subscriber-secret<!--access end-->'], 'knowledge');
        $visitor = $this->workflowActor(); $this->authenticateWorkflowActor($visitor);
        $page = $this->workflow->listing($visitor->id, new ContentListQuery('notices'));
        $this->assertSame([$public['id']], array_column($page->items, 'id'));
        $this->assertArrayNotHasKey('content', $page->items[0]);
        $this->assertNull($this->workflow->find($visitor->id, 'notices', $subscriberOnly['id']));
        $body = $this->workflow->find($visitor->id, 'knowledge', $article['id']);
        $this->assertStringNotContainsString('subscriber-secret', $body['body']);
        $this->assertStringNotContainsString('<!--access', $body['body']);
        [$subscriber] = $this->subscribe(); $this->authenticateWorkflowActor($subscriber);
        $this->assertNotNull($this->workflow->find($subscriber->id, 'notices', $subscriberOnly['id']));
        $this->assertStringContainsString('subscriber-secret', $this->workflow->find($subscriber->id, 'knowledge', $article['id'])['body']);
        $this->assertSame(['categories' => ['Help']], $this->workflow->categories($subscriber->id, 'en'));
        $this->authenticateWorkflowActor($this->admin);
        $this->assertCount(5, $this->workflow->listing($this->admin->id, new ContentListQuery('notices'), true)->items);
    }

    public function test_permanent_original_key_recovery_and_versioned_visibility_and_delete_keep_one_effect(): void
    {
        $key = $this->workflowKey(); $input = $this->notice();
        $created = $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'create', $input, $key));
        $this->assertSame($created, $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'create', $input, $key)));
        $this->workflowHttpFailure(409, fn () => $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id,
            'notices', 'create', array_replace($input, ['title' => 'Changed original intent']), $key)));
        $this->assertSame(1, DB::table('tx_content_request')->count());
        $this->assertSame(1, DB::table('tx_content_audit')->count());
        $this->assertSame(1, DB::table('tx_module_event')->where('kind', 'content.changed.v1')->count());
        $visibility = ['content_id' => $created['id'], 'expected_version' => '1', 'show' => false];
        $hidden = $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'visibility', $visibility, $this->workflowKey()));
        $this->assertSame('2', $hidden['row_version']); $this->assertFalse($hidden['show']);
        $this->workflowHttpFailure(409, fn () => $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id,
            'notices', 'visibility', $visibility, $this->workflowKey())));
        $deleted = $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'delete',
            ['content_id' => $created['id'], 'expected_version' => '2'], $this->workflowKey()));
        $this->assertTrue($deleted['ok']); $this->assertSame('3', $deleted['row_version']);
        $this->assertSame(1, DB::table('tx_notice')->count());
        $this->assertNotNull(DB::table('tx_notice')->value('deleted_at'));
        $this->assertNull($this->workflow->find($this->admin->id, 'notices', $created['id'], true));
        $audit = $this->workflow->audit($this->admin->id, '0', 2);
        $this->assertTrue($audit->meta['has_more']);
        $this->assertSame(['create', 'visibility'], array_column($audit->items, 'operation'));
        $this->assertStringNotContainsString($input['content'], json_encode($audit->items, JSON_THROW_ON_ERROR));
    }

    public function test_sort_requires_the_complete_current_collection_and_rolls_back_on_any_stale_version(): void
    {
        $first = $this->createContent(['title' => 'First']); $second = $this->createContent(['title' => 'Second']);
        $incomplete = ['items' => [['id' => $second['id'], 'expected_version' => '1']]];
        $this->workflowHttpFailure(409, fn () => $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id,
            'notices', 'sort', $incomplete, $this->workflowKey())));
        $stale = ['items' => [['id' => $second['id'], 'expected_version' => '1'], ['id' => $first['id'], 'expected_version' => '9']]];
        $this->workflowHttpFailure(409, fn () => $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id,
            'notices', 'sort', $stale, $this->workflowKey())));
        $this->assertSame([1, 1], DB::table('tx_notice')->orderBy('id')->pluck('row_version')->map(fn ($version) => (int) $version)->all());
        $complete = ['items' => [['id' => $second['id'], 'expected_version' => '1'], ['id' => $first['id'], 'expected_version' => '1']]];
        $key = $this->workflowKey();
        $sorted = $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'sort', $complete, $key));
        $this->assertSame($sorted, $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'sort', $complete, $key)));
        $page = $this->workflow->listing($this->admin->id, new ContentListQuery('notices'), true);
        $this->assertSame([$second['id'], $first['id']], array_column($page->items, 'id'));
        $this->assertSame(['2', '2'], array_column($page->items, 'row_version'));
        OperationSchemas::output('content.notices.list', $page->items);
    }

    public function test_revoked_current_grant_and_current_session_are_rechecked_before_receipt_replay(): void
    {
        $token = $this->authenticateWorkflowActor($this->admin); $key = $this->workflowKey(); $input = $this->notice();
        $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'create', $input, $key));
        DB::table('tx_internal_actor_grant')->where('user_id', $this->admin->id)->where('permission', 'content.manage')->delete();
        $this->workflowHttpFailure(403, fn () => $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id,
            'notices', 'create', $input, $key)));
        $token->delete();
        try { $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'create', $input, $key)); $this->fail('Expected session rejection'); }
        catch (AuthenticationException) { $this->assertSame(1, DB::table('tx_content_request')->count()); }
    }

    public function test_event_failure_rolls_back_every_owner_record_and_audit_corruption_is_rejected(): void
    {
        $events = Mockery::mock(EventJournal::class);
        $events->shouldReceive('appendLocked')->once()->andThrow(new \RuntimeException('INJECTED_CONTENT_EVENT_FAILURE'));
        $failing = new NativeContentWorkflow(app(CurrentSessionAuthorization::class), app(LockedEntitlementQueries::class),
            $events, app(CurrentInvocation::class));
        $key = $this->workflowKey();
        try { $this->invokeWorkflow(fn () => $failing->execute($this->admin->id, 'notices', 'create', $this->notice(), $key)); $this->fail('Expected event failure'); }
        catch (\RuntimeException $error) { $this->assertSame('INJECTED_CONTENT_EVENT_FAILURE', $error->getMessage()); }
        foreach (['tx_notice', 'tx_content_audit', 'tx_content_request'] as $table) $this->assertSame(0, DB::table($table)->count());
        $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, 'notices', 'create', $this->notice(), $key));
        DB::table('tx_content_audit')->update(['content_hash' => str_repeat('0', 64)]);
        $this->expectException(\LogicException::class);
        $this->workflow->audit($this->admin->id, '0', 10);
    }

    private function createContent(array $changes = [], string $kind = 'notices'): array
    {
        $input = $kind === 'notices' ? $this->notice() : ['title' => 'Knowledge', 'body' => 'Knowledge body',
            'category' => 'Help', 'language' => 'en', 'show' => true, 'sort' => 0, 'audience' => 'authenticated', 'publish_at' => null, 'expire_at' => null];
        return $this->invokeWorkflow(fn () => $this->workflow->execute($this->admin->id, $kind, 'create',
            array_replace($input, $changes), $this->workflowKey()));
    }

    private function notice(): array
    {
        return ['title' => 'Notice', 'content' => 'Private editor body', 'img_url' => null, 'tags' => ['help'],
            'popup' => false, 'show' => true, 'sort' => 0, 'audience' => 'authenticated', 'publish_at' => null, 'expire_at' => null];
    }
}
