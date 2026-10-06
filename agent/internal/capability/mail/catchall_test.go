package mail

import (
	"context"
	"strings"
	"testing"
)

func TestRender_CatchallOnlyForActiveMailboxOnSameDomain(t *testing.T) {
	cases := []struct {
		name      string
		catchall  string
		suspended bool
		want      bool
	}{
		{"active mailbox on the domain", "Catch@example.com", false, true},
		{"external address", "someone@gmail.com", false, false},
		{"unknown local part", "ghost@example.com", false, false},
		{"suspended mailbox", "catch@example.com", true, false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			catchall := tc.catchall
			payload := Payload{
				Domain:        "example.com",
				CatchallEmail: &catchall,
				Accounts:      []Account{{LocalPart: "catch", Suspended: tc.suspended}},
			}
			credentials := map[string]string{"catch@example.com": "hash"}

			data, err := New(Config{}).render(context.Background(), []Payload{payload}, credentials)
			if err != nil {
				t.Fatalf("render: %v", err)
			}

			if got := strings.Contains(data.catchall, "example.com"); got != tc.want {
				t.Fatalf("catchall.list = %q, want present=%v", data.catchall, tc.want)
			}
		})
	}
}
