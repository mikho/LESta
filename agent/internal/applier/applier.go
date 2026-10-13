// Package applier is the seam between the unprivileged daemon and the root process that applies
// node configuration. The daemon talks to the control plane and decides nothing about the machine;
// the applier, running as root, is the only process that renders configuration, writes the
// directories root services load, validates it and reloads those services.
//
// The two sides speak over a unix socket. Remote implements protocol.Capability by forwarding an
// OperationEnvelope; Server hosts real capabilities behind the same interface. Because the
// interface is the existing one, a capability moves behind the applier without changing, and the
// shared contract suite runs against Remote unchanged.
//
// The server trusts nothing it is sent beyond what the capabilities' own payload validators allow:
// it only serves the capabilities it hosts, only to the users it was told to accept (checked
// from the socket's kernel-reported peer credentials, not anything in the message), and bounds
// the size and time of every request.
package applier

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"sync"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	// MaxMessageBytes bounds one request or response. A file read through the file manager can
	// come back as 25 MB of base64-encoded content, so the bound is well above that; the only peers
	// are root and the daemon's own user.
	MaxMessageBytes = 48 << 20
	// DefaultOperationTimeout bounds one operation on the server when the client sets no earlier deadline.
	DefaultOperationTimeout = 10 * time.Minute
	// idleReadTimeout bounds how long the server waits for a client to send its request.
	idleReadTimeout = 30 * time.Second
)

// request and response are the two messages of the protocol: one of each per connection.
type request struct {
	Operation protocol.OperationEnvelope `json:"operation"`
}

type response struct {
	Result *protocol.ResultEnvelope `json:"result,omitempty"`
	Error  string                   `json:"error,omitempty"`
}

// writeFrame writes v as a 4-byte big-endian length and its JSON.
func writeFrame(w io.Writer, v any) error {
	body, err := json.Marshal(v)
	if err != nil {
		return err
	}

	if len(body) > MaxMessageBytes {
		return fmt.Errorf("message of %d bytes exceeds the %d byte limit", len(body), MaxMessageBytes)
	}

	header := make([]byte, 4)
	binary.BigEndian.PutUint32(header, uint32(len(body)))

	if _, err := w.Write(append(header, body...)); err != nil {
		return err
	}

	return nil
}

// readFrame reads one frame into v, refusing anything larger than MaxMessageBytes before reading it.
func readFrame(r io.Reader, v any) error {
	header := make([]byte, 4)
	if _, err := io.ReadFull(r, header); err != nil {
		return err
	}

	size := binary.BigEndian.Uint32(header)
	if size == 0 || size > MaxMessageBytes {
		return fmt.Errorf("message size %d is outside 1..%d", size, MaxMessageBytes)
	}

	body := make([]byte, size)
	if _, err := io.ReadFull(r, body); err != nil {
		return err
	}

	dec := json.NewDecoder(bytesReader(body))
	dec.DisallowUnknownFields()

	return dec.Decode(v)
}

// Handler applies one operation. The server calls it only for capabilities it hosts.
type Handler func(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error)

// Server serves operations from a listener to the allowed users.
type Server struct {
	// Handler applies an operation for a hosted capability.
	Handler Handler
	// Hosted is the set of capability names this server will apply; anything else is refused
	// before Handler is reached.
	Hosted map[string]bool
	// AllowedUIDs are the users whose connections are served, by the kernel-reported peer
	// credentials of the socket. Root is always served. A nil AllowedUIDs on a platform without
	// peer credentials serves nobody except through TrustAnyPeer (tests).
	AllowedUIDs []uint32
	// TrustAnyPeer skips the peer check. Tests only.
	TrustAnyPeer bool
	// OperationTimeout bounds one operation; zero means DefaultOperationTimeout.
	OperationTimeout time.Duration
	// Logf receives one line per refused or failed connection; nil discards.
	Logf func(format string, args ...any)
}

func (s *Server) logf(format string, args ...any) {
	if s.Logf != nil {
		s.Logf(format, args...)
	}
}

// Serve accepts connections until ctx is cancelled or the listener fails. Each connection carries
// one request and is closed after its response.
func (s *Server) Serve(ctx context.Context, l net.Listener) error {
	go func() {
		<-ctx.Done()
		_ = l.Close()
	}()

	var wg sync.WaitGroup

	defer wg.Wait()

	for {
		conn, err := l.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}

			return err
		}

		wg.Add(1)

		go func() {
			defer wg.Done()

			s.serveConn(ctx, conn)
		}()
	}
}

func (s *Server) serveConn(ctx context.Context, conn net.Conn) {
	defer func() { _ = conn.Close() }()

	if !s.TrustAnyPeer {
		if err := s.checkPeer(conn); err != nil {
			s.logf("applier: refused a connection: %v", err)

			return
		}
	}

	_ = conn.SetReadDeadline(time.Now().Add(idleReadTimeout))

	var req request
	if err := readFrame(conn, &req); err != nil {
		s.logf("applier: unreadable request: %v", err)
		_ = writeFrame(conn, response{Error: "the request could not be read"})

		return
	}

	_ = conn.SetReadDeadline(time.Time{})

	if !s.Hosted[req.Operation.Capability] {
		s.logf("applier: refused capability %q", req.Operation.Capability)
		_ = writeFrame(conn, response{Error: fmt.Sprintf("capability %q is not applied by the applier", req.Operation.Capability)})

		return
	}

	timeout := s.OperationTimeout
	if timeout <= 0 {
		timeout = DefaultOperationTimeout
	}

	opCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	result, err := s.safeHandle(opCtx, req.Operation)
	if err != nil {
		s.logf("applier: %s %s failed: %v", req.Operation.Capability, req.Operation.Operation, err)
		_ = writeFrame(conn, response{Error: err.Error()})

		return
	}

	if err := writeFrame(conn, response{Result: &result}); err != nil {
		s.logf("applier: writing the result failed: %v", err)
	}
}

// safeHandle turns a panic in a capability into an error instead of ending the applier.
func (s *Server) safeHandle(ctx context.Context, op protocol.OperationEnvelope) (result protocol.ResultEnvelope, err error) {
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("the capability panicked: %v", r)
		}
	}()

	return s.Handler(ctx, op)
}

// Remote is a protocol.Capability that applies operations through an applier's socket.
type Remote struct {
	// SocketPath is the applier's unix socket.
	SocketPath string
	// Timeout bounds a call whose context has no deadline of its own; zero means
	// DefaultOperationTimeout plus a minute. It must be longer than the server's.
	Timeout time.Duration
}

// Apply sends op to the applier and returns its result. An error means no verdict was reached: the
// applier could not be reached, refused the request, or failed without producing a result.
func (r Remote) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	var dialer net.Dialer

	conn, err := dialer.DialContext(ctx, "unix", r.SocketPath)
	if err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("the applier could not be reached: %w", err)
	}

	defer func() { _ = conn.Close() }()

	deadline, ok := ctx.Deadline()
	if !ok {
		timeout := r.Timeout
		if timeout <= 0 {
			timeout = DefaultOperationTimeout + time.Minute
		}

		deadline = time.Now().Add(timeout)
	}

	_ = conn.SetDeadline(deadline)

	// Closing the connection is how a cancelled context stops a read in flight.
	stop := context.AfterFunc(ctx, func() { _ = conn.Close() })
	defer stop()

	if err := writeFrame(conn, request{Operation: op}); err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("sending the operation to the applier: %w", err)
	}

	var resp response
	if err := readFrame(conn, &resp); err != nil {
		if ctx.Err() != nil {
			return protocol.ResultEnvelope{}, ctx.Err()
		}

		return protocol.ResultEnvelope{}, fmt.Errorf("reading the applier's answer: %w", err)
	}

	if resp.Error != "" {
		return protocol.ResultEnvelope{}, errors.New(resp.Error)
	}

	if resp.Result == nil {
		return protocol.ResultEnvelope{}, errors.New("the applier answered with neither a result nor an error")
	}

	return *resp.Result, nil
}
