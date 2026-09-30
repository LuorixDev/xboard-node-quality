package main

import (
	"context"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestPublicDestination(t *testing.T) {
	for _, s := range []string{"127.0.0.1", "10.1.2.3", "169.254.169.254", "100.64.0.1", "::1", "::ffff:127.0.0.1", "fe80::1", "fc00::1", "2001:db8::1", "64:ff9b::7f00:1"} {
		if publicIP(net.ParseIP(s)) {
			t.Fatal("accepted", s)
		}
	}
	for _, s := range []string{"1.1.1.1", "8.8.8.8", "2606:4700:4700::1111"} {
		if !publicIP(net.ParseIP(s)) {
			t.Fatal("rejected", s)
		}
	}
}
func TestResolvePrivate(t *testing.T) {
	_, _, e := resolve(context.Background(), "127.0.0.1", 4)
	if e == nil || e.Error() != "blocked" {
		t.Fatal(e)
	}
}
func TestTCPMeasurements(t *testing.T) {
	l, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	defer l.Close()
	a := l.Addr().(*net.TCPAddr)
	s := tcp(context.Background(), Profile{}, a.IP, a.Port)
	if s.Status != "ok" || s.MS == nil {
		t.Fatal(s)
	}
	l.Close()
	s = tcp(context.Background(), Profile{}, a.IP, a.Port)
	if s.Status == "ok" || s.MS != nil {
		t.Fatal("failure must not produce zero latency", s)
	}
}
func TestSpoolRetry(t *testing.T) {
	dir := t.TempDir()
	s := &Spool{Dir: dir}
	f := Frame{ID: strings.Repeat("a", 64), Profile: "p", At: time.Now().Unix(), Kind: "scheduled"}
	if e := s.put(f); e != nil {
		t.Fatal(e)
	}
	attempt := 0
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		attempt++
		if r.Header.Get("Authorization") != "Bearer test" {
			t.Error("missing auth")
		}
		if attempt == 1 {
			w.WriteHeader(503)
			return
		}
		json.NewEncoder(w).Encode(map[string]any{"data": map[string]any{"ack": []string{f.ID, "../../config"}}})
	}))
	defer server.Close()
	c := &Client{Config: Config{Panel: server.URL, Token: "test"}, HTTP: server.Client()}
	if s.flush(context.Background(), c) == nil {
		t.Fatal("expected retry")
	}
	if !fileExists(filepath.Join(dir, f.ID+".json")) {
		t.Fatal("lost unacknowledged frame")
	}
	if e := s.flush(context.Background(), c); e != nil {
		t.Fatal(e)
	}
	if fileExists(filepath.Join(dir, f.ID+".json")) {
		t.Fatal("ack not removed")
	}
}
func TestAtomicAndPrune(t *testing.T) {
	s := &Spool{Dir: t.TempDir()}
	f := Frame{ID: strings.Repeat("b", 64), Profile: "p"}
	if e := s.put(f); e != nil {
		t.Fatal(e)
	}
	path := filepath.Join(s.Dir, f.ID+".json")
	old := time.Now().Add(-74 * time.Hour)
	os.Chtimes(path, old, old)
	if s.prune() != 1 {
		t.Fatal("not pruned")
	}
}
func TestNormalization(t *testing.T) {
	v := normalize(map[string]any{"zero": "0", "missing": "null", "percentage": "0.47%"}).(map[string]any)
	if v["zero"] != "0" || v["missing"] != nil || v["percentage"] != "0.47%" {
		t.Fatal(v)
	}
}
func TestNoShell(t *testing.T) {
	ctx, c := context.WithTimeout(context.Background(), 2*time.Second)
	defer c()
	b, e := command(ctx, "/usr/bin/printf", "%s", "$(touch /tmp/should-not-exist)")
	if e != nil || string(b) != "$(touch /tmp/should-not-exist)" {
		t.Fatal(e, string(b))
	}
}
func TestProcessTimeout(t *testing.T) {
	ctx, c := context.WithTimeout(context.Background(), 30*time.Millisecond)
	defer c()
	start := time.Now()
	_, e := command(ctx, "/bin/sleep", "5")
	if e == nil || time.Since(start) > time.Second {
		t.Fatal("timeout failed", e)
	}
}
func TestStableIdentity(t *testing.T) {
	if frameID("p", "scheduled", 300, "") != frameID("p", "scheduled", 300, "") {
		t.Fatal("unstable")
	}
	if frameID("p", "scheduled", 300, "") == frameID("p", "manual", 300, "t") {
		t.Fatal("collision")
	}
}

func TestServiceEvidence(t *testing.T) {
	r := map[string]any{"Media": map[string]any{"Netflix": map[string]any{"Status": "Yes", "Region": "", "Type": "Native"}}}
	sanitizeMedia(r)
	if r["Media"].(map[string]any)["Netflix"].(map[string]any)["Status"] != "Unknown" {
		t.Fatal("unsubstantiated service success")
	}
}

func TestTraceSchema(t *testing.T) {
	var v any
	json.Unmarshal([]byte(`{"Hops":[[{"Success":true,"Address":{"IP":"1.1.1.1"},"Geo":{"Asnumber":"13335"},"RTT":12000000}]],"StopReason":{"reason":"destination_reached"}}`), &v)
	h := normalizedHops(v)["hops"].([]any)[0].(map[string]any)
	if h["ip"] != "1.1.1.1" || h["rtt_ms"] != float64(12) || len(routePath(v)) != 1 {
		t.Fatal(h, routePath(v))
	}
}
