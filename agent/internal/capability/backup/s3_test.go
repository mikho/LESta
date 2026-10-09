package backup

import (
	"net"
	"net/http"
	"strings"
	"testing"
	"time"
)

// The two examples AWS publishes for Signature Version 4 on S3 (with their
// example credentials): the signatures below are AWS's own, so a match proves the
// canonical request, the key derivation and the encoding.
const (
	awsExampleAccess = "AKIAIOSFODNN7EXAMPLE"
	awsExampleSecret = "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
	emptyHash        = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
)

func TestSignV4MatchesTheAwsGetObjectExample(t *testing.T) {
	req, _ := http.NewRequest(http.MethodGet, "https://examplebucket.s3.amazonaws.com/test.txt", nil)
	req.Header.Set("Range", "bytes=0-9")
	req.Header.Set("x-amz-content-sha256", emptyHash)
	req.Header.Set("x-amz-date", "20130524T000000Z")

	signV4(req, []string{"host", "range", "x-amz-content-sha256", "x-amz-date"}, emptyHash, awsExampleAccess, awsExampleSecret, "us-east-1", "s3", time.Date(2013, 5, 24, 0, 0, 0, 0, time.UTC))

	want := "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41"

	if got := strings.ReplaceAll(req.Header.Get("Authorization"), ", ", ","); got != want {
		t.Errorf("signature mismatch:\n got %s\nwant %s", got, want)
	}
}

func TestSignV4MatchesTheAwsPutObjectExampleWithAnEncodedKey(t *testing.T) {
	const payloadHash = "44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072"

	req, _ := http.NewRequest(http.MethodPut, "https://examplebucket.s3.amazonaws.com/test$file.text", nil)
	req.Header.Set("Date", "Fri, 24 May 2013 00:00:00 GMT")
	req.Header.Set("x-amz-date", "20130524T000000Z")
	req.Header.Set("x-amz-storage-class", "REDUCED_REDUNDANCY")
	req.Header.Set("x-amz-content-sha256", payloadHash)

	signV4(req, []string{"date", "host", "x-amz-content-sha256", "x-amz-date", "x-amz-storage-class"}, payloadHash, awsExampleAccess, awsExampleSecret, "us-east-1", "s3", time.Date(2013, 5, 24, 0, 0, 0, 0, time.UTC))

	want := "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class,Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd"

	if got := strings.ReplaceAll(req.Header.Get("Authorization"), ", ", ","); got != want {
		t.Errorf("signature mismatch:\n got %s\nwant %s", got, want)
	}
}

func TestDestinationValidationRefusesAnythingThatIsNotAPlainHttpsStorageAddress(t *testing.T) {
	good := S3Destination{Endpoint: "https://s3.eu-west-1.amazonaws.com", Region: "eu-west-1", Bucket: "my-backups", AccessKey: "AKIAEXAMPLE", SecretKey: "abcd1234/efgh+5678=="}

	if err := validateS3(&good, "lesta/acct/2026-10-09-abcd1234.tar.gz"); err != nil {
		t.Fatalf("a valid destination was refused: %v", err)
	}

	for name, mutate := range map[string]func(d *S3Destination, key *string){
		"plain http":           func(d *S3Destination, _ *string) { d.Endpoint = "http://s3.example.com" },
		"a path":               func(d *S3Destination, _ *string) { d.Endpoint = "https://s3.example.com/admin" },
		"credentials":          func(d *S3Destination, _ *string) { d.Endpoint = "https://user:pw@s3.example.com" },
		"a query":              func(d *S3Destination, _ *string) { d.Endpoint = "https://s3.example.com?x=1" },
		"a file address":       func(d *S3Destination, _ *string) { d.Endpoint = "file:///etc/passwd" },
		"no host":              func(d *S3Destination, _ *string) { d.Endpoint = "https://" },
		"an upper-case bucket": func(d *S3Destination, _ *string) { d.Bucket = "My_Bucket" },
		"a header in a key":    func(d *S3Destination, _ *string) { d.AccessKey = "key\r\nX-Evil: 1" },
		"a space in a secret":  func(d *S3Destination, _ *string) { d.SecretKey = "has a space in it" },
		"a bad region":         func(d *S3Destination, _ *string) { d.Region = "../x" },
		"a climbing object":    func(_ *S3Destination, k *string) { *k = "a/../b" },
		"a slash start":        func(_ *S3Destination, k *string) { *k = "/abs" },
		"a double slash":       func(_ *S3Destination, k *string) { *k = "a//b" },
		"a query in the key":   func(_ *S3Destination, k *string) { *k = "a?b=c" },
	} {
		d, key := good, "ok/object.tar.gz"
		mutate(&d, &key)

		if err := validateS3(&d, key); err == nil {
			t.Errorf("%s: expected a refusal", name)
		}
	}

	if err := validateS3(nil, "x"); err == nil {
		t.Errorf("a missing destination must be refused")
	}
}

func TestOnlyPublicAddressesAreAllowedAsAStorageTarget(t *testing.T) {
	for ip, want := range map[string]bool{
		"8.8.8.8": true, "93.184.216.34": true, "2606:4700:4700::1111": true,
		"127.0.0.1": false, "10.0.0.5": false, "192.168.1.1": false, "172.16.0.1": false,
		"169.254.169.254": false, "::1": false, "fe80::1": false, "fd00::1": false, "0.0.0.0": false, "224.0.0.1": false,
	} {
		if got := isPublicIP(net.ParseIP(ip)); got != want {
			t.Errorf("%s: isPublicIP = %v, want %v", ip, got, want)
		}
	}

	client := storageClient(false)

	if _, err := client.Get("http://127.0.0.1:9/"); err == nil || !strings.Contains(err.Error(), "private or internal") {
		t.Errorf("the client must refuse a loopback target, got %v", err)
	}
}
