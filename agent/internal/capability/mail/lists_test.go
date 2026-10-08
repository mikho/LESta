package mail

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

func listPayload(lists string) json.RawMessage {
	return json.RawMessage(`{"domain":"example.com","antivirus_enabled":true,"antispam_enabled":true,"dkim_enabled":false,"dkim_active_selector":"lesta1","dkim_pending_selector":null,"dkim_retire_selector":null,"catchall_email":null,"accounts":[{"local_part":"alice","quota_mb":null,"forward_to":null,"forward_only":false,"autoreply_enabled":false,"autoreply_message":null,"suspended":false}],"suspended":false,"lists":` + lists + `}`)
}

func TestRender_ListsPostersPrefixAndOwner(t *testing.T) {
	payload, err := ParsePayload(listPayload(`[
		{"local_part":"news","owner_email":"boss@corp.example","post_policy":"members","subject_prefix":"[News]","reply_to_list":true,"members":["a@x.example","b@y.example","boss@corp.example"]},
		{"local_part":"open","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":["a@x.example"]},
		{"local_part":"board","owner_email":"chair@corp.example","post_policy":"owner","subject_prefix":"","reply_to_list":false,"members":[]}
	]`))
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	data, err := New(Config{}).render(context.Background(), []Payload{payload}, map[string]string{"alice@example.com": "hash"})
	if err != nil {
		t.Fatalf("render: %v", err)
	}

	for file, want := range map[string]struct {
		got  string
		must []string
	}{
		"lists":   {data.lists, []string{"news@example.com: a@x.example, b@y.example", "open@example.com: a@x.example", "board@example.com: :fail: this mailing list has no members yet"}},
		"owners":  {data.listOwners, []string{"news-owner@example.com: boss@corp.example", "board-owner@example.com: chair@corp.example"}},
		"posters": {data.listPosters, []string{"news@example.com: boss@corp.example:a@x.example:b@y.example", "board@example.com: chair@corp.example"}},
		"prefix":  {data.listPrefixes, []string{"news@example.com: [News]"}},
		"replyto": {data.listReplyTo, []string{"news@example.com: news@example.com"}},
	} {
		for _, line := range want.must {
			if !strings.Contains(want.got, line) {
				t.Errorf("%s file is missing %q:\n%s", file, line, want.got)
			}
		}
	}

	if strings.Contains(data.listPosters, "boss@corp.example:boss@corp.example") {
		t.Errorf("an owner who is also a member must be listed once:\n%s", data.listPosters)
	}

	if strings.Contains(data.listPosters, "open@example.com") {
		t.Errorf("a list open to anyone must have no poster restriction:\n%s", data.listPosters)
	}
}

func TestRender_SuspendedDomainRendersNoLists(t *testing.T) {
	payload, err := ParsePayload(listPayload(`[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":["a@x.example"]}]`))
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	payload.Suspended = true

	data, err := New(Config{}).render(context.Background(), []Payload{payload}, map[string]string{"alice@example.com": "hash"})
	if err != nil {
		t.Fatalf("render: %v", err)
	}

	if data.lists != "" || data.listOwners != "" {
		t.Errorf("a suspended domain must render no lists, got %q %q", data.lists, data.listOwners)
	}
}

func TestParsePayload_RejectsUnsafeLists(t *testing.T) {
	good := `{"local_part":"news","owner_email":"boss@corp.example","post_policy":"members","subject_prefix":"[News]","reply_to_list":false,"members":["a@x.example"]}`

	for name, lists := range map[string]string{
		"name taken by a mailbox":       `[{"local_part":"alice","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":["a@x.example"]}]`,
		"reserved owner suffix":         `[{"local_part":"news-owner","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":[]}]`,
		"owner address of another list": `[` + good + `,{"local_part":"x","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":[]},{"local_part":"x","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":[]}]`,
		"member with a comma":           `[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":["a@x.example,evil@y.example"]}]`,
		"member that is a pipe":         `[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":["|/bin/sh@x.example"]}]`,
		"member that is a fail":         `[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":[":fail:@x.example"]}]`,
		"member with a colon":           `[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":["a:b@x.example"]}]`,
		"duplicate member":              `[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":["a@x.example","a@x.example"]}]`,
		"unknown policy":                `[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"everyone","subject_prefix":"","reply_to_list":false,"members":[]}]`,
		"expansion in the prefix":       `[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"${run{x}}","reply_to_list":false,"members":[]}]`,
		"newline in the prefix":         `[{"local_part":"news","owner_email":"boss@corp.example","post_policy":"anyone","subject_prefix":"a\nBcc: x@y.example","reply_to_list":false,"members":[]}]`,
		"bad owner":                     `[{"local_part":"news","owner_email":"not an email","post_policy":"anyone","subject_prefix":"","reply_to_list":false,"members":[]}]`,
	} {
		if _, err := ParsePayload(listPayload(lists)); err == nil {
			t.Errorf("%s: expected a rejection", name)
		}
	}

	if _, err := ParsePayload(listPayload(`[` + good + `]`)); err != nil {
		t.Errorf("a valid list was rejected: %v", err)
	}
}
