<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provisioner Driver
    |--------------------------------------------------------------------------
    |
    | The provisioner adapter used to apply provisioning operations against a
    | node. "fake" is a stateless, deterministic adapter used where no real
    | node is available. "daemon" is the real adapter: it leaves the operation
    | Dispatched for delivery to the owning node's agent daemon on its next
    | heartbeat, which applies it and reports the result back over
    | agent/v1/operation-results.
    |
    */

    'driver' => env('PROVISIONING_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | DNS Name Servers
    |--------------------------------------------------------------------------
    |
    | The name servers every hosted zone names as its own (its apex NS set),
    | shown to customers as what to enter where they registered a domain. They
    | must match the agent's (agent/cmd/lesta-agent/main.go, bind9's
    | Nameservers), which renders them into each zone. Comma-separated.
    |
    */

    'dns_nameservers' => array_values(array_filter(array_map(
        fn (string $nameserver): string => rtrim(trim($nameserver), '.'),
        explode(',', (string) env('DNS_NAMESERVERS', 'ns1.lesta-hosting.example,ns2.lesta-hosting.example')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Dispatch Deadline
    |--------------------------------------------------------------------------
    |
    | Minutes from dispatch after which an operation is considered overdue.
    | Set once, at dispatch time, and never moved afterwards.
    |
    */

    'dispatch_deadline_minutes' => (int) env('PROVISIONING_DISPATCH_DEADLINE_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Staleness Window
    |--------------------------------------------------------------------------
    |
    | Minutes a provisioning operation may remain "pending" before the
    | provisioning:dispatch-pending backstop command re-dispatches it.
    |
    */

    'stale_after_minutes' => (int) env('PROVISIONING_STALE_AFTER_MINUTES', 5),

];
