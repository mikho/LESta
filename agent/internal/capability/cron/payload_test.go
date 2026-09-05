package cron_test

import (
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/cron"
)

func TestParsePayloadValidation(t *testing.T) {
	tests := []struct {
		name    string
		payload map[string]any
		wantErr bool
		field   string
	}{
		{
			name:    "every field wildcard is valid",
			payload: cronPayload("*", "*", "*", "*", "*", "echo hello", false),
			wantErr: false,
		},
		{
			name:    "numeric fields within range are valid",
			payload: cronPayload("0", "23", "1", "12", "0", "echo hello", false),
			wantErr: false,
		},
		{
			name:    "day of week accepts both 0 and 7 for Sunday",
			payload: cronPayload("0", "0", "*", "*", "7", "echo hello", false),
			wantErr: false,
		},
		{
			name:    "list, range, and step combinations are valid",
			payload: cronPayload("*/15", "1-5/2", "1,15", "1-6", "1-5", "echo hello", false),
			wantErr: false,
		},
		{
			name:    "minute out of range is rejected",
			payload: cronPayload("60", "*", "*", "*", "*", "echo hello", false),
			wantErr: true,
			field:   "minute",
		},
		{
			name:    "hour out of range is rejected",
			payload: cronPayload("*", "24", "*", "*", "*", "echo hello", false),
			wantErr: true,
			field:   "hour",
		},
		{
			name:    "day of month zero is rejected",
			payload: cronPayload("*", "*", "0", "*", "*", "echo hello", false),
			wantErr: true,
			field:   "day_of_month",
		},
		{
			name:    "month 13 is rejected",
			payload: cronPayload("*", "*", "*", "13", "*", "echo hello", false),
			wantErr: true,
			field:   "month",
		},
		{
			name:    "day of week 8 is rejected",
			payload: cronPayload("*", "*", "*", "*", "8", "echo hello", false),
			wantErr: true,
			field:   "day_of_week",
		},
		{
			name:    "empty schedule field is rejected",
			payload: cronPayload("", "*", "*", "*", "*", "echo hello", false),
			wantErr: true,
			field:   "minute",
		},
		{
			name:    "a malformed range is rejected",
			payload: cronPayload("5-", "*", "*", "*", "*", "echo hello", false),
			wantErr: true,
			field:   "minute",
		},
		{
			name:    "a reversed range is rejected",
			payload: cronPayload("30-10", "*", "*", "*", "*", "echo hello", false),
			wantErr: true,
			field:   "minute",
		},
		{
			name:    "empty command is rejected",
			payload: cronPayload("*", "*", "*", "*", "*", "   ", false),
			wantErr: true,
			field:   "command",
		},
		{
			name:    "a command containing a newline is rejected",
			payload: cronPayload("*", "*", "*", "*", "*", "echo hello\nrm -rf /", false),
			wantErr: true,
			field:   "command",
		},
		{
			name:    "a command containing a carriage return is rejected",
			payload: cronPayload("*", "*", "*", "*", "*", "echo hello\r\nrm -rf /", false),
			wantErr: true,
			field:   "command",
		},
		{
			name:    "a command starting with sudo is rejected",
			payload: cronPayload("*", "*", "*", "*", "*", "sudo rm -rf /", false),
			wantErr: true,
			field:   "command",
		},
		{
			name:    "a command starting with su is rejected",
			payload: cronPayload("*", "*", "*", "*", "*", "su root -c ls", false),
			wantErr: true,
			field:   "command",
		},
		{
			name:    "leading whitespace before sudo is still caught",
			payload: cronPayload("*", "*", "*", "*", "*", "   sudo rm -rf /", false),
			wantErr: true,
			field:   "command",
		},
		{
			name:    "a command merely mentioning sudo mid-string is allowed (non-exhaustive check)",
			payload: cronPayload("*", "*", "*", "*", "*", "echo 'do not sudo this'", false),
			wantErr: false,
		},
		{
			name:    "an empty run_as is rejected",
			payload: cronPayloadRunAs("*", "*", "*", "*", "*", "echo hello", false, ""),
			wantErr: true,
			field:   "run_as",
		},
		{
			name:    "a run_as starting with an uppercase letter is rejected",
			payload: cronPayloadRunAs("*", "*", "*", "*", "*", "echo hello", false, "Lesta-t42"),
			wantErr: true,
			field:   "run_as",
		},
		{
			name:    "a run_as containing a path separator is rejected",
			payload: cronPayloadRunAs("*", "*", "*", "*", "*", "echo hello", false, "../../etc"),
			wantErr: true,
			field:   "run_as",
		},
		{
			name:    "a well-formed run_as is accepted",
			payload: cronPayloadRunAs("*", "*", "*", "*", "*", "echo hello", false, "lesta-t42"),
			wantErr: false,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			raw := marshalPayload(t, tt.payload)

			_, err := cron.ParsePayload(raw)
			if tt.wantErr && err == nil {
				t.Fatalf("expected an error, got none")
			}
			if !tt.wantErr && err != nil {
				t.Fatalf("expected no error, got %v", err)
			}

			if tt.wantErr {
				var ve *cron.ValidationError
				if !asValidationError(err, &ve) {
					t.Fatalf("expected a *cron.ValidationError, got %T: %v", err, err)
				}
				if ve.Field != tt.field {
					t.Fatalf("expected the error field to be %q, got %q", tt.field, ve.Field)
				}
			}
		})
	}
}
