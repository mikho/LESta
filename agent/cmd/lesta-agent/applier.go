package main

import (
	"context"
	"errors"
	"fmt"
	"net"
	"os"
	"os/signal"
	"os/user"
	"strconv"
	"syscall"
	"time"

	"github.com/mikho/LESta/agent/internal/applier"
	"github.com/mikho/LESta/agent/internal/capability/acme"
	"github.com/mikho/LESta/agent/internal/capability/apache"
	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/capability/bind9"
	"github.com/mikho/LESta/agent/internal/capability/cron"
	"github.com/mikho/LESta/agent/internal/capability/files"
	"github.com/mikho/LESta/agent/internal/capability/identity"
	"github.com/mikho/LESta/agent/internal/capability/mail"
	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/capability/metrics"
	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/capability/phpfpm"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// This file is the root applier's wiring: which capabilities it applies, which of its instances
// hosts each, and the production configuration each has when it runs as root. See
// internal/applier and the vault's "Privileged Applier Split Plan".
//
// An instance is one applier process with its own socket and its own sandbox. The default instance
// hosts the capabilities that write configuration files; the "identity" instance hosts the one that
// edits the account database (/etc/passwd and friends), which needs write access to all of /etc and
// would otherwise widen the sandbox of every other capability. The "tenant" instance applies what
// works on the tenants' own data (the file manager, cron, usage measurement), and "backup" the
// one that can also reach the network, so a process that reads all tenant data is not the one
// that can send it out.

const (
	// applierUser is the only non-root user an applier serves.
	applierUser = "lesta-agent"
	// applierSocketDir holds the instances' sockets.
	applierSocketDir = "/run/lesta"
)

// applierInstance is one applier process.
type applierInstance struct {
	// name is the argument to "lesta-agent applier"; the default instance has none.
	name string
	// socket is where it listens.
	socket string
	// hosted is the capabilities it applies.
	hosted map[string]bool
	// timeout bounds one operation; zero means the applier's default.
	timeout time.Duration
}

var applierInstances = []applierInstance{
	{
		name:   "",
		socket: applierSocketDir + "/applier.sock",
		hosted: map[string]bool{
			dnsBind9Capability:       true,
			webNginxCapability:       true,
			webPhpFpmCapability:      true,
			webApacheCapability:      true,
			mailSmtpImapCapability:   true,
			databaseTenantCapability: true,
			tlsAcmeCapability:        true,
		},
	},
	{
		name:   "identity",
		socket: applierSocketDir + "/applier-identity.sock",
		hosted: map[string]bool{
			systemAccountIdentityCapability: true,
		},
	},
	{
		name:   "tenant",
		socket: applierSocketDir + "/applier-tenant.sock",
		hosted: map[string]bool{
			filesManagerCapability:  true,
			schedulerCronCapability: true,
			metricsUsageCapability:  true,
		},
		timeout: 15 * time.Minute,
	},
	{
		name:   "backup",
		socket: applierSocketDir + "/applier-backup.sock",
		hosted: map[string]bool{
			backupEncryptedArtifactsCapability: true,
		},
		// A backup or a restore of a large account takes a long time.
		timeout: 3 * time.Hour,
	},
}

// applierInstanceFor returns the instance that hosts capability.
func applierInstanceFor(capability string) (applierInstance, bool) {
	for _, instance := range applierInstances {
		if instance.hosted[capability] {
			return instance, true
		}
	}

	return applierInstance{}, false
}

// applierInstanceNamed returns the instance an applier process was started as.
func applierInstanceNamed(name string) (applierInstance, bool) {
	for _, instance := range applierInstances {
		if instance.name == name {
			return instance, true
		}
	}

	return applierInstance{}, false
}

// dispatchHosted applies op inside an applier, as root, for a capability that instance hosts. Each
// capability gets the production configuration it has when it runs as root, so it runs its tools
// directly rather than through sudo.
func dispatchHosted(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	var capability protocol.Capability

	switch op.Capability {
	case dnsBind9Capability:
		capability = bind9.New(bind9AppliedConfig())
	case webNginxCapability:
		capability = nginx.New(nginxAppliedConfig())
	case webPhpFpmCapability:
		capability = phpfpm.New(phpFpmAppliedConfig())
	case webApacheCapability:
		capability = apache.New(apacheAppliedConfig())
	case mailSmtpImapCapability:
		capability = mail.New(mailAppliedConfig())
	case databaseTenantCapability:
		// The tenant database administrator's credential is read here, as root: it is a root-only
		// file, and the unprivileged daemon never holds it.
		capability = mariadb.New(mariadbProductionConfig())
	case tlsAcmeCapability:
		capability = acme.New(acmeProductionConfig())
	case systemAccountIdentityCapability:
		capability = identity.New(identityAppliedConfig())
	case filesManagerCapability:
		capability = files.New(filesAppliedConfig())
	case schedulerCronCapability:
		capability = cron.New(cronAppliedConfig())
	case metricsUsageCapability:
		capability = metrics.New(metricsProductionConfig())
	case backupEncryptedArtifactsCapability:
		capability = backup.New(backupAppliedConfig())
	default:
		return protocol.ResultEnvelope{}, fmt.Errorf("capability %q is not hosted by the applier", op.Capability)
	}

	return capability.Apply(ctx, op)
}

// filesAppliedConfig is the file manager's configuration for the applier: requests are applied in
// this process, as the account (DropToAccount), where the sudo helper used to do the same.
func filesAppliedConfig() files.Config {
	cfg := filesManagerProductionConfig()
	cfg.SudoBinary = ""
	cfg.AgentBinaryPath = ""
	cfg.DropToAccount = true

	return cfg
}

// cronAppliedConfig is the cron configuration for the applier: the fragments, sidecars and account
// directories are written in this process. AgentBinaryPath stays: it is written into the crontab
// lines, which run the agent as the tenant's own user.
func cronAppliedConfig() cron.Config {
	cfg := cronProductionConfig()
	cfg.SudoBinary = ""

	return cfg
}

// backupAppliedConfig is the backup configuration for the applier: the databases are dumped and
// loaded, and the tenants' files, mailboxes and cron state read and written, by this process
// directly, because it already is root.
func backupAppliedConfig() backup.Config {
	cfg := backupProductionConfig()
	cfg.SudoBinary = ""
	cfg.AgentBinaryPath = ""

	return cfg
}

// identityAppliedConfig is the identity configuration for the applier: accounts are created and
// removed, the chroot trees made and the key files written in this process, and sshd is checked
// and reloaded directly, because the applier already is root.
func identityAppliedConfig() identity.Config {
	cfg := identityProductionConfig()
	cfg.SudoBinary = ""
	cfg.AgentBinaryPath = ""

	return cfg
}

// nginxAppliedConfig is the nginx configuration for the applier: the same paths, with no sudo and
// no helper binary, because the applier already is root. The configuration nginx tests and loads is
// rendered here from validated fields, so no path the daemon chooses is ever tested or loaded.
func nginxAppliedConfig() nginx.Config {
	cfg := nginxProductionConfig()
	cfg.SudoBinary = ""
	cfg.AgentBinaryPath = ""

	return cfg
}

// phpFpmAppliedConfig is the php-fpm configuration for the applier: no sudo, because the applier
// already is root. The pool files it writes are what the root php-fpm master loads, so they are
// rendered here from validated fields and never taken from the daemon.
func phpFpmAppliedConfig() phpfpm.Config {
	cfg := phpFpmProductionConfig()
	cfg.SudoBinary = ""

	return cfg
}

// apacheAppliedConfig is the apache configuration for the applier: the reload runs directly and
// the document roots are made in this process.
func apacheAppliedConfig() apache.Config {
	cfg := apacheProductionConfig()
	cfg.ReloadCommand = []string{"/usr/bin/systemctl", "reload", "apache2"}
	cfg.EnsureDocroot = func(username, resourceID string) error {
		return nginx.EnsureDocrootPrivileged(nginxAppliedConfig(), username, resourceID)
	}

	return cfg
}

// mailAppliedConfig is the mail configuration for the applier: reloads run directly, and what Exim
// and Dovecot must read but others must not (DKIM keys, the password file) goes to the group the
// mail services belong to, since a file written as root would otherwise be root's alone.
func mailAppliedConfig() mail.Config {
	cfg := mailProductionConfig()
	cfg.EximReloadCommand = []string{"/usr/bin/systemctl", "reload", "exim4"}
	cfg.DovecotReloadCommand = []string{"/usr/bin/systemctl", "reload", "dovecot"}
	cfg.SecretGroup = "lesta"

	return cfg
}

// bind9AppliedConfig is the bind9 configuration for the applier: the same paths, with no sudo,
// because the applier already is root.
func bind9AppliedConfig() bind9.Config {
	cfg := bind9ProductionConfig()
	cfg.SudoBinary = ""

	return cfg
}

// runApplier listens on the instance's socket and applies the operations the daemon sends until it
// is told to stop. It returns the process exit code.
func runApplier(instanceName string) int {
	instance, ok := applierInstanceNamed(instanceName)
	if !ok {
		fmt.Fprintf(os.Stderr, "lesta-agent applier: unknown instance %q\n", instanceName)

		return 1
	}

	if os.Geteuid() != 0 {
		fmt.Fprintln(os.Stderr, "lesta-agent applier: must run as root")

		return 1
	}

	agent, err := user.Lookup(applierUser)
	if err != nil {
		fmt.Fprintf(os.Stderr, "lesta-agent applier: looking up %s: %v\n", applierUser, err)

		return 1
	}

	uid, err := strconv.ParseUint(agent.Uid, 10, 32)
	if err != nil {
		fmt.Fprintf(os.Stderr, "lesta-agent applier: user id %q: %v\n", agent.Uid, err)

		return 1
	}

	// Files the capabilities create are world-readable like the ones the daemon creates, so a
	// capability can be moved between the two without a state file becoming unreadable.
	syscall.Umask(0o022)

	if err := os.Remove(instance.socket); err != nil && !errors.Is(err, os.ErrNotExist) {
		fmt.Fprintf(os.Stderr, "lesta-agent applier: removing the old socket: %v\n", err)

		return 1
	}

	listener, err := net.Listen("unix", instance.socket)
	if err != nil {
		fmt.Fprintf(os.Stderr, "lesta-agent applier: listening on %s: %v\n", instance.socket, err)

		return 1
	}

	// The socket is the daemon's alone; the peer credentials are checked on every connection too.
	if err := os.Chmod(instance.socket, 0o600); err != nil {
		fmt.Fprintf(os.Stderr, "lesta-agent applier: socket mode: %v\n", err)

		return 1
	}

	if err := os.Chown(instance.socket, int(uid), -1); err != nil {
		fmt.Fprintf(os.Stderr, "lesta-agent applier: socket owner: %v\n", err)

		return 1
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	server := &applier.Server{
		Handler:          dispatchHosted,
		Hosted:           instance.hosted,
		AllowedUIDs:      []uint32{uint32(uid)},
		OperationTimeout: instance.timeout,
		Logf: func(format string, args ...any) {
			fmt.Fprintf(os.Stderr, format+"\n", args...)
		},
	}

	if err := server.Serve(ctx, listener); err != nil {
		fmt.Fprintf(os.Stderr, "lesta-agent applier: %v\n", err)

		return 1
	}

	return 0
}
