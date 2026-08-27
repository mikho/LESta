package protocol

import "context"

// Capability is implemented by every node capability, fake or real.
//
// Expected, well-formed failure modes (validation rejections, nginx -t failures,
// reload failures, health-check failures, drift) are always expressed through the
// returned ResultEnvelope's Status/Errors, never as a Go error. A non-nil error
// means "no verdict was reached" (malformed input, a cancelled context, an
// unwritable scratch dir), not a fourth kind of business outcome.
type Capability interface {
	Apply(ctx context.Context, op OperationEnvelope) (ResultEnvelope, error)
}
