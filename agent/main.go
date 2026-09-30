// SPDX-License-Identifier: AGPL-3.0-only
package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/netip"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

const version = "0.1.1"

type Config struct {
	Panel   string `json:"panel"`
	Token   string `json:"token"`
	Machine string `json:"machine_id"`
}
type Profile struct {
	ID        string `json:"id"`
	Name      string `json:"name"`
	Source    string `json:"source"`
	Interface string `json:"interface"`
}
type Target struct {
	ID       int    `json:"id"`
	Revision int    `json:"revision"`
	Name     string `json:"name"`
	Host     string `json:"host"`
	Port     int    `json:"port"`
}
type RouteTarget struct {
	Name  string `json:"name"`
	Host  string `json:"host"`
	Host6 string `json:"host6,omitempty"`
}
type Task struct {
	ID      string `json:"id"`
	Profile string `json:"profile_id"`
	Target  int    `json:"target_id"`
	Expires int64  `json:"expires_at"`
}
type Plan struct {
	DisabledSources []string      `json:"disabled_sources"`
	Schema          int           `json:"schema_version"`
	ServerTime      int64         `json:"server_time"`
	Expires         int64         `json:"expires_at"`
	Interval        int           `json:"interval"`
	HeavyPaused     bool          `json:"heavy_paused"`
	Profiles        []Profile     `json:"profiles"`
	Targets         []Target      `json:"targets"`
	Routes          []RouteTarget `json:"route_targets"`
	Tasks           []Task        `json:"tasks"`
}
type Sample struct {
	Status string   `json:"status"`
	MS     *float64 `json:"ms,omitempty"`
}
type Result struct {
	Target   int      `json:"target_id"`
	Revision int      `json:"revision"`
	Family   int      `json:"family"`
	IP       string   `json:"resolved_ip,omitempty"`
	DNS      float64  `json:"dns_ms"`
	ICMP     []Sample `json:"icmp"`
	TCP      []Sample `json:"tcp"`
}
type Frame struct {
	ID      string            `json:"id"`
	Profile string            `json:"profile_id"`
	At      int64             `json:"observed_at"`
	Kind    string            `json:"kind"`
	Task    string            `json:"task_id,omitempty"`
	Results []Result          `json:"results,omitempty"`
	Quality map[string]any    `json:"quality,omitempty"`
	Gap     int               `json:"gap,omitempty"`
	Egress  map[string]string `json:"egress,omitempty"`
}
type Client struct {
	Config Config
	HTTP   *http.Client
}

func newClient(c Config) *Client {
	return &Client{c, &http.Client{Timeout: 20 * time.Second, CheckRedirect: func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }}}
}
func (c *Client) post(ctx context.Context, path string, in, out any) error {
	b, e := json.Marshal(in)
	if e != nil {
		return e
	}
	r, e := http.NewRequestWithContext(ctx, "POST", strings.TrimRight(c.Config.Panel, "/")+"/api/v1/node-quality/"+path, bytes.NewReader(b))
	if e != nil {
		return e
	}
	r.Header.Set("Content-Type", "application/json")
	if c.Config.Token != "" {
		r.Header.Set("Authorization", "Bearer "+c.Config.Token)
	}
	resp, e := c.HTTP.Do(r)
	if e != nil {
		return errors.New("panel transport failed")
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("panel HTTP %d", resp.StatusCode)
	}
	var envelope struct {
		Data json.RawMessage `json:"data"`
	}
	if e = json.NewDecoder(io.LimitReader(resp.Body, 4<<20)).Decode(&envelope); e != nil {
		return e
	}
	return json.Unmarshal(envelope.Data, out)
}
func atomicJSON(path string, v any) error {
	b, e := json.Marshal(v)
	if e != nil {
		return e
	}
	if e = os.MkdirAll(filepath.Dir(path), 0700); e != nil {
		return e
	}
	f, e := os.CreateTemp(filepath.Dir(path), ".tmp-")
	if e != nil {
		return e
	}
	tmp := f.Name()
	defer os.Remove(tmp)
	f.Chmod(0600)
	if _, e = f.Write(b); e == nil {
		e = f.Sync()
	}
	ce := f.Close()
	if e == nil {
		e = ce
	}
	if e != nil {
		return e
	}
	if e = os.Rename(tmp, path); e != nil {
		return e
	}
	d, e := os.Open(filepath.Dir(path))
	if e != nil {
		return e
	}
	defer d.Close()
	return d.Sync()
}
func readJSON(path string, v any) error {
	b, e := os.ReadFile(path)
	if e != nil {
		return e
	}
	return json.Unmarshal(b, v)
}
func token() string {
	b := make([]byte, 32)
	if _, e := rand.Read(b); e != nil {
		panic(e)
	}
	return hex.EncodeToString(b)
}
func frameID(p, kind string, at int64, task string) string {
	h := sha256.Sum256([]byte(fmt.Sprintf("%s|%s|%d|%s", p, kind, at, task)))
	return hex.EncodeToString(h[:])
}

var denied = []netip.Prefix{netip.MustParsePrefix("0.0.0.0/8"), netip.MustParsePrefix("100.64.0.0/10"), netip.MustParsePrefix("169.254.0.0/16"), netip.MustParsePrefix("192.0.0.0/24"), netip.MustParsePrefix("192.0.2.0/24"), netip.MustParsePrefix("198.18.0.0/15"), netip.MustParsePrefix("198.51.100.0/24"), netip.MustParsePrefix("203.0.113.0/24"), netip.MustParsePrefix("240.0.0.0/4"), netip.MustParsePrefix("2001:db8::/32"), netip.MustParsePrefix("64:ff9b::/96"), netip.MustParsePrefix("2002::/16")}

func publicIP(ip net.IP) bool {
	a, ok := netip.AddrFromSlice(ip)
	if !ok {
		return false
	}
	a = a.Unmap()
	if !a.IsGlobalUnicast() || a.IsPrivate() || a.IsLoopback() || a.IsLinkLocalUnicast() {
		return false
	}
	for _, p := range denied {
		if p.Contains(a) {
			return false
		}
	}
	return true
}
func resolve(ctx context.Context, host string, family int) (net.IP, float64, error) {
	start := time.Now()
	network := "ip4"
	if family == 6 {
		network = "ip6"
	}
	ips, e := net.DefaultResolver.LookupIP(ctx, network, host)
	ms := float64(time.Since(start).Microseconds()) / 1000
	if e != nil {
		var de *net.DNSError
		if errors.As(e, &de) && de.IsNotFound {
			return nil, ms, errors.New("unavailable")
		}
		return nil, ms, e
	}
	for _, ip := range ips {
		if !publicIP(ip) {
			return nil, ms, errors.New("blocked")
		}
	}
	if len(ips) == 0 {
		return nil, ms, errors.New("unavailable")
	}
	return ips[0], ms, nil
}
func outcome(e error) string {
	if e == nil {
		return "ok"
	}
	if errors.Is(e, context.DeadlineExceeded) {
		return "timeout"
	}
	var ne net.Error
	if errors.As(e, &ne) && ne.Timeout() {
		return "timeout"
	}
	if errors.Is(e, syscall.ECONNREFUSED) {
		return "refused"
	}
	if errors.Is(e, syscall.ENETUNREACH) || errors.Is(e, syscall.EADDRNOTAVAIL) {
		return "error"
	}
	return "error"
}
func success(ms float64) Sample { return Sample{"ok", &ms} }
func boundDialer(p Profile) *net.Dialer {
	d := &net.Dialer{Timeout: 2 * time.Second}
	if p.Source != "" {
		d.LocalAddr = &net.TCPAddr{IP: net.ParseIP(p.Source)}
	}
	if p.Interface != "" {
		d.Control = func(_, _ string, c syscall.RawConn) error {
			var err error
			e := c.Control(func(fd uintptr) {
				err = syscall.SetsockoptString(int(fd), syscall.SOL_SOCKET, syscall.SO_BINDTODEVICE, p.Interface)
			})
			if e != nil {
				return e
			}
			return err
		}
	}
	return d
}
func tcp(ctx context.Context, p Profile, ip net.IP, port int) Sample {
	start := time.Now()
	c, e := boundDialer(p).DialContext(ctx, "tcp", net.JoinHostPort(ip.String(), strconv.Itoa(port)))
	if e != nil {
		return Sample{Status: outcome(e)}
	}
	c.Close()
	return success(float64(time.Since(start).Microseconds()) / 1000)
}

type limitedBuffer struct {
	bytes.Buffer
	Limit int
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	n := len(p)
	remain := b.Limit - b.Len()
	if remain > 0 {
		if len(p) > remain {
			p = p[:remain]
		}
		b.Buffer.Write(p)
	}
	return n, nil
}
func command(ctx context.Context, path string, args ...string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, path, args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	cmd.Cancel = func() error {
		if cmd.Process != nil {
			return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
		}
		return nil
	}
	cmd.WaitDelay = time.Second
	var out limitedBuffer
	out.Limit = 1 << 20
	cmd.Stdout = &out
	cmd.Stderr = io.Discard
	cmd.Env = append(os.Environ(), "LC_ALL=C")
	e := cmd.Run()
	return out.Bytes(), e
}

var rtt = regexp.MustCompile(`time[=<]([0-9.]+)\s*ms`)

func ping(ctx context.Context, p Profile, ip net.IP, family int) Sample {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	args := []string{"-n", "-c", "1", "-W", "2", "-" + strconv.Itoa(family)}
	if p.Interface != "" {
		args = append(args, "-I", p.Interface)
	}
	if p.Source != "" {
		args = append(args, "-I", p.Source)
	}
	args = append(args, ip.String())
	out, e := command(ctx, "/usr/bin/ping", args...)
	if e != nil {
		if errors.Is(e, exec.ErrNotFound) || os.IsNotExist(e) {
			return Sample{Status: "unsupported"}
		}
		return Sample{Status: "timeout"}
	}
	m := rtt.FindSubmatch(out)
	if len(m) != 2 {
		return Sample{Status: "error"}
	}
	v, e := strconv.ParseFloat(string(m[1]), 64)
	if e != nil {
		return Sample{Status: "error"}
	}
	return success(v)
}
func probe(ctx context.Context, p Profile, t Target, family int) Result {
	r := Result{Target: t.ID, Revision: t.Revision, Family: family, ICMP: []Sample{}, TCP: []Sample{}}
	if !familyAvailable(p, family) {
		r.ICMP = []Sample{{Status: "unavailable"}}
		r.TCP = []Sample{{Status: "unavailable"}}
		return r
	}
	c, cancel := context.WithTimeout(ctx, 5*time.Second)
	ip, dns, e := resolve(c, t.Host, family)
	cancel()
	r.DNS = dns
	if e != nil {
		s := "dns_error"
		if e.Error() == "blocked" {
			s = "blocked"
		}
		if e.Error() == "unavailable" {
			s = "unavailable"
		}
		r.ICMP = []Sample{{Status: s}}
		r.TCP = []Sample{{Status: s}}
		return r
	}
	r.IP = ip.String()
	for i := 0; i < 4; i++ {
		r.ICMP = append(r.ICMP, ping(ctx, p, ip, family))
	}
	for i := 0; i < 3; i++ {
		r.TCP = append(r.TCP, tcp(ctx, p, ip, t.Port))
	}
	return r
}
func collect(ctx context.Context, p Profile, targets []Target, kind string, at int64, task string, sem chan struct{}) Frame {
	f := Frame{ID: frameID(p.ID, kind, at, task), Profile: p.ID, At: at, Kind: kind, Task: task, Results: []Result{}}
	var mu sync.Mutex
	var wg sync.WaitGroup
	for _, t := range targets {
		for _, af := range []int{4, 6} {
			wg.Add(1)
			go func(t Target, af int) {
				defer wg.Done()
				select {
				case sem <- struct{}{}:
				case <-ctx.Done():
					return
				}
				defer func() { <-sem }()
				r := probe(ctx, p, t, af)
				mu.Lock()
				f.Results = append(f.Results, r)
				mu.Unlock()
			}(t, af)
		}
	}
	wg.Wait()
	f.Egress = map[string]string{}
	for _, af := range []int{4, 6} {
		if ip, e := egress(ctx, p, af); e == nil {
			f.Egress[strconv.Itoa(af)] = ip
		}
	}
	sort.Slice(f.Results, func(i, j int) bool {
		if f.Results[i].Target == f.Results[j].Target {
			return f.Results[i].Family < f.Results[j].Family
		}
		return f.Results[i].Target < f.Results[j].Target
	})
	return f
}

type Spool struct {
	Dir string
	Mu  sync.Mutex
}

func (s *Spool) put(f Frame) error {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	return atomicJSON(filepath.Join(s.Dir, f.ID+".json"), f)
}
func (s *Spool) prune() int {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	files, _ := filepath.Glob(filepath.Join(s.Dir, "*.json"))
	type entry struct {
		path string
		size int64
		at   time.Time
	}
	var es []entry
	var total int64
	for _, p := range files {
		fi, e := os.Stat(p)
		if e == nil {
			es = append(es, entry{p, fi.Size(), fi.ModTime()})
			total += fi.Size()
		}
	}
	sort.Slice(es, func(i, j int) bool { return es[i].at.Before(es[j].at) })
	n := 0
	for _, e := range es {
		if time.Since(e.at) > 72*time.Hour || total > 256<<20 {
			if os.Remove(e.path) == nil {
				total -= e.size
				n++
			}
		}
	}
	return n
}
func (s *Spool) flush(ctx context.Context, c *Client) error {
	files, _ := filepath.Glob(filepath.Join(s.Dir, "*.json"))
	if len(files) > 80 {
		files = files[:80]
	}
	for start := 0; start < len(files); start += 3 {
		end := start + 3
		if end > len(files) {
			end = len(files)
		}
		var fs []Frame
		for _, p := range files[start:end] {
			var f Frame
			if readJSON(p, &f) == nil {
				fs = append(fs, f)
			}
		}
		if len(fs) == 0 {
			continue
		}
		var out struct {
			Ack      []string `json:"ack"`
			Rejected []struct {
				ID     string `json:"id"`
				Status int    `json:"status"`
			} `json:"rejected"`
		}
		if e := c.post(ctx, "agent/results", map[string]any{"frames": fs}, &out); e != nil {
			return e
		}
		allowed := map[string]bool{}
		for _, f := range fs {
			allowed[f.ID] = true
		}
		for _, id := range out.Ack {
			if allowed[id] {
				os.Remove(filepath.Join(s.Dir, id+".json"))
			}
		}
		for _, r := range out.Rejected {
			if allowed[r.ID] {
				log.Printf("frame rejected status=%d", r.Status)
				os.Remove(filepath.Join(s.Dir, r.ID+".json"))
			}
		}
	}
	return nil
}
func run(ctx context.Context, dir string, c Config) error {
	spool := &Spool{Dir: filepath.Join(dir, "spool")}
	os.MkdirAll(spool.Dir, 0700)
	client := newClient(c)
	var plan Plan
	readJSON(filepath.Join(dir, "plan.json"), &plan)
	last := map[string]int64{}
	readJSON(filepath.Join(dir, "slots.json"), &last)
	egresses := map[string]string{}
	readJSON(filepath.Join(dir, "egress.json"), &egresses)
	sem := make(chan struct{}, 4)
	heavy := make(chan struct{}, 1)
	var wg sync.WaitGroup
	defer wg.Wait()
	results := make(chan Frame, 128)
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	nextSync := time.Time{}
	nextUpload := time.Time{}
	retry := 15 * time.Second
	var offset int64
	for {
		select {
		case <-ctx.Done():
			return nil
		case f := <-results:
			if e := spool.put(f); e != nil {
				log.Printf("spool write failed: %v", e)
			}
			for af, ip := range f.Egress {
				key := f.Profile + ":" + af
				if old := egresses[key]; old != "" && old != ip {
					delete(last, "heavy:"+f.Profile)
				}
				egresses[key] = ip
			}
			atomicJSON(filepath.Join(dir, "egress.json"), egresses)
		case <-ticker.C:
			now := time.Now()
			if !now.Before(nextSync) {
				var p Plan
				e := client.post(ctx, "agent/sync", map[string]any{"capabilities": map[string]any{"version": version, "arch": runtime.GOARCH, "quality": fileExists("/opt/node-quality/collect-quality.sh"), "nexttrace": fileExists("/opt/node-quality/nexttrace")}}, &p)
				if e == nil && p.Schema == 1 && len(p.Targets) <= 20 && len(p.Profiles) <= 32 {
					plan = p
					offset = p.ServerTime - time.Now().Unix()
					atomicJSON(filepath.Join(dir, "plan.json"), plan)
					retry = 15 * time.Second
				} else {
					if e != nil && (strings.Contains(e.Error(), "HTTP 401") || strings.Contains(e.Error(), "HTTP 403") || strings.Contains(e.Error(), "HTTP 404")) {
						plan.Expires = 0
						atomicJSON(filepath.Join(dir, "plan.json"), plan)
					}
					log.Printf("plan sync unavailable")
					retry *= 2
					if retry > 5*time.Minute {
						retry = 5 * time.Minute
					}
				}
				nextSync = time.Now().Add(retry)
			}
			if !now.Before(nextUpload) {
				if e := spool.flush(ctx, client); e != nil {
					log.Printf("upload retry: %v", e)
				}
				nextUpload = time.Now().Add(15 * time.Second)
			}
			dropped := spool.prune()
			at := time.Now().Unix() + offset
			if at >= plan.Expires {
				continue
			}
			for _, p := range plan.Profiles {
				if dropped > 0 {
					spool.put(Frame{ID: frameID(p.ID, "gap", at, ""), Profile: p.ID, At: at, Kind: "gap", Gap: dropped})
				}
				slot := at / 300 * 300
				h := sha256.Sum256([]byte(p.ID))
				if len(plan.Targets) > 0 && at-slot >= int64(h[0]%30) && last[p.ID] < slot {
					if last[p.ID] > 0 && slot-last[p.ID] > 300 {
						spool.put(Frame{ID: frameID(p.ID, "gap", slot, ""), Profile: p.ID, At: slot, Kind: "gap", Gap: int((slot-last[p.ID])/300) - 1})
					}
					last[p.ID] = slot
					atomicJSON(filepath.Join(dir, "slots.json"), last)
					wg.Add(1)
					go func(p Profile, ts []Target, at int64) {
						defer wg.Done()
						x, cancel := context.WithTimeout(ctx, 240*time.Second)
						defer cancel()
						f := collect(x, p, ts, "scheduled", at, "", sem)
						select {
						case results <- f:
						case <-ctx.Done():
							spool.put(f)
						}
					}(p, append([]Target{}, plan.Targets...), slot)
				}
				for key, stamp := range last {
					if strings.HasPrefix(key, "task:") && stamp < at-86400 {
						delete(last, key)
					}
				}
				day := at / 86400 * 86400
				if !plan.HeavyPaused && last["heavy:"+p.ID] < day && at-day >= int64(h[0])*60 {
					select {
					case heavy <- struct{}{}:
						last["heavy:"+p.ID] = day
						atomicJSON(filepath.Join(dir, "slots.json"), last)
						wg.Add(1)
						go func(p Profile, rt []RouteTarget, disabled []string) {
							defer wg.Done()
							defer func() { <-heavy }()
							f := quality(ctx, p, rt, disabled)
							select {
							case results <- f:
							case <-ctx.Done():
								spool.put(f)
							}
						}(p, append([]RouteTarget{}, plan.Routes...), append([]string{}, plan.DisabledSources...))
					default:
					}
				}
			}
			for _, task := range plan.Tasks {
				if task.Expires <= at || last["task:"+task.ID] > 0 {
					continue
				}
				for _, p := range plan.Profiles {
					if p.ID != task.Profile {
						continue
					}
					for _, t := range plan.Targets {
						if t.ID != task.Target {
							continue
						}
						last["task:"+task.ID] = at
						atomicJSON(filepath.Join(dir, "slots.json"), last)
						wg.Add(1)
						go func(p Profile, t Target, task Task, at, clockOffset int64) {
							defer wg.Done()
							x, cancel := context.WithDeadline(ctx, time.Unix(task.Expires-clockOffset, 0))
							defer cancel()
							f := collect(x, p, []Target{t}, "manual", at, task.ID, sem)
							select {
							case results <- f:
							case <-ctx.Done():
								spool.put(f)
							}
						}(p, t, task, at, offset)
					}
				}
			}
		}
	}
}
func fileExists(path string) bool { _, e := os.Stat(path); return e == nil }
func main() {
	if len(os.Args) > 1 && os.Args[1] == "version" {
		fmt.Println(version)
		return
	}
	if len(os.Args) < 2 {
		log.Fatal("usage: node-quality-agent run|enroll|version")
	}
	fs := flag.NewFlagSet(os.Args[1], flag.ExitOnError)
	dir := fs.String("state", "/var/lib/node-quality", "state directory")
	panel := fs.String("panel", "", "HTTPS panel URL")
	enroll := fs.String("enroll-token", "", "one-time registration code")
	fs.Parse(os.Args[2:])
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()
	var c Config
	path := filepath.Join(*dir, "config.json")
	switch os.Args[1] {
	case "enroll":
		u, e := url.Parse(*panel)
		if e != nil || u.Scheme != "https" || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" {
			log.Fatal("panel must be HTTPS origin")
		}
		if readJSON(path, &c) != nil {
			c = Config{Panel: strings.TrimRight(*panel, "/"), Token: token()}
			if e = atomicJSON(path, c); e != nil {
				log.Fatal(e)
			}
		}
		if c.Panel != strings.TrimRight(*panel, "/") {
			log.Fatal("existing registration belongs to a different panel; use a new state directory")
		}
		var out struct {
			ID string `json:"machine_id"`
		}
		if e = newClient(c).post(ctx, "agent/enroll", map[string]string{"enroll_token": *enroll, "credential": c.Token}, &out); e != nil {
			log.Fatal(e)
		}
		c.Machine = out.ID
		if e = atomicJSON(path, c); e != nil {
			log.Fatal(e)
		}
		fmt.Println("registered", out.ID)
	case "run":
		if e := readJSON(path, &c); e != nil {
			log.Fatal(e)
		}
		lock, e := os.OpenFile(filepath.Join(*dir, "agent.lock"), os.O_CREATE|os.O_RDWR, 0600)
		if e != nil {
			log.Fatal(e)
		}
		defer lock.Close()
		if syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB) != nil {
			log.Fatal("agent already running")
		}
		if e = run(ctx, *dir, c); e != nil {
			log.Fatal(e)
		}
	default:
		log.Fatal("unknown command")
	}
}

func familyAvailable(p Profile, family int) bool {
	if p.Source != "" {
		ip := net.ParseIP(p.Source)
		return ip != nil && ((family == 4) == (ip.To4() != nil))
	}
	var addrs []net.Addr
	if p.Interface != "" {
		i, e := net.InterfaceByName(p.Interface)
		if e != nil {
			return false
		}
		addrs, _ = i.Addrs()
	} else {
		addrs, _ = net.InterfaceAddrs()
	}
	for _, a := range addrs {
		ip, _, e := net.ParseCIDR(a.String())
		if e == nil && ip.IsGlobalUnicast() && ((family == 4) == (ip.To4() != nil)) {
			return true
		}
	}
	return false
}
