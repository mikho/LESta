<?php

use App\Enums\ProvisioningStatus;
use App\Http\Requests\Files\StoreFileRequest;
use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Models\WebDomain;

test('an owner can dispatch an observe operation to list a path', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->postJson(route('domains.files.observe', $webDomain), ['path' => 'sub/dir']);

    $response->assertStatus(202)->assertJsonStructure(['operation_id']);

    $operation = ProvisioningOperation::find($response->json('operation_id'));

    expect($operation)->not->toBeNull()
        ->and($operation->capability)->toBe('files.manager.v1')
        ->and($operation->operation->value)->toBe('observe')
        ->and($operation->resource_id)->toBe($webDomain->uuid)
        ->and($operation->payload['path'])->toBe('sub/dir');
});

test('a non-owner member can also observe, since it is a read', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;

    $this->actingAs($member)
        ->postJson(route('domains.files.observe', $webDomain), ['path' => ''])
        ->assertStatus(202);
});

test('an owner can create a new file, recording an audit event', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->postJson(route('domains.files.store', $webDomain), [
        'path' => 'index.html',
        'content_base64' => base64_encode('<h1>hi</h1>'),
    ]);

    $response->assertStatus(202);

    $operation = ProvisioningOperation::find($response->json('operation_id'));

    expect($operation->operation->value)->toBe('create')
        ->and($operation->payload['path'])->toBe('index.html')
        ->and($operation->payload['content_base64'])->toBe(base64_encode('<h1>hi</h1>'))
        ->and(AuditEvent::where('action', 'web_domain.file_created')->where('auditable_id', $webDomain->id)->exists())->toBeTrue();
});

test('a non-owner member cannot create a file', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $member = Membership::factory()->for($webDomain->account)->member()->create()->user;

    $this->actingAs($member)
        ->postJson(route('domains.files.store', $webDomain), ['path' => 'index.html'])
        ->assertForbidden();
});

test('an owner can rename a file via update', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->putJson(route('domains.files.update', $webDomain), [
        'path' => 'old.html',
        'new_path' => 'new.html',
    ]);

    $response->assertStatus(202);

    $operation = ProvisioningOperation::find($response->json('operation_id'));

    expect($operation->operation->value)->toBe('update')
        ->and($operation->payload['new_path'])->toBe('new.html');
});

test('update requires exactly one of new_path or content_base64', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $this->actingAs($owner)
        ->putJson(route('domains.files.update', $webDomain), ['path' => 'index.html'])
        ->assertInvalid(['new_path']);

    $this->actingAs($owner)
        ->putJson(route('domains.files.update', $webDomain), [
            'path' => 'index.html',
            'new_path' => 'other.html',
            'content_base64' => base64_encode('x'),
        ])
        ->assertInvalid(['new_path']);
});

test('an owner can delete a file', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $response = $this->actingAs($owner)->deleteJson(route('domains.files.destroy', $webDomain), [
        'path' => 'gone.html',
    ]);

    $response->assertStatus(202);

    $operation = ProvisioningOperation::find($response->json('operation_id'));

    expect($operation->operation->value)->toBe('delete')
        ->and($operation->payload['recursive'])->toBeFalse();
});

test('operationStatus reports the real operation outcome', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $webDomain->getMorphClass(),
        'provisionable_id' => $webDomain->id,
        'node_id' => $node->id,
        'resource_id' => $webDomain->uuid,
        'capability' => 'files.manager.v1',
        'status' => ProvisioningStatus::Applied,
        'data' => ['type' => 'file', 'content_base64' => 'aGk='],
    ]);

    $this->actingAs($owner)
        ->getJson(route('domains.files.operation-status', [$webDomain, $operation]))
        ->assertOk()
        ->assertJson([
            'status' => 'applied',
            'data' => ['type' => 'file', 'content_base64' => 'aGk='],
        ]);
});

test('operationStatus 404s for an operation belonging to a different web domain', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $otherDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($otherDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$otherDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $operation = ProvisioningOperation::factory()->create([
        'provisionable_type' => $otherDomain->getMorphClass(),
        'provisionable_id' => $otherDomain->id,
        'node_id' => $node->id,
        'resource_id' => $otherDomain->uuid,
        'capability' => 'files.manager.v1',
    ]);

    $this->actingAs($owner)
        ->getJson(route('domains.files.operation-status', [$webDomain, $operation]))
        ->assertNotFound();
});

test('an owner can upload a file well past the old 64 KB payload limit', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $content = base64_encode(random_bytes(1024 * 1024));

    $response = $this->actingAs($owner)->postJson(route('domains.files.store', $webDomain), ['path' => 'big.bin', 'content_base64' => $content]);

    $response->assertStatus(202);

    expect(ProvisioningOperation::find($response->json('operation_id'))->payload['content_base64'])->toBe($content);
});

test('an upload over the size limit is refused with a clear message, not a server error', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);
    $owner = Membership::factory()->for($webDomain->account)->owner()->create()->user;

    $tooBig = str_repeat('A', StoreFileRequest::maxContentBase64Length() + 4);

    $this->actingAs($owner)
        ->postJson(route('domains.files.store', $webDomain), ['path' => 'huge.bin', 'content_base64' => $tooBig])
        ->assertStatus(422)
        ->assertJsonValidationErrors('content_base64')
        ->assertJsonPath('errors.content_base64.0', 'This file is larger than 4 MB, the most the file manager can upload.');

    expect(ProvisioningOperation::where('capability', 'files.manager.v1')->where('resource_id', $webDomain->uuid)->exists())->toBeFalse();
});
