// Package generation is a service-agnostic generation store: stage, activate,
// roll back, and prune numbered generations of a resource's rendered
// configuration, on top of `current`/`previous` symlinks and a manifest.json per
// generation. It is parameterized entirely by root paths and knows nothing about
// nginx, or any other specific service; mirrors how RecordsProvisioningOperation
// is provisionable-agnostic on the Laravel side.
//
// Generations are scoped per resource, not per node: operations address one
// resource at a time with its own desired_state_version, so a single node-wide
// counter would conflate unrelated resources' histories. Each resource gets its
// own generation sequence and its own current/previous pointers, all nested
// under this Store's Root.
package generation

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"time"
)

const (
	currentLinkName  = "current"
	previousLinkName = "previous"
	generationsDir   = "generations"
	contentFileName  = "resource.conf"
	manifestFileName = "manifest.json"

	// DefaultKeep is the number of most-recent generations retained per
	// resource once pruning runs; a reasonable, tunable bound the ADR itself
	// doesn't number.
	DefaultKeep = 5
)

// Manifest is the metadata persisted alongside each generation's rendered
// content. Digest is the whole-owned-root digest (see digest.go) observed
// immediately after this generation was activated, used by observe to detect
// drift later.
type Manifest struct {
	ResourceID          string    `json:"resource_id"`
	Generation          int       `json:"generation"`
	Digest              string    `json:"digest"`
	DesiredStateVersion int       `json:"desired_state_version"`
	Deleted             bool      `json:"deleted"`
	CreatedAt           time.Time `json:"created_at"`
}

// Store manages generation history under Root, one subdirectory per resource ID.
type Store struct {
	// Root is the state directory generations nest under, e.g.
	// /var/lib/lesta/nginx/domains. Each resource gets Root/<resourceID>/.
	Root string
	// Keep is how many of the most recent generations are retained per
	// resource; older ones are pruned after each Activate. Zero means
	// DefaultKeep.
	Keep int
}

// New returns a Store rooted at root, retaining DefaultKeep generations per
// resource.
func New(root string) *Store {
	return &Store{Root: root, Keep: DefaultKeep}
}

func (s *Store) keep() int {
	if s.Keep <= 0 {
		return DefaultKeep
	}

	return s.Keep
}

func (s *Store) resourceDir(resourceID string) string {
	return filepath.Join(s.Root, resourceID)
}

func (s *Store) generationsDir(resourceID string) string {
	return filepath.Join(s.resourceDir(resourceID), generationsDir)
}

func (s *Store) generationDir(resourceID string, n int) string {
	return filepath.Join(s.generationsDir(resourceID), strconv.Itoa(n))
}

// GenerationDir exposes the on-disk directory for resourceID's generation n, so
// a specific capability (nginx, etc.) can stash its own service-specific sidecar
// metadata alongside the generic content/manifest files this package writes,
// without this service-agnostic package needing to know that sidecar's shape.
func (s *Store) GenerationDir(resourceID string, n int) string {
	return s.generationDir(resourceID, n)
}

// NextGeneration returns the generation number a fresh apply attempt should use:
// one past the highest generation number ever created for resourceID (whether or
// not that generation is still retained after pruning), or 1 if none exists yet.
// A generation number is only "spent" once Activate is actually called for it;
// a rejected attempt (which never calls Activate) reuses the same number on
// retry, since nothing was ever written under it.
func (s *Store) NextGeneration(resourceID string) (int, error) {
	entries, err := os.ReadDir(s.generationsDir(resourceID))
	if errors.Is(err, os.ErrNotExist) {
		return 1, nil
	}
	if err != nil {
		return 0, fmt.Errorf("reading generations directory for %s: %w", resourceID, err)
	}

	max := 0

	for _, entry := range entries {
		n, err := strconv.Atoi(entry.Name())
		if err != nil {
			continue
		}
		if n > max {
			max = n
		}
	}

	return max + 1, nil
}

// readPointer resolves a current/previous symlink to the generation number it
// points at. ok is false if the symlink doesn't exist.
func (s *Store) readPointer(resourceID, linkName string) (n int, ok bool, err error) {
	linkPath := filepath.Join(s.resourceDir(resourceID), linkName)

	target, err := os.Readlink(linkPath)
	if errors.Is(err, os.ErrNotExist) {
		return 0, false, nil
	}
	if err != nil {
		return 0, false, fmt.Errorf("reading %s pointer for %s: %w", linkName, resourceID, err)
	}

	n, convErr := strconv.Atoi(filepath.Base(target))
	if convErr != nil {
		return 0, false, fmt.Errorf("%s pointer for %s has unexpected target %q: %w", linkName, resourceID, target, convErr)
	}

	return n, true, nil
}

// HasCurrent reports whether resourceID has any activated generation at all.
func (s *Store) HasCurrent(resourceID string) (bool, error) {
	_, ok, err := s.readPointer(resourceID, currentLinkName)

	return ok, err
}

// CurrentGeneration returns the generation number resourceID's current pointer
// targets, if any.
func (s *Store) CurrentGeneration(resourceID string) (n int, ok bool, err error) {
	return s.readPointer(resourceID, currentLinkName)
}

// PreviousGeneration returns the generation number resourceID's previous pointer
// targets, if any.
func (s *Store) PreviousGeneration(resourceID string) (n int, ok bool, err error) {
	return s.readPointer(resourceID, previousLinkName)
}

// ReadManifest loads generation n's persisted manifest for resourceID.
func (s *Store) ReadManifest(resourceID string, n int) (Manifest, error) {
	raw, err := os.ReadFile(filepath.Join(s.generationDir(resourceID, n), manifestFileName))
	if err != nil {
		return Manifest{}, fmt.Errorf("reading manifest for %s generation %d: %w", resourceID, n, err)
	}

	var manifest Manifest
	if err := json.Unmarshal(raw, &manifest); err != nil {
		return Manifest{}, fmt.Errorf("parsing manifest for %s generation %d: %w", resourceID, n, err)
	}

	return manifest, nil
}

// ReadContent loads generation n's stored rendered content for resourceID. If
// that generation represents a deletion (no live fragment), deleted is true and
// content is nil.
func (s *Store) ReadContent(resourceID string, n int) (content []byte, deleted bool, err error) {
	manifest, err := s.ReadManifest(resourceID, n)
	if err != nil {
		return nil, false, err
	}

	if manifest.Deleted {
		return nil, true, nil
	}

	content, err = os.ReadFile(filepath.Join(s.generationDir(resourceID, n), contentFileName))
	if err != nil {
		return nil, false, fmt.Errorf("reading content for %s generation %d: %w", resourceID, n, err)
	}

	return content, false, nil
}

// Activate persists generation n's content (or, if deleted is true, records that
// this generation has no live fragment at all) and manifest, then atomically
// swaps the previous/current pointers so previous now targets whatever current
// pointed at before this call, and current now targets n. It then prunes
// generations older than the last Keep retained.
//
// Rollback is not a special code path: a caller recovering from a failed
// update/suspend/unsuspend/delete calls Activate again with the previous
// generation's own stored content, which re-runs it through the exact same
// stage-swap-prune sequence, landing it in a brand new generation number.
func (s *Store) Activate(resourceID string, n int, content []byte, deleted bool, digest string, desiredStateVersion int) error {
	genDir := s.generationDir(resourceID, n)
	if err := os.MkdirAll(genDir, 0o755); err != nil {
		return fmt.Errorf("creating generation directory for %s generation %d: %w", resourceID, n, err)
	}

	if !deleted {
		if err := os.WriteFile(filepath.Join(genDir, contentFileName), content, 0o644); err != nil {
			return fmt.Errorf("writing content for %s generation %d: %w", resourceID, n, err)
		}
	}

	manifest := Manifest{
		ResourceID:          resourceID,
		Generation:          n,
		Digest:              digest,
		DesiredStateVersion: desiredStateVersion,
		Deleted:             deleted,
		CreatedAt:           time.Now().UTC(),
	}

	manifestBytes, err := json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		return fmt.Errorf("encoding manifest for %s generation %d: %w", resourceID, n, err)
	}

	if err := os.WriteFile(filepath.Join(genDir, manifestFileName), manifestBytes, 0o644); err != nil {
		return fmt.Errorf("writing manifest for %s generation %d: %w", resourceID, n, err)
	}

	oldCurrent, hadCurrent, err := s.readPointer(resourceID, currentLinkName)
	if err != nil {
		return err
	}

	if hadCurrent {
		if err := s.setPointer(resourceID, previousLinkName, oldCurrent); err != nil {
			return err
		}
	}

	if err := s.setPointer(resourceID, currentLinkName, n); err != nil {
		return err
	}

	return s.prune(resourceID)
}

func (s *Store) setPointer(resourceID, linkName string, n int) error {
	linkPath := filepath.Join(s.resourceDir(resourceID), linkName)

	if err := os.RemoveAll(linkPath); err != nil {
		return fmt.Errorf("clearing %s pointer for %s: %w", linkName, resourceID, err)
	}

	target := filepath.Join(generationsDir, strconv.Itoa(n))
	if err := os.Symlink(target, linkPath); err != nil {
		return fmt.Errorf("setting %s pointer for %s to generation %d: %w", linkName, resourceID, n, err)
	}

	return nil
}

// prune removes generation directories older than the last Keep retained for
// resourceID. current and previous are always within the retained window as
// long as Keep is at least 2.
func (s *Store) prune(resourceID string) error {
	entries, err := os.ReadDir(s.generationsDir(resourceID))
	if err != nil {
		return fmt.Errorf("reading generations directory for %s: %w", resourceID, err)
	}

	var numbers []int

	for _, entry := range entries {
		n, err := strconv.Atoi(entry.Name())
		if err != nil {
			continue
		}
		numbers = append(numbers, n)
	}

	sort.Sort(sort.Reverse(sort.IntSlice(numbers)))

	if len(numbers) <= s.keep() {
		return nil
	}

	for _, n := range numbers[s.keep():] {
		if err := os.RemoveAll(s.generationDir(resourceID, n)); err != nil {
			return fmt.Errorf("pruning %s generation %d: %w", resourceID, n, err)
		}
	}

	return nil
}
