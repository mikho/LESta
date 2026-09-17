# Node Health

Agent registration, heartbeat, capability reporting, package facts, service health, and drift reporting. This is not a shell gateway and must not expose arbitrary process, service, or package execution to Laravel.

## No standalone installer

There is no `install.sh` in this directory. Like `firewall`, this manifest exists so other services can declare a real `depends_on: ["node.health.v1"]` entry, but the bootstrap itself (vendored agent binary install, checksum verification, and the agent's own self-test) is embedded automatically inside every operator-run installer that needs it, the first time any one of them runs on a fresh node. An operator never invokes it directly or names `node-health` in `install-selected.sh --services`.
