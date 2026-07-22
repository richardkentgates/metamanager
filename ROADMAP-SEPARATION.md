# Metamanager Separation Roadmap

**Date:** 2026-07-22
**Goal:** Separate the WordPress plugin from the OS daemon layer into two independent repos, set up an apt repository, and configure automated deployments.

---

## Architecture

```
GitHub: richardkentgates/metamanager-plugin     GitHub: richardkentgates/metamanager
  ├── metamanager.php                              ├── daemons/
  ├── includes/ (PHP classes)                      │   ├── metamanager-compress-daemon.sh
  ├── templates/                                   │   ├── metamanager-meta-daemon.sh
  ├── assets/ (JS/CSS)                             │   ├── *.service (systemd units)
  ├── tests/                                       ├── metamanager-install.sh
  ├── languages/                                   ├── debian/
  ├── composer.json                                └── README.md
  ├── phpstan.neon
  ├── phpunit.xml.dist
  └── README.md

Server: apt.richardkentgates.com (Debian 13, LAMP)
  ├── /var/www/html/apt/                           (apt repository for daemon .deb packages)
  │   ├── pool/m/metamanager/
  │   │   ├── metamanager_2.4.0-1_all.deb      (release)
  │   │   └── metamanager_2.4.0~test1_all.deb   (test)
  │   ├── dists/
  │   │   ├── bookworm/
  │   │   │   └── main/binary-amd64/
  │   │   │       ├── Packages
  │   │   │       └── Packages.gz
  │   │   └── bookworm-test/
  │   │       └── main/binary-amd64/
  │   │           ├── Packages
  │   │           └── Packages.gz
  │   └── key.gpg
  │
  └── /var/www/html/metamanager/                   (plugin files for WordPress auto-updates)
      ├── metamanager.php
      ├── includes/
      ├── templates/
      ├── assets/
      ├── metamanager-latest.zip                   (latest build for WP updater)
      └── metadata.json                            (version info for WP update check)
```

## Deployment Flow

```
Plugin Repo (metamanager-plugin)
  test branch  → rsync → server:/var/www/html/metamanager/ (plugin files + zip)
  main branch  → rsync → server:/var/www/html/metamanager/ + build zip + update metadata.json
  WP auto-update: plugin checks server metadata.json, downloads zip from server

Daemon Repo (metamanager)
  test branch  → dpkg-buildpackage → SCP → apt server pool/ → regenerate bookworm-test/Packages
  main branch  → dpkg-buildpackage → SCP → apt server pool/ → regenerate bookworm/Packages
  Client: apt update && apt install metamanager (or apt upgrade)
```

## Branch Strategy

Both repos follow the same pattern:
```
test  →  development, pre-release validation, deploys to server as "test" builds
main  →  stable releases, tagged versions, deploys to server as "release" builds
```

- `test` branch: push to trigger CI → deploys to server
- `main` branch: merge from test → tagged release → deploys to server
- Both branches are available on the server for testing

## Server Setup (apt.richardkentgates.com — Debian 13, 1.9GB RAM, 9.7GB disk)

### Security Stack
```
UFW (static) — base rules: 22, 80, 443 open, everything else denied
    ↓
iptables (dynamic under UFW) — fail2ban injects ban rules into ufw-before-input
    ↓
modsecurity — flood mitigation, request anomaly detection (no OWASP CRS)
    ↓
fail2ban — ban IPs based on modsecurity + SSH + Apache triggers (banaction = ufw)
    ↓
maldet — active daemon, daily malware scans of /var/www/
```

### Services to Install
| Package | Purpose | RAM Impact |
|---------|---------|------------|
| apache2 | HTTP server for apt repo + plugin hosting | ~50MB |
| libapache2-mod-php | PHP for potential dynamic endpoints | ~20MB |
| modsecurity | Flood mitigation layer (no OWASP CRS) | ~30MB |
| fail2ban | IP banning based on trigger logs | ~10MB |
| iptables-persistent | Firewall rules persistence | ~1MB |
| maldet | Active malware scanning daemon | ~20MB |
| dpkg-dev | Build apt package indices | 0 (build only) |
| **Total** | | **~130MB** |

### Directory Structure
```
/var/www/html/
├── apt/                          (apt repository)
│   ├── pool/m/metamanager/
│   │   └── *.deb
│   └── dists/
│       ├── bookworm/
│       │   └── main/binary-amd64/ (Packages, Packages.gz)
│       └── bookworm-test/
│           └── main/binary-amd64/ (Packages, Packages.gz)
│
└── metamanager/                  (plugin files for WP auto-updates)
    ├── metamanager.php
    ├── includes/
    ├── templates/
    ├── assets/
    ├── metamanager-latest.zip
    └── metadata.json
```

### Client Machine Setup

```bash
# Import GPG key
wget -qO - https://apt.richardkentgates.com/key.gpg | sudo gpg --dearmor -o /usr/share/keyrings/metamanager.gpg

# Add repo (stable)
echo "deb [signed-by=/usr/share/keyrings/metamanager.gpg] https://apt.richardkentgates.com bookworm main" | sudo tee /etc/apt/sources.list.d/metamanager.list

# OR add repo (test)
echo "deb [signed-by=/usr/share/keyrings/metamanager.gpg] https://apt.richardkentgates.com bookworm-test main" | sudo tee /etc/apt/sources.list.d/metamanager.list

# Install
sudo apt update && sudo apt install metamanager
```

---

## Phase 1: Create New Plugin Repo

- [ ] 1.1 Create `/home/richard/Public/Projects/metamanager-plugin/`
- [ ] 1.2 `git init` in new directory
- [ ] 1.3 Create GitHub repo `richardkentgates/metamanager-plugin`
- [ ] 1.4 Add remote and push initial commit

## Phase 2: Migrate Plugin Files

- [ ] 2.1 Copy `metamanager.php`, `uninstall.php`, `index.php`
- [ ] 2.2 Copy `includes/` (all PHP classes — 18 core + 12 modules + 5 admin)
- [ ] 2.3 Copy `templates/`, `assets/`, `languages/`
- [ ] 2.4 Copy `tests/` and test config files
- [ ] 2.5 Copy `composer.json`, `phpstan.neon`, `phpunit.xml.dist`, `tests/phpunit.xml`
- [ ] 2.6 Copy docs: `README.md`, `ARCHITECTURE.md`, `CHANGELOG.md`, `ROADMAP.md`, `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`
- [ ] 2.7 Copy `LICENSE`, `.gitignore`, `.distignore`
- [ ] 2.8 Update `README.md` for plugin-only context (Installation: "Install via apt, then activate plugin")
- [ ] 2.9 Commit and push to GitHub

## Phase 3: Strip Current Repo to Daemon-Only

- [ ] 3.1 Remove `includes/`, `templates/`, `assets/`, `tests/`, `languages/`
- [ ] 3.2 Remove `metamanager.php`, `uninstall.php`, `index.php`
- [ ] 3.3 Remove `composer.json`, `composer.lock`, `phpstan.neon`, `phpunit.xml*`
- [ ] 3.4 Remove `.distignore` (not needed for daemon repo)
- [ ] 3.5 Remove `phpunit.xml.dist`, `tests/phpunit.xml`, `tests/bootstrap.php`, `tests/bin/`, `tests/stubs/`, `tests/Integration/`
- [ ] 3.6 Update `README.md` for daemon-only context
- [ ] 3.7 Update `ARCHITECTURE.md` for daemon-only context (remove PHP architecture, keep daemon architecture)
- [ ] 3.8 Update `debian/metamanager.install` — remove plugin files, keep only `metamanager-install.sh` and `daemons`
- [ ] 3.9 Update `debian/control` — update description to daemon-only
- [ ] 3.10 Update `debian/changelog` — bump to 2.4.0
- [ ] 3.11 Update `.github/workflows/build-deb.yml` if needed
- [ ] 3.12 Commit and push

## Phase 4: Update Install Script

- [ ] 4.1 Change GitHub clone URL from `metamanager` to `metamanager-plugin`
- [ ] 4.2 Remove `daemons/` references from plugin copy logic (plugin repo won't have daemons/)
- [ ] 4.3 Update `--update` mode to fetch from new repo URL
- [ ] 4.4 Test install script locally (dry run)

## Phase 5: Add Daemon Hard-Fail to Plugin

- [ ] 5.1 Add `MM_Status::daemon_package_installed()` — checks for `/usr/local/bin/metamanager-compress-daemon.sh`
- [ ] 5.2 Add activation check in `mm_activate_single_site()` — if daemons missing, throw `WP_Error`
- [ ] 5.3 Add persistent admin notice on `admin_init` — "Metamanager requires the daemon package. Install: `sudo apt install metamanager`"
- [ ] 5.4 Write tests for daemon detection logic

## Phase 6: Formalize Job Queue Contract

- [ ] 6.1 Create `JOB_QUEUE_SPEC.md` with directory structure
- [ ] 6.2 Document JSON job input schema (compress and metadata job types)
- [ ] 6.3 Document JSON result output schema (completed and failed)
- [ ] 6.4 Document atomic ownership claim protocol (`.processing` rename)
- [ ] 6.5 Add spec to both repos

## Phase 7: Server Setup — LMAP Stack + Security (apt.richardkentgates.com)

### 7A: System Hardening

- [ ] 7A.1 Update system packages: `apt update && apt upgrade -y`
- [ ] 7A.2 Set timezone: `timedatectl set-timezone UTC`
- [ ] 7A.3 Install essentials: `apt install -y curl wget git unzip software-properties-common apt-transport-https ca-certificates`
- [ ] 7A.4 Create deploy user (if not using richardkentgates for deploys): `useradd -m -s /bin/bash deploy`
- [ ] 7A.5 Harden SSH:
  - Disable root login: `PermitRootLogin no`
  - Disable password auth: `PasswordAuthentication no`
  - Key-only auth: `PubkeyAuthentication yes`
  - Limit SSH to deploy user only
  - Restart sshd
- [ ] 7A.6 Configure unattended-upgrades for security patches

### 7B: Install LMAP Stack (Apache + PHP, no MariaDB)

- [ ] 7B.1 Install Apache: `apt install -y apache2`
- [ ] 7B.2 Install PHP: `apt install -y php libapache2-mod-php php-cli`
- [ ] 7B.3 Enable Apache modules: `a2enmod rewrite headers ssl`
- [ ] 7B.4 Configure Apache performance:
  - `KeepAlive On`, `MaxKeepAliveRequests 100`, `KeepAliveTimeout 5`
  - `Timeout 30`
  - Disable `ServerSignature` and `ServerTokens`
- [ ] 7B.5 Create directory structure:
  ```
  /var/www/html/apt/           (apt repository)
  /var/www/html/metamanager/   (plugin hosting)
  ```
- [ ] 7B.6 Configure Apache vhost:
  ```apache
  <VirtualHost *:80>
      ServerName apt.richardkentgates.com
      DocumentRoot /var/www/html
      <Directory /var/www/html>
          Options -Indexes +FollowSymLinks
          AllowOverride None
          Require all granted
      </Directory>
      # Serve apt repo with correct MIME types
      <Directory /var/www/html/apt/dists>
          Options -Indexes
      </Directory>
  </VirtualHost>
  ```
- [ ] 7B.7 Test Apache: `curl http://localhost`

### 7C: Install UFW + iptables (UFW static, iptables dynamic via fail2ban)

UFW is the static firewall (the "king" layer). iptables is the dynamic layer underneath — fail2ban and modsecurity inject temporary ban rules into UFW's chains.

**Architecture:**
```
UFW (static rules)              Dynamic layer (iptables under UFW)
┌─────────────────────┐    ┌──────────────────────────────────┐
│ ufw default deny    │    │ fail2ban:                         │
│ ufw allow 22/tcp    │    │   injects rules into ufw-        │
│ ufw allow 80/tcp    │    │   before-input to ban IPs        │
│ ufw allow 443/tcp   │    │                                  │
│                     │    │ modsecurity:                      │
│                     │    │   logs triggers → fail2ban reads  │
└─────────────────────┘    └──────────────────────────────────┘
```

- [ ] 7C.1 Install UFW: `apt install -y ufw`
- [ ] 7C.2 Set default policies:
  ```bash
  ufw default deny incoming
  ufw default allow outgoing
  ```
- [ ] 7C.3 Allow services:
  ```bash
  ufw allow 22/tcp    # SSH
  ufw allow 80/tcp    # HTTP
  ufw allow 443/tcp   # HTTPS
  ```
- [ ] 7C.4 Enable UFW: `ufw enable`
- [ ] 7C.5 Verify: `ufw status verbose`
- [ ] 7C.6 Configure fail2ban to inject into UFW chains:
  - Set `banaction = ufw` in `/etc/fail2ban/jail.local` `[DEFAULT]`
  - This makes fail2ban use `ufw deny from <IP>` instead of raw iptables
- [ ] 7C.7 Add rate limiting via UFW:
  ```bash
  # Rate limit SSH (6 connections/30 seconds)
  ufw limit 22/tcp
  ```
- [ ] 7C.8 IPv6: `ufw enable` already handles IPv6 if enabled
- [ ] 7C.9 Verify UFW is the sole iptables manager: `iptables -L -n | head -20` (should show ufw- prefixed chains)
- [ ] 7C.10 Test: `ufw status` — should show active with rules

### 7D: Install ModSecurity (Flood Mitigation)

- [ ] 7D.1 Install: `apt install -y libapache2-mod-security2`
- [ ] 7D.2 Enable module: `a2enmod security2`
- [ ] 7D.3 Configure `/etc/apache2/mods-enabled/security2.conf`:
  - `SecRuleEngine On`
  - `SecRequestBodyAccess On`
  - `SecResponseBodyAccess Off` (not needed for static files)
  - `SecTmpDir /tmp/`
  - `SecDataDir /tmp/`
- [ ] 7D.4 Create custom rules (NO OWASP CRS) for flood/scanning mitigation:
  ```apache
  # Rate limit: max 30 requests per 10 seconds per IP
  SecAction "id:100001,phase:1,pass,nolog,setvar:ip.request_count=+1,expirevar:ip.request_count=10"
  SecRule IP:REQUEST_COUNT "@gt 30" "id:100002,phase:1,log,pass,msg:'Flood detected',setvar:ip.flood=1"

  # Block known scanner user agents
  SecRule REQUEST_HEADERS:User-Agent "@pmFromFile scanners-user-agents.data" "id:100003,phase:1,log,deny,status:403,msg:'Scanner user agent blocked'"

  # Block directory traversal attempts
  SecRule REQUEST_URI "\.\./" "id:100004,phase:1,log,deny,status:403,msg:'Directory traversal blocked'"

  # Block common exploit paths
  SecRule REQUEST_URI "@pm /etc/passwd /etc/shadow wp-config.php .env .git" "id:100005,phase:1,log,deny,status:403,msg:'Exploit path blocked'"
  ```
- [ ] 7D.5 Create `/etc/apache2/security2.data/scanners-user-agents.data` with known scanner UA strings
- [ ] 7D.6 Restart Apache, verify: `curl -A "Nikto" http://localhost/` (should be blocked)

### 7E: Install Fail2Ban

- [ ] 7E.1 Install: `apt install -y fail2ban`
- [ ] 7E.2 Create `/etc/fail2ban/jail.local`:
  ```ini
  [DEFAULT]
  bantime = 3600
  findtime = 600
  maxretry = 5
  backend = systemd

  [sshd]
  enabled = true
  port = 22
  maxretry = 3
  bantime = 86400

  [apache-modsecurity]
  enabled = true
  filter = apache-modsecurity
  logpath = /var/log/apache2/error.log
  maxretry = 5
  bantime = 3600

  [apache-badbots]
  enabled = true
  filter = apache-badbots
  logpath = /var/log/apache2/access.log
  maxretry = 2
  bantime = 86400

  [recidive]
  enabled = true
  filter = recidive
  logpath = /var/log/fail2ban.log
  bantime = 604800
  findtime = 86400
  maxretry = 3
  ```
- [ ] 7E.3 Create custom filter `/etc/fail2ban/filter.d/apache-modsecurity.conf`:
  ```ini
  [Definition]
  failregex = ^.*\[error\].*\[client <HOST>\].*ModSecurity:.*
  ignoreregex =
  ```
- [ ] 7E.4 Create custom filter `/etc/fail2ban/filter.d/apache-badbots.conf`
- [ ] 7E.5 Start fail2ban: `systemctl enable --now fail2ban`
- [ ] 7E.6 Verify: `fail2ban-client status`

### 7F: Install Maldet (Linux Malware Detect)

- [ ] 7F.1 Install dependencies: `apt install -y liblockfile-simple-perl`
- [ ] 7F.2 Download and install maldet:
  ```bash
  cd /tmp
  wget https://www.rfxn.com/downloads/maldetect-current.tar.gz
  tar xzf maldetect-current.tar.gz
  cd maldetect-*
  ./install.sh
  ```
- [ ] 7F.3 Configure `/usr/local/maldetect/conf.maldet`:
  - `scan_user="root"` (scan web directories)
  - `quarantine_hits="1"`
  - `quarantine_clean="0"` (don't auto-delete, alert first)
  - `email_alert="1"`
  - `email_addr="your@email.com"`
- [ ] 7F.4 Set up daily scan cron: `/etc/cron.daily/maldet`:
  ```bash
  #!/bin/bash
  /usr/local/maldetect/maldet --scan-all /var/www/html/ >> /var/log/maldet-scan.log 2>&1
  ```
- [ ] 7F.5 Start maldet monitoring daemon: `maldet --monitor /var/www/html/`
- [ ] 7F.6 Enable maldet service at boot
- [ ] 7F.7 Test: `maldet --scan-all /var/www/html/`

### 7G: Set Up Apt Repository

- [ ] 7G.1 Install `dpkg-dev`: `apt install -y dpkg-dev`
- [ ] 7G.2 Create directory structure:
  ```
  /var/www/html/apt/pool/m/metamanager/
  /var/www/html/apt/dists/bookworm/main/binary-amd64/
  /var/www/html/apt/dists/bookworm-test/main/binary-amd64/
  ```
- [ ] 7G.3 Generate initial empty `Packages` index
- [ ] 7G.4 Set permissions: `chown -R www-data:www-data /var/www/html/apt/`

### 7H: Set Up Plugin Hosting

- [ ] 7H.1 Create directory: `/var/www/html/metamanager/`
- [ ] 7H.2 Set permissions: `chown -R www-data:www-data /var/www/html/metamanager/`
- [ ] 7H.3 Create `metadata.json` template (version info for WP auto-updates)
- [ ] 7H.4 Test: `curl http://apt.richardkentgates.com/metamanager/metadata.json`

### 7I: Verification

- [ ] 7I.1 Test Apache: `curl http://apt.richardkentgates.com/`
- [ ] 7I.2 Test iptables: `nmap -p 1-1000 34.136.87.92` (only 22, 80, 443 should be open)
- [ ] 7I.3 Test fail2ban: trigger SSH brute force, verify ban
- [ ] 7I.4 Test modsecurity: send scanner UA, verify block
- [ ] 7I.5 Test maldet: `maldet --scan-all /var/www/html/`
- [ ] 7I.6 Check memory usage: `free -h` (should be under 500MB used)

---

## Phase 8: GPG Signing

- [ ] 8.1 Generate GPG keypair on server (or locally, upload private key for CI)
- [ ] 8.2 Configure CI to sign Release files
- [ ] 8.3 Document client setup: import key + add repo with `signed-by`

## Phase 9: CI/CD — Plugin Repo Deploy

- [ ] 9.1 Create `.github/workflows/deploy.yml` in plugin repo
- [ ] 9.2 Generate SSH deploy key, add to server `authorized_keys`
- [ ] 9.3 Add GitHub secrets: `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`
- [ ] 9.4 `test` branch: rsync plugin files to server `wp-content/plugins/metamanager/`
- [ ] 9.5 `main` branch: rsync + `wp plugin activate metamanager`
- [ ] 9.6 Test: push to test branch, verify files land on server

## Phase 10: CI/CD — Daemon Repo Build + Apt Push

- [ ] 10.1 Update `.github/workflows/build-deb.yml` — add upload step
- [ ] 10.2 Add GitHub secrets: `APT_SSH_KEY`, `APT_HOST`, `APT_USER`, `GPG_PRIVATE_KEY`, `GPG_PASSPHRASE`
- [ ] 10.3 `test` branch: build .deb, SCP to `pool/`, regenerate `bookworm-test/Packages`
- [ ] 10.4 `v*` tag: build .deb, SCP to `pool/`, regenerate `bookworm/Packages`, sign Release
- [ ] 10.5 Test: push to test branch, verify .deb appears in apt repo

## Phase 11: End-to-End Testing

- [ ] 11.1 Fresh Debian 13 box: add apt repo, `apt install metamanager`
- [ ] 11.2 Verify daemons running: `systemctl status metamanager-compress-daemon metamanager-meta-daemon`
- [ ] 11.3 Verify plugin installed at `wp-content/plugins/metamanager/`
- [ ] 11.4 Verify plugin activates via WP-CLI
- [ ] 11.5 Test: push to test branch → verify plugin files update on server
- [ ] 11.6 Test: tag release → verify `apt upgrade` pulls new .deb
- [ ] 11.7 Test: upload image → verify compression job runs and completes
- [ ] 11.8 Test: edit metadata → verify write-back to file

## Phase 12: Documentation & Cleanup

- [ ] 12.1 Update both READMEs with complete install/deploy instructions
- [ ] 12.2 Update `CONTRIBUTING.md` in both repos
- [ ] 12.3 Update `ARCHITECTURE.md` in both repos
- [ ] 12.4 Tag `v2.4.0` in both repos
- [ ] 12.5 Update GitHub repo descriptions and topics
- [ ] 12.6 Remove stale branches if any
- [ ] 12.7 Close this roadmap

---

## Notes

- The job queue contract (Phase 6) is the API boundary between the two repos. Both repos reference it.
- The apt repo serves two distributions: `bookworm-test` (test branch builds) and `bookworm` (tagged releases).
- Client machines choose which distribution to subscribe to based on their risk tolerance.
- GPG signing is recommended for production but can be added after initial setup.
