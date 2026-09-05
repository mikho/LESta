package daemon

import (
	"math/rand"
	"time"
)

const (
	// backoffInitial is the first retry delay after a failed cycle.
	backoffInitial = 5 * time.Second
	// backoffMax caps how long a single retry delay can grow to, however
	// many consecutive failures precede it.
	backoffMax = 5 * time.Minute
	// backoffMultiplier doubles the delay on each consecutive failure,
	// standard exponential backoff.
	backoffMultiplier = 2
)

// backoff tracks an exponentially growing retry delay, reset to zero (so
// the next failure starts again from backoffInitial) on any success.
type backoff struct {
	current time.Duration
}

func newBackoff() *backoff {
	return &backoff{}
}

// next returns the delay to sleep after a failed cycle, growing the
// internal state for the following call.
func (b *backoff) next() time.Duration {
	if b.current == 0 {
		b.current = backoffInitial
	} else {
		b.current *= backoffMultiplier
		if b.current > backoffMax {
			b.current = backoffMax
		}
	}

	return b.current
}

// reset clears the backoff state after a successful cycle.
func (b *backoff) reset() {
	b.current = 0
}

// jitter returns d adjusted by a random +/-10%, so many nodes heartbeating
// on the same nominal interval do not all retry in lockstep.
func jitter(d time.Duration) time.Duration {
	if d <= 0 {
		return d
	}

	spread := float64(d) * 0.10
	offset := (rand.Float64()*2 - 1) * spread

	return d + time.Duration(offset)
}
