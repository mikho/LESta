package main

import (
	"context"
	"errors"
	"fmt"
	"log"
	"net"
	"os"
	"os/signal"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
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
	"github.com/mikho/LESta/agent/internal/capability/servicecontrol"
	"github.com/mikho/LESta/agent/internal/capability/serviceuninstall"
	"github.com/mikho/LESta/agent/internal/idempotency"
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
			serviceControlCapability: true,
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
	{
		// The one instance without a file-system sandbox: removing a service purges packages and
		// deletes its configuration. It hosts nothing else.
		name:   "maintenance",
		socket: applierSocketDir + "/applier-maintenance.sock",
		hosted: map[string]bool{
			serviceUninstallCapability: true,
		},
		timeout: 30 * time.Minute,
	},
}

// installerScript is the node's installer orchestrator: the .install directory recorded by the
// agent-daemon installer in /etc/lesta/installer-root (an install fetched to another --dest), or
// the default /opt/lesta/.install.
func installerScript() string {
	root := "/opt/lesta/.install"

	if raw, err := os.ReadFile("/etc/lesta/installer-root"); err == nil && strings.TrimSpace(string(raw)) != "" {
		root = strings.TrimSpace(string(raw))
	}

	return filepath.Join(root, "scripts", "install-selected.sh")
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

// hostedResourceIDPattern is the only resource_id an applier accepts: a lower-case UUID, which is
// what the panel and every self-test send. Capabilities build paths and names from it, so it is
// checked once here for all of them, before any capability sees the operation.
var hostedResourceIDPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

var (
	hostedMu sync.Mutex
	// hostedInstances keeps one instance per capability for the life of the applier, so its
	// idempotency receipts survive between operations: a redelivered operation (its result was
	// lost on the way back) is answered from the receipt instead of being applied again.
	hostedInstances = map[string]protocol.Capability{}
	// hostedLocks applies one operation per capability at a time: two operations rendering,
	// activating and reloading the same server at once would undo each other.
	hostedLocks = map[string]*sync.Mutex{}
)

// dispatchHosted applies op inside an applier, as root, for a capability that instance hosts. Each
// capability gets the production configuration it has when it runs as root, so it runs its tools
// directly rather than through sudo. The file manager is not serialized: a person waits on it, and
// each of its operations is contained on its own.
func dispatchHosted(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	if !hostedResourceIDPattern.MatchString(op.ResourceID) {
		return protocol.ResultEnvelope{}, fmt.Errorf("resource_id %q is not a lower-case UUID", op.ResourceID)
	}

	hostedMu.Lock()
	capability, ok := hostedInstances[op.Capability]
	if !ok {
		var err error
		if capability, err = newHostedCapability(op.Capability); err != nil {
			hostedMu.Unlock()

			return protocol.ResultEnvelope{}, err
		}
		hostedInstances[op.Capability] = capability
		hostedLocks[op.Capability] = &sync.Mutex{}
	}
	lock := hostedLocks[op.Capability]
	hostedMu.Unlock()

	if op.Capability != filesManagerCapability {
		lock.Lock()
		defer lock.Unlock()
	}

	// Receipts on disk answer an operation redelivered after the applier
	// restarted, which the capability's in-memory receipts no longer know. Only
	// an applied operation keeps one: a failed one is tried again. Observe
	// changes nothing and the file manager's operations are a person's own
	// clicks, so neither keeps one.
	remember := op.Operation != protocol.OperationObserve && op.Capability != filesManagerCapability
	receipts := idempotency.NewDir(filepath.Join(appliedReceiptsRoot, op.Capability), appliedReceiptMaxAge)

	if remember {
		if prior, ok := receipts.Lookup(op.IdempotencyKey); ok && prior.Capability == op.Capability && prior.ResourceID == op.ResourceID {
			if prior.Status == protocol.StatusApplied || prior.Status == protocol.StatusAlreadyApplied {
				prior.Status = protocol.StatusAlreadyApplied
			}

			return prior, nil
		}
	}

	result, err := capability.Apply(ctx, op)
	if err == nil && remember && (result.Status == protocol.StatusApplied || result.Status == protocol.StatusAlreadyApplied) {
		if recordErr := receipts.Record(op.IdempotencyKey, result); recordErr != nil {
			log.Printf("applier: keeping the receipt of %s %s: %v", op.Capability, op.IdempotencyKey, recordErr)
		}
	}

	return result, err
}

// appliedReceiptsRoot holds the applier's receipts on disk, one folder per
// capability (see dispatchHosted); a variable only so tests can move it.
var appliedReceiptsRoot = "/var/lib/lesta/agent/applier-receipts"

// appliedReceiptMaxAge is how long a receipt is kept: far longer than the
// panel ever redelivers an operation.
const appliedReceiptMaxAge = 30 * 24 * time.Hour

// newHostedCapability builds a capability with the configuration it has as root.
func newHostedCapability(name string) (protocol.Capability, error) {
	switch name {
	case dnsBind9Capability:
		return bind9.New(bind9AppliedConfig()), nil
	case webNginxCapability:
		return nginx.New(nginxAppliedConfig()), nil
	case webPhpFpmCapability:
		return phpfpm.New(phpFpmAppliedConfig()), nil
	case webApacheCapability:
		return apache.New(apacheAppliedConfig()), nil
	case mailSmtpImapCapability:
		return mail.New(mailAppliedConfig()), nil
	case databaseTenantCapability:
		// The tenant database administrator's credential is read here, as root: it is a root-only
		// file, and the unprivileged daemon never holds it.
		return mariadb.New(mariadbProductionConfig()), nil
	case tlsAcmeCapability:
		return acme.New(acmeProductionConfig()), nil
	case serviceUninstallCapability:
		return serviceuninstall.New(serviceuninstall.Config{Installer: installerScript()}), nil
	case serviceControlCapability:
		return servicecontrol.New(servicecontrol.Config{AllowedUnits: servicecontrol.DefaultAllowedUnits}), nil
	case systemAccountIdentityCapability:
		return identity.New(identityAppliedConfig()), nil
	case filesManagerCapability:
		return files.New(filesAppliedConfig()), nil
	case schedulerCronCapability:
		return cron.New(cronAppliedConfig()), nil
	case metricsUsageCapability:
		return metrics.New(metricsProductionConfig()), nil
	case backupEncryptedArtifactsCapability:
		return backup.New(backupAppliedConfig()), nil
	default:
		return nil, fmt.Errorf("capability %q is not hosted by the applier", name)
	}
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
// and Dovecot must read but others must not (DKIM keys, the password file) goes to lesta-mail, the
// group only the mail services belong to (the mail installer's MAIL_SECRET_GROUP), since a file
// written as root would otherwise be root's alone.
func mailAppliedConfig() mail.Config {
	cfg := mailProductionConfig()
	cfg.EximReloadCommand = []string{"/usr/bin/systemctl", "reload", "exim4"}
	cfg.DovecotReloadCommand = []string{"/usr/bin/systemctl", "reload", "dovecot"}
	cfg.SecretGroup = "lesta-mail"

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
