<?php

use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\PhpVersion;
use App\Enums\SslMode;
use App\Enums\WebServer;
use App\Exceptions\NoWebCapableNodeAvailableException;
use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\DnsZone;
use App\Models\IpAllocation;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\TenantDatabase;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;

test('toProvisioningPayload returns exactly the expected keys with no secret-shaped values', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $allocation = IpAllocation::factory()->for($node)->create(['ip_address' => '203.0.113.10']);
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $webDomain = WebDomain::factory()
        ->for($account)
        ->for($node)
        ->for($allocation)
        ->create(['domain' => 'example.com', 'ssl_mode' => SslMode::Manual]);
    WebDomainAlias::factory()->for($webDomain)->create(['alias' => 'www.example.com']);

    $payload = $webDomain->toProvisioningPayload('web.nginx.v1');

    expect($payload)->toBe([
        'domain' => 'example.com',
        'aliases' => ['www.example.com'],
        'ip_address' => '203.0.113.10',
        'web_template' => 'default',
        'account_id' => $account->id,
        'account_username' => 'lesta-t'.$account->id,
        'php_socket' => null,
        'adminer_socket' => null,
        'webmail_socket' => null,
        'ssl' => ['mode' => 'manual'],
        'suspended' => false,
        'waf_mode' => 'off',
        'waf_excluded_rules' => [],
        'waf_preset' => 'none',
        'hotlink_protection' => false,
        'hotlink_allowed_hosts' => [],
        'redirects' => [],
        'ip_rules' => [],
    ])
        ->and(array_keys($payload))->toBe(['domain', 'aliases', 'ip_address', 'web_template', 'account_id', 'account_username', 'php_socket', 'adminer_socket', 'webmail_socket', 'ssl', 'suspended', 'waf_mode', 'waf_excluded_rules', 'waf_preset', 'hotlink_protection', 'hotlink_allowed_hosts', 'redirects', 'ip_rules']);
});

test('toProvisioningPayload reports a real php_socket once php_version is set', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => PhpVersion::Php83]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['php_socket'])
        ->toBe("/run/lesta-php/8.3/{$webDomain->uuid}.sock");
});

test('toProvisioningPayload reports a null php_socket when php_version is not set', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['php_version' => null]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['php_socket'])->toBeNull();
});

/**
 * A web domain for the node's own hostname with every tools-host condition met unless overridden:
 * nginx rendering, a certificate issued, and the given tool capability non-suspended.
 */
function toolsHostDomain(string $capability, array $domainAttributes = [], bool $withCapability = true, string $domain = 'node.example.test'): WebDomain
{
    $node = Node::factory()->create(['hostname' => 'node.example.test']);
    $webDomain = WebDomain::factory()->for($node)->create(array_merge([
        'domain' => $domain,
        'certificate_issued_at' => now(),
    ], $domainAttributes));
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    if ($withCapability) {
        NodeCapability::factory()->for($node)->create(['capability' => $capability]);
    }

    return $webDomain;
}

test('toProvisioningPayload reports the fixed adminer_socket for the node\'s own hostname once every condition is met', function () {
    expect(toolsHostDomain('tools.adminer.v1')->toProvisioningPayload('web.nginx.v1')['adminer_socket'])
        ->toBe('/run/lesta-adminer/adminer.sock');
});

test('toProvisioningPayload never reports an adminer_socket for a customer\'s own domain', function () {
    $webDomain = toolsHostDomain('tools.adminer.v1', domain: 'customer.example', domainAttributes: ['php_version' => PhpVersion::Php83]);
    TenantDatabase::factory()->for($webDomain->account)->for($webDomain->node)->create();

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['adminer_socket'])->toBeNull();
});

test('toProvisioningPayload reports a null adminer_socket for web.apache.v1 regardless of eligibility', function () {
    expect(toolsHostDomain('tools.adminer.v1', ['web_server' => WebServer::Apache])->toProvisioningPayload('web.apache.v1')['adminer_socket'])->toBeNull();
});

test('toProvisioningPayload reports a null adminer_socket when the node has no tools.adminer.v1 capability', function () {
    expect(toolsHostDomain('tools.adminer.v1', withCapability: false)->toProvisioningPayload('web.nginx.v1')['adminer_socket'])->toBeNull();
});

test('toProvisioningPayload reports a null adminer_socket until a certificate is issued', function () {
    expect(toolsHostDomain('tools.adminer.v1', ['certificate_issued_at' => null])->toProvisioningPayload('web.nginx.v1')['adminer_socket'])->toBeNull();
});

test('toProvisioningPayload reports the fixed webmail_socket for the node\'s own hostname once every condition is met', function () {
    expect(toolsHostDomain('mail.webmail.v1')->toProvisioningPayload('web.nginx.v1')['webmail_socket'])
        ->toBe('/run/lesta-webmail/webmail.sock');
});

test('toProvisioningPayload reports a null webmail_socket for a domain that is not the node\'s own hostname', function () {
    expect(toolsHostDomain('mail.webmail.v1', domain: 'www.example.test')->toProvisioningPayload('web.nginx.v1')['webmail_socket'])->toBeNull();
});

test('toProvisioningPayload reports a null webmail_socket until a certificate is issued', function () {
    expect(toolsHostDomain('mail.webmail.v1', ['certificate_issued_at' => null])->toProvisioningPayload('web.nginx.v1')['webmail_socket'])->toBeNull();
});

test('toProvisioningPayload reports a null webmail_socket when the node has no mail.webmail.v1 capability', function () {
    expect(toolsHostDomain('mail.webmail.v1', withCapability: false)->toProvisioningPayload('web.nginx.v1')['webmail_socket'])->toBeNull();
});

test('toProvisioningPayload reports a null webmail_socket when the node\'s webmail capability is suspended', function () {
    $webDomain = toolsHostDomain('mail.webmail.v1', withCapability: false);
    NodeCapability::factory()->for($webDomain->node)->suspended()->create(['capability' => 'mail.webmail.v1']);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['webmail_socket'])->toBeNull();
});

test('toProvisioningPayload still reports the webmail_socket when the node has no mail hostname set', function () {
    $webDomain = toolsHostDomain('mail.webmail.v1');
    $webDomain->node->forceFill(['mail_hostname' => null])->save();

    expect($webDomain->refresh()->toProvisioningPayload('web.nginx.v1')['webmail_socket'])->toBe('/run/lesta-webmail/webmail.sock');
});

test('toProvisioningPayload reports a null webmail_socket for web.apache.v1 regardless of eligibility', function () {
    expect(toolsHostDomain('mail.webmail.v1')->toProvisioningPayload('web.apache.v1')['webmail_socket'])->toBeNull();
});

test('toPhpFpmProvisioningPayload reports the account\'s own real node identity', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['php_version' => PhpVersion::Php82]);

    expect($webDomain->toPhpFpmProvisioningPayload())->toBe([
        'account_id' => $account->id,
        'account_username' => 'lesta-t'.$account->id,
        'php_version' => '8.2',
        'suspended' => false,
    ]);
});

test('toPhpFpmProvisioningPayload accepts an explicit version override for a delete after turning PHP off', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create(['system_username' => 'lesta-t'.$account->id]);
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['php_version' => null]);

    expect($webDomain->toPhpFpmProvisioningPayload(PhpVersion::Php81)['php_version'])->toBe('8.1');
});

test('toPhpFpmProvisioningPayload throws when no version is set and none is passed explicitly', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    AccountNodeIdentity::factory()->for($account)->for($node)->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['php_version' => null]);

    $webDomain->toPhpFpmProvisioningPayload();
})->throws(RuntimeException::class);

test('toPhpFpmProvisioningPayload throws when no AccountNodeIdentity exists yet', function () {
    $account = Account::factory()->create();
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($account)->for($node)->create(['php_version' => PhpVersion::Php83]);

    $webDomain->toPhpFpmProvisioningPayload();
})->throws(RuntimeException::class);

test('toProvisioningPayload omits ssl certificate paths until a certificate has actually been issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['ssl_mode' => SslMode::LetsEncrypt, 'certificate_issued_at' => null]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['ssl'])->toBe(['mode' => 'lets_encrypt']);
});

test('toProvisioningPayload adds ssl certificate paths for web.nginx.v1 once a certificate is issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'domain' => 'issued.example.com',
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => now(),
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['ssl'])->toBe([
        'mode' => 'lets_encrypt',
        'certificate_path' => '/var/lib/lesta/acme/certs/issued.example.com/fullchain.pem',
        'private_key_path' => '/var/lib/lesta/acme/certs/issued.example.com/privkey.pem',
    ]);
});

test('toProvisioningPayload adds ssl certificate paths for web.apache.v1 once a certificate is issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'domain' => 'apache-issued.example.com',
        'web_server' => WebServer::Apache,
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => now(),
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.apache.v1')['ssl'])->toBe([
        'mode' => 'lets_encrypt',
        'certificate_path' => '/var/lib/lesta/acme/certs/apache-issued.example.com/fullchain.pem',
        'private_key_path' => '/var/lib/lesta/acme/certs/apache-issued.example.com/privkey.pem',
    ]);
});

test('toProvisioningPayload omits ssl certificate paths for web.apache.v1 until a certificate has actually been issued', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_server' => WebServer::Apache,
        'ssl_mode' => SslMode::LetsEncrypt,
        'certificate_issued_at' => null,
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.apache.v1')['ssl'])->toBe(['mode' => 'lets_encrypt']);
});

test('resolveDnsZone finds the exact-match zone for this domain', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'zone-match.example.com']);
    $zone = DnsZone::factory()->for($node)->create(['domain' => 'zone-match.example.com']);

    expect($webDomain->resolveDnsZone()?->id)->toBe($zone->id);
});

test('resolveDnsZone returns null when no exact-match zone exists', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['domain' => 'no-zone.example.com']);
    DnsZone::factory()->for($node)->create(['domain' => 'unrelated.example.com']);

    expect($webDomain->resolveDnsZone())->toBeNull();
});

test('toProvisioningPayload reflects the current suspension state', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->suspended()->for($node)->create();
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['suspended'])->toBeTrue();
});

test('toProvisioningPayload overrides web_template to apache-proxy for nginx when web_server is apache', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_template' => 'custom',
        'web_server' => WebServer::Apache,
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['web_template'])->toBe('apache-proxy')
        ->and($webDomain->toProvisioningPayload('web.apache.v1')['web_template'])->toBe('custom');
});

test('toProvisioningPayload never overrides web_template when web_server is nginx', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create([
        'web_template' => 'custom',
        'web_server' => WebServer::Nginx,
    ]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1')['web_template'])->toBe('custom');
});

test('nginx is preferred over apache when a node has both capabilities active', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    [$resolvedNode, $capabilities] = app(ResolvesWebCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capabilities)->toBe(['web.nginx.v1']);
});

test('a node with only an active apache capability is used as a fallback', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    [$resolvedNode, $capabilities] = app(ResolvesWebCapableNode::class)->resolve();

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capabilities)->toBe(['web.apache.v1']);
});

test('resolve throws when no node has an active web capability', function () {
    Node::factory()->create();

    app(ResolvesWebCapableNode::class)->resolve();
})->throws(NoWebCapableNodeAvailableException::class);

test('resolveFor scopes the same nginx-over-apache priority to an already-assigned node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    expect(app(ResolvesWebCapableNode::class)->resolveFor($node))->toBe(['web.nginx.v1']);
});

test('resolve with web_server apache returns apache alone on an apache-only node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    [$resolvedNode, $capabilities] = app(ResolvesWebCapableNode::class)->resolve('apache');

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capabilities)->toBe(['web.apache.v1']);
});

test('resolve with web_server apache returns apache then nginx on a both-profile node', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);
    NodeCapability::factory()->for($node)->create(['capability' => 'web.apache.v1']);

    [$resolvedNode, $capabilities] = app(ResolvesWebCapableNode::class)->resolve('apache');

    expect($resolvedNode->id)->toBe($node->id)
        ->and($capabilities)->toBe(['web.apache.v1', 'web.nginx.v1']);
});

test('resolve with web_server apache throws when no node has an active apache capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    app(ResolvesWebCapableNode::class)->resolve('apache');
})->throws(NoWebCapableNodeAvailableException::class);

test('resolveFor with web_server apache throws when the given node has no active apache capability', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'web.nginx.v1']);

    app(ResolvesWebCapableNode::class)->resolveFor($node, 'apache');
})->throws(NoWebCapableNodeAvailableException::class);

test('only the nginx payload carries the WAF fields, with the stored mode and rule ids', function () {
    $node = Node::factory()->create();
    $webDomain = WebDomain::factory()->for($node)->create(['waf_mode' => 'block', 'waf_excluded_rules' => [942100, 920350], 'waf_preset' => 'wordpress', 'hotlink_protection' => true, 'hotlink_allowed_hosts' => ['partner.example']]);
    AccountNodeIdentity::factory()->for($webDomain->account)->for($node)->create(['system_username' => 'lesta-t'.$webDomain->account_id]);

    expect($webDomain->toProvisioningPayload('web.nginx.v1'))->toMatchArray(['waf_mode' => 'block', 'waf_excluded_rules' => [942100, 920350], 'waf_preset' => 'wordpress', 'hotlink_protection' => true, 'hotlink_allowed_hosts' => ['partner.example']])
        ->and($webDomain->toProvisioningPayload('web.apache.v1'))->not->toHaveKeys(['waf_mode', 'waf_excluded_rules', 'waf_preset', 'hotlink_protection', 'hotlink_allowed_hosts']);
});
