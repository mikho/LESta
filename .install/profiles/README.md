# Web Profiles

A blank server selects exactly one immutable web profile during bootstrap: `nginx`, `apache`, or `both`.

- `nginx` installs nginx as the public listener on ports 80 and 443.
- `apache` installs Apache as the public listener on ports 80 and 443.
- `both` installs nginx as the public listener on ports 80 and 443 and Apache as a loopback-only backend on a fixed LESta-owned port.

Profiles are data consumed by the installer dependency planner. A tenant cannot select, switch, or override the profile. Profile migration is a separate operator workflow and is not part of unattended bootstrap.
