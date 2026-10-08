<?php

use App\Jobs\UpdateWebCapabilityCertificate;
use App\Models\AuditEvent;
use App\Models\IpAllocation;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\WebDomain;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function toolsReadyNode(): Node
{
    $node = Node::factory()->create(['hostname' => 'node.example.test']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    IpAllocation::factory()->for($node)->create();

    return $node;
}

test('an admin creates the node hostname web domain from the node page', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = toolsReadyNode();

    $this->actingAs($admin)
        ->post(route('nodes.tools-domain.store', $node), ['ssl_mode' => 'manual'])
        ->assertRedirect(route('nodes.edit', $node));

    $domain = WebDomain::where('domain', 'node.example.test')->sole();

    expect($domain->account->is_platform)->toBeTrue()
        ->and($domain->ssl_mode->value)->toBe('manual')
        ->and(AuditEvent::where('action', 'node.tools_domain_created')->where('actor_id', $admin->id)->exists())->toBeTrue();
});

test('only the two certificate modes are accepted', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = toolsReadyNode();

    $this->actingAs($admin)
        ->post(route('nodes.tools-domain.store', $node), ['ssl_mode' => 'none'])
        ->assertSessionHasErrors('ssl_mode');

    expect(WebDomain::count())->toBe(0);
});

test('a node that cannot host the domain yet gets a clear error, not a server error', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = Node::factory()->create(['hostname' => 'bare.example.test']);

    $this->actingAs($admin)
        ->post(route('nodes.tools-domain.store', $node), ['ssl_mode' => 'lets_encrypt'])
        ->assertSessionHasErrors('tools_domain');
});

test('a customer cannot create or confirm a node tools domain', function () {
    $node = toolsReadyNode();
    $owner = Membership::factory()->owner()->create()->user;

    $this->actingAs($owner)->post(route('nodes.tools-domain.store', $node), ['ssl_mode' => 'manual'])->assertForbidden();
    $this->actingAs($owner)->post(route('nodes.tools-domain.certificate', $node))->assertForbidden();
});

test('confirming a manual certificate records it and re-sends the web configuration', function () {
    Queue::fake();
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = toolsReadyNode();

    $this->actingAs($admin)->post(route('nodes.tools-domain.store', $node), ['ssl_mode' => 'manual']);
    $this->actingAs($admin)->post(route('nodes.tools-domain.certificate', $node))->assertRedirect(route('nodes.edit', $node));

    $domain = WebDomain::where('domain', 'node.example.test')->sole();

    expect($domain->certificate_issued_at)->not->toBeNull()
        ->and($domain->certificate_authority)->toBe('manual')
        ->and(AuditEvent::where('action', 'node.tools_certificate_confirmed')->exists())->toBeTrue();

    Queue::assertPushed(UpdateWebCapabilityCertificate::class, fn ($job) => $job->webDomain->is($domain));
});

test('confirming is refused without a manual-certificate domain', function () {
    Queue::fake();
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = toolsReadyNode();

    $this->actingAs($admin)->post(route('nodes.tools-domain.certificate', $node))->assertSessionHasErrors('tools_domain');

    $this->actingAs($admin)->post(route('nodes.tools-domain.store', $node), ['ssl_mode' => 'lets_encrypt']);
    $this->actingAs($admin)->post(route('nodes.tools-domain.certificate', $node))->assertSessionHasErrors('tools_domain');

    Queue::assertNotPushed(UpdateWebCapabilityCertificate::class);
});

test('the node page reports the state of the tools address', function () {
    $admin = Membership::factory()->providerAdmin()->create()->user;
    $node = toolsReadyNode();
    NodeCapability::factory()->for($node)->create(['capability' => 'mail.webmail.v1']);

    $this->actingAs($admin)->get(route('nodes.edit', $node))
        ->assertInertia(fn (Assert $page) => $page
            ->where('node.tools.hostname', 'node.example.test')
            ->where('node.tools.domain', null)
            ->where('node.tools.webmail', ['declared' => true, 'available' => false])
            ->where('node.tools.adminer', ['declared' => false, 'available' => false]));

    WebDomain::factory()->for($node)->create(['domain' => 'node.example.test', 'ssl_mode' => 'manual', 'certificate_issued_at' => null]);

    $this->actingAs($admin)->get(route('nodes.edit', $node))
        ->assertInertia(fn (Assert $page) => $page
            ->where('node.tools.domain.ssl_mode', 'manual')
            ->where('node.tools.domain.certificate_issued_at', null)
            ->where('node.tools.domain.certificate_path', '/var/lib/lesta/acme/certs/node.example.test')
            ->where('node.tools.webmail.available', false));

    WebDomain::query()->where('domain', 'node.example.test')->update(['certificate_issued_at' => now()]);

    $this->actingAs($admin)->get(route('nodes.edit', $node))
        ->assertInertia(fn (Assert $page) => $page->where('node.tools.webmail', ['declared' => true, 'available' => true]));
});
