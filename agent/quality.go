package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"
)

func egress(ctx context.Context, p Profile, family int) (string, error) {
	network := "tcp4"
	if family == 6 {
		network = "tcp6"
	}
	tr := &http.Transport{DialContext: func(ctx context.Context, _, addr string) (net.Conn, error) {
		return boundDialer(p).DialContext(ctx, network, addr)
	}}
	defer tr.CloseIdleConnections()
	c := &http.Client{Transport: tr, Timeout: 8 * time.Second, CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }}
	req, _ := http.NewRequestWithContext(ctx, "GET", "https://api64.ipify.org", nil)
	resp, e := c.Do(req)
	if e != nil {
		return "", e
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return "", fmt.Errorf("IP echo HTTP %d", resp.StatusCode)
	}
	b, e := io.ReadAll(io.LimitReader(resp.Body, 100))
	if e != nil {
		return "", e
	}
	ip := net.ParseIP(strings.TrimSpace(string(b)))
	if !publicIP(ip) {
		return "", fmt.Errorf("invalid egress response")
	}
	return ip.String(), nil
}
func normalize(v any) any {
	switch x := v.(type) {
	case string:
		if x == "null" || x == "N/A" || x == "" {
			return nil
		}
		return x
	case map[string]any:
		for k, val := range x {
			x[k] = normalize(val)
		}
		return x
	case []any:
		for i, val := range x {
			x[i] = normalize(val)
		}
		return x
	}
	return v
}
func quality(ctx context.Context, p Profile, targets []RouteTarget, disabled []string) Frame {
	at := time.Now().Unix()
	f := Frame{ID: frameID(p.ID, "quality", at, ""), Profile: p.ID, At: at, Kind: "quality"}
	eg := map[string]string{}
	reports := map[string]any{}
	routes := []any{}
	for _, af := range []int{4, 6} {
		ip, e := egress(ctx, p, af)
		if e != nil {
			reports[strconv.Itoa(af)] = map[string]any{"status": "unavailable"}
			continue
		}
		eg[strconv.Itoa(af)] = ip
		bind := p.Interface
		if p.Source != "" {
			bind = p.Source
		}
		x, cancel := context.WithTimeout(ctx, 6*time.Minute)
		raw, e := command(x, "/bin/bash", "/opt/node-quality/collect-quality.sh", ip, strconv.Itoa(af), bind, strings.Join(disabled, ","))
		cancel()
		var report map[string]any
		if e != nil || json.Unmarshal(raw, &report) != nil {
			reports[strconv.Itoa(af)] = map[string]any{"status": "unavailable", "source": "IPQuality", "version": "2384a67c"}
		} else {
			sanitizeMedia(report)
			reports[strconv.Itoa(af)] = map[string]any{"status": "ok", "observed_at": at, "source": "IPQuality", "version": "2384a67c", "report": normalize(report)}
		}
		for _, target := range targets {
			r := map[string]any{"name": target.Name, "family": af, "status": "unavailable"}
			x, cancel := context.WithTimeout(ctx, 5*time.Second)
			dst, _, e := resolve(x, routeHost(target, af), af)
			cancel()
			if e != nil {
				routes = append(routes, r)
				continue
			}
			args := []string{"--json", "--tcp", "--port", "443", "--queries", "2", "--max-hops", "24", "-" + strconv.Itoa(af)}
			if p.Source != "" {
				args = append(args, "--source", p.Source)
			}
			if p.Interface != "" {
				args = append(args, "--dev", p.Interface)
			}
			args = append(args, dst.String())
			x, cancel = context.WithTimeout(ctx, 60*time.Second)
			raw, e = command(x, "/opt/node-quality/nexttrace", args...)
			cancel()
			var data any
			if e == nil && json.Unmarshal(raw, &data) == nil {
				r["status"] = "partial"
				if m, ok := data.(map[string]any); ok {
					if stop, ok := m["StopReason"].(map[string]any); ok && stop["reason"] == "destination_reached" {
						r["status"] = "ok"
					}
				}
				r["hops"] = normalizedHops(data)
				r["path"] = routePath(data)
			}
			routes = append(routes, r)
		}
	}
	f.Quality = map[string]any{"egress": eg, "reports": reports, "routes": routes, "scope": "machine_egress"}
	return f
}

func normalizedHops(v any) map[string]any {
	out := []any{}
	m, _ := v.(map[string]any)
	groups, _ := m["Hops"].([]any)
	for i, group := range groups {
		hops, _ := group.([]any)
		for _, raw := range hops {
			h, _ := raw.(map[string]any)
			a, _ := h["Address"].(map[string]any)
			g, _ := h["Geo"].(map[string]any)
			rtt, _ := h["RTT"].(float64)
			out = append(out, map[string]any{"ttl": i + 1, "ip": a["IP"], "asn": g["Asnumber"], "rtt_ms": rtt / 1e6, "success": h["Success"]})
		}
	}
	return map[string]any{"hops": out}
}

// Extract ordered ASN evidence only; unstable RTT/IP choices never trigger route-change events.
func routePath(v any) []string {
	out := []string{}
	var walk func(any)
	walk = func(v any) {
		switch x := v.(type) {
		case []any:
			for _, a := range x {
				walk(a)
			}
		case map[string]any:
			if a, ok := x["Asnumber"]; ok && fmt.Sprint(a) != "" && fmt.Sprint(a) != "<nil>" {
				s := fmt.Sprint(a)
				if len(out) == 0 || out[len(out)-1] != s {
					out = append(out, s)
				}
			}
			for _, key := range []string{"Hops", "hops", "Geo", "geo", "GeoInfo", "geoinfo"} {
				if a, ok := x[key]; ok {
					walk(a)
				}
			}
		}
	}
	walk(v)
	return out
}

func routeHost(t RouteTarget, af int) string {
	if af == 6 && t.Host6 != "" {
		return t.Host6
	}
	return t.Host
}

func sanitizeMedia(report map[string]any) {
	media, _ := report["Media"].(map[string]any)
	for _, v := range media {
		m, ok := v.(map[string]any)
		if !ok {
			continue
		}
		status := fmt.Sprint(m["Status"])
		region := strings.TrimSpace(fmt.Sprint(m["Region"]))
		if (status == "Yes" || status == "Unlocked") && (region == "" || region == "null" || region == "<nil>") {
			m["Status"] = "Unknown"
			m["Type"] = nil
			m["Evidence"] = "No corroborating region evidence"
		}
	}
}
