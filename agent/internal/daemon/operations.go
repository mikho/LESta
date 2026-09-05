package daemon

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// operationResultsRequest is the JSON body POSTed to
// <ControlPlaneURL>/agent/v1/operation-results.
type operationResultsRequest struct {
	Results []protocol.ResultEnvelope `json:"results"`
}

// reportOperationResults applies each of pending's own OperationEnvelope entries via
// cfg.Dispatch, then POSTs the whole batch of resulting ResultEnvelopes to the control
// plane in one request. cfg.Dispatch returning an error (a capability itself reporting a
// failed status is an entirely different, expected outcome, carried in the
// ResultEnvelope's own Status/Errors, never as a Go error here) never crashes or drops the
// operation: a synthetic failed ResultEnvelope is built from the envelope's own identifying
// fields instead, so the batch always carries one result per pending operation. A non-2xx
// response from the POST is returned as an error and not retried within this cycle; the
// operation stays Dispatched server-side and is naturally redelivered on the next
// heartbeat.
func reportOperationResults(client *http.Client, cfg Config, credential string, pending []protocol.OperationEnvelope) error {
	results := make([]protocol.ResultEnvelope, 0, len(pending))

	for _, envelope := range pending {
		result, err := cfg.Dispatch(context.Background(), envelope)
		if err != nil {
			result = syntheticFailedResult(envelope, err)
		}

		results = append(results, result)
	}

	return postOperationResults(client, cfg, credential, results)
}

// syntheticFailedResult builds a failed ResultEnvelope from an OperationEnvelope's own
// identifying fields when cfg.Dispatch itself returns an error (no verdict was reached at
// all), so the daemon always reports exactly one result per pending operation, never
// silently dropping one.
func syntheticFailedResult(envelope protocol.OperationEnvelope, dispatchErr error) protocol.ResultEnvelope {
	return protocol.ResultEnvelope{
		ProtocolVersion:      envelope.ProtocolVersion,
		Capability:           envelope.Capability,
		ResourceID:           envelope.ResourceID,
		IdempotencyKey:       envelope.IdempotencyKey,
		CorrelationID:        envelope.CorrelationID,
		Status:               protocol.StatusFailed,
		ObservedStateVersion: 0,
		ObservedStateDigest:  "sha256:" + strings.Repeat("0", 64),
		GenerationID:         "none",
		Errors: []protocol.ResultError{
			{Code: "dispatch_failed", Message: dispatchErr.Error()},
		},
		CompletedAt: time.Now().UTC(),
	}
}

// postOperationResults POSTs one batch to the control plane. A non-2xx response or network
// error is returned as an error.
func postOperationResults(client *http.Client, cfg Config, credential string, results []protocol.ResultEnvelope) error {
	body, err := json.Marshal(operationResultsRequest{Results: results})
	if err != nil {
		return fmt.Errorf("marshaling operation-results request: %w", err)
	}

	httpReq, err := http.NewRequest(http.MethodPost, cfg.ControlPlaneURL+"/agent/v1/operation-results", bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("building operation-results request: %w", err)
	}

	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("Authorization", "Bearer "+credential)

	resp, err := client.Do(httpReq)
	if err != nil {
		return fmt.Errorf("sending operation-results request: %w", err)
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("operation-results request returned status %d: %s", resp.StatusCode, string(respBody))
	}

	return nil
}
