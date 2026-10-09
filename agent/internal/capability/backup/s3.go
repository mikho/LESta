package backup

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// This file copies a backup to the account's own S3-compatible storage (AWS S3,
// MinIO, Backblaze B2, Wasabi, Cloudflare R2 and the like) with a single PUT
// signed with AWS Signature Version 4 (path-style addressing). Only the Go
// standard library is used, like everything else in the agent.

// S3Destination is where an account's backups are copied. The values come from
// the account owner, so each is validated against a strict pattern before it is
// used to build a request.
type S3Destination struct {
	Endpoint  string `json:"endpoint"`
	Region    string `json:"region"`
	Bucket    string `json:"bucket"`
	AccessKey string `json:"access_key"`
	SecretKey string `json:"secret_key"`
}

var (
	s3RegionPattern    = regexp.MustCompile(`^[a-z0-9-]{2,30}$`)
	s3BucketPattern    = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$`)
	s3AccessKeyPattern = regexp.MustCompile(`^[A-Za-z0-9._-]{4,128}$`)
	s3SecretKeyPattern = regexp.MustCompile(`^[A-Za-z0-9/+=._-]{8,256}$`)
	s3ObjectKeyPattern = regexp.MustCompile(`^[A-Za-z0-9!_.*'()/-]{1,512}$`)
)

// validateS3 checks a destination and an object key. The endpoint must be an
// https address of a host, with no path, query or credentials.
func validateS3(d *S3Destination, objectKey string) error {
	fail := func(field, message string) error {
		return &ValidationError{Code: "invalid_destination", Message: message, Field: "destination." + field}
	}

	if d == nil {
		return fail("endpoint", "a storage destination is required")
	}

	u, err := url.Parse(d.Endpoint)
	if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || (u.Path != "" && u.Path != "/") || u.RawQuery != "" || u.Fragment != "" {
		return fail("endpoint", "the endpoint must be an https address of a host, such as https://s3.example.com")
	}

	if strings.ContainsAny(u.Host, " \t\r\n") {
		return fail("endpoint", "the endpoint is not a valid address")
	}

	switch {
	case !s3RegionPattern.MatchString(d.Region):
		return fail("region", "the region is not valid")
	case !s3BucketPattern.MatchString(d.Bucket) || strings.Contains(d.Bucket, ".."):
		return fail("bucket", "the bucket name is not valid")
	case !s3AccessKeyPattern.MatchString(d.AccessKey):
		return fail("access_key", "the access key is not valid")
	case !s3SecretKeyPattern.MatchString(d.SecretKey):
		return fail("secret_key", "the secret key is not valid")
	case !s3ObjectKeyPattern.MatchString(objectKey) || strings.Contains(objectKey, "..") || strings.Contains(objectKey, "//") || strings.HasPrefix(objectKey, "/"):
		return &ValidationError{Code: "invalid_destination", Message: "the object name is not valid", Field: "object_key"}
	}

	return nil
}

// uriEncode percent-encodes s as Signature V4 requires: every byte except
// A-Z a-z 0-9 - _ . ~ is encoded, and "/" is kept when keepSlash is true.
func uriEncode(s string, keepSlash bool) string {
	const unreserved = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_.~"

	var b strings.Builder

	for i := 0; i < len(s); i++ {
		c := s[i]

		switch {
		case strings.IndexByte(unreserved, c) >= 0, c == '/' && keepSlash:
			b.WriteByte(c)
		default:
			fmt.Fprintf(&b, "%%%02X", c)
		}
	}

	return b.String()
}

func hmacSHA256(key []byte, data string) []byte {
	m := hmac.New(sha256.New, key)
	m.Write([]byte(data))

	return m.Sum(nil)
}

// signV4 adds an Authorization header to req (whose x-amz-date and
// x-amz-content-sha256 headers, and Host, are already set), signing the
// headers named in signedHeaders.
func signV4(req *http.Request, signedHeaders []string, payloadHash, accessKey, secretKey, region, service string, now time.Time) {
	amzDate := now.UTC().Format("20060102T150405Z")
	day := now.UTC().Format("20060102")

	names := make([]string, len(signedHeaders))
	for i, h := range signedHeaders {
		names[i] = strings.ToLower(h)
	}

	sort.Strings(names)

	var canonicalHeaders strings.Builder

	for _, name := range names {
		value := req.Header.Get(name)
		if name == "host" {
			value = req.Host
			if value == "" {
				value = req.URL.Host
			}
		}

		canonicalHeaders.WriteString(name + ":" + strings.Join(strings.Fields(value), " ") + "\n")
	}

	query := req.URL.Query()
	pairs := make([]string, 0, len(query))

	for key, values := range query {
		for _, v := range values {
			pairs = append(pairs, uriEncode(key, false)+"="+uriEncode(v, false))
		}
	}

	sort.Strings(pairs)

	canonicalRequest := strings.Join([]string{
		req.Method,
		uriEncode(req.URL.Path, true),
		strings.Join(pairs, "&"),
		canonicalHeaders.String(),
		strings.Join(names, ";"),
		payloadHash,
	}, "\n")

	scope := day + "/" + region + "/" + service + "/aws4_request"
	hash := sha256.Sum256([]byte(canonicalRequest))
	stringToSign := "AWS4-HMAC-SHA256\n" + amzDate + "\n" + scope + "\n" + hex.EncodeToString(hash[:])

	key := hmacSHA256([]byte("AWS4"+secretKey), day)
	key = hmacSHA256(key, region)
	key = hmacSHA256(key, service)
	key = hmacSHA256(key, "aws4_request")

	signature := hex.EncodeToString(hmacSHA256(key, stringToSign))

	req.Header.Set("Authorization", "AWS4-HMAC-SHA256 Credential="+accessKey+"/"+scope+", SignedHeaders="+strings.Join(names, ";")+", Signature="+signature)
}

// isPublicIP reports whether ip is an address a node should send a tenant's
// storage request to: not loopback, private, link-local, unspecified or
// multicast.
func isPublicIP(ip net.IP) bool {
	return !(ip.IsLoopback() || ip.IsPrivate() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() || ip.IsUnspecified() || ip.IsMulticast() || ip.IsInterfaceLocalMulticast())
}

// storageClient is the HTTP client for a copy: no redirects (a storage endpoint
// that redirects is not followed), and, unless allowPrivate, a dialer that
// refuses to connect to a non-public address, checked on the address actually
// dialled so a name that resolves to an internal address (or is rebound to one)
// cannot reach the node's own network.
func storageClient(allowPrivate bool) *http.Client {
	dialer := &net.Dialer{
		Timeout: 30 * time.Second,
		Control: func(_, address string, _ syscall.RawConn) error {
			if allowPrivate {
				return nil
			}

			host, _, err := net.SplitHostPort(address)
			if err != nil {
				return err
			}

			if ip := net.ParseIP(host); ip == nil || !isPublicIP(ip) {
				return errors.New("the storage endpoint resolves to a private or internal address, which is not allowed")
			}

			return nil
		},
	}

	return &http.Client{
		Transport:     &http.Transport{DialContext: dialer.DialContext, TLSHandshakeTimeout: 30 * time.Second, ResponseHeaderTimeout: 10 * time.Minute},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
}

// s3Put uploads the file at path (size bytes, with the given SHA-256) to
// <endpoint>/<bucket>/<key> in one signed PUT.
func s3Put(ctx context.Context, client *http.Client, d S3Destination, key, path string, size int64, payloadHash string, now time.Time) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}

	defer func() { _ = f.Close() }()

	endpoint, err := url.Parse(d.Endpoint)
	if err != nil {
		return err
	}

	target := *endpoint
	target.Path = "/" + d.Bucket + "/" + key
	target.RawPath = ""

	req, err := http.NewRequestWithContext(ctx, http.MethodPut, target.String(), f)
	if err != nil {
		return err
	}

	req.ContentLength = size
	req.Header.Set("Content-Type", "application/gzip")
	req.Header.Set("x-amz-content-sha256", payloadHash)
	req.Header.Set("x-amz-date", now.UTC().Format("20060102T150405Z"))
	signV4(req, []string{"host", "content-type", "x-amz-content-sha256", "x-amz-date"}, payloadHash, d.AccessKey, d.SecretKey, d.Region, "s3", now)

	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("the storage endpoint could not be reached: %w", err)
	}

	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode/100 == 2 {
		return nil
	}

	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))

	return fmt.Errorf("the storage refused the upload (%s): %s", strconv.Itoa(resp.StatusCode), strings.TrimSpace(string(body)))
}
