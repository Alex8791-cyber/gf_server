# Deployment Runbook — Block ① Server Foundation

Brings the Grand Fantasia servers online on a fresh Ubuntu 24.04 VPS.

## 0. Prerequisites
- A VPS running **Ubuntu Server 24.04 LTS** with a static public IPv4.
- Root or sudo access.

## 1. Secure the VPS first
- Create a non-root sudo user; log in as that user.
- Set up SSH key authentication, then in `/etc/ssh/sshd_config` set
  `PermitRootLogin no` and `PasswordAuthentication no`; `sudo systemctl restart ssh`.
  **Keep your current session open** until you have confirmed key login works.
- `sudo apt update && sudo apt full-upgrade -y && sudo reboot`.

## 2. Place the repository
```bash
sudo git clone <repo-url> /opt/gfserver
cd /opt/gfserver
```

## 3. Configure
```bash
cp deploy/gfserver.env.example deploy/gfserver.env
nano deploy/gfserver.env        # set HOST_IP; leave DB_PASSWORD empty to auto-generate
```

## 4. Install
```bash
sudo deploy/install.sh
```
Idempotent — safe to re-run. It installs PostgreSQL 16, creates the `gfserver`
user and `gf_app` DB role, imports the schema, patches the World/Zone binaries,
installs the systemd units, and configures `ufw`, `fail2ban`, and unattended
upgrades.

## 5. Verify the public game ports
The defaults (`6543 5560`) may be incomplete. Inspect the database:
```bash
sudo -u postgres psql -d gf_ls -c "SELECT name, ip, port FROM worlds;"
sudo -u postgres psql -d gf_gs -c "SELECT * FROM serverstatus;"
```
Add any additional player-facing ports to `GAME_PORTS` in
`deploy/gfserver.env`, then re-run `sudo deploy/install.sh` (the firewall step
re-applies the rules).
**Never** open the ZoneServer GM port (`10320`) or PostgreSQL (`5432`) to the
internet.

## 6. Start the server
```bash
sudo deploy/gfctl start
deploy/gfctl status
```
Inspect a component's log with `journalctl -u gf-zone -f`.

## 7. End-to-end check
- Point the game client's `connect.ini` at `HOST_IP:6543`.
- Confirm: login succeeds, character list loads, you can enter the world.
- This manual check is the acceptance criterion for Block ①.

## Day-to-day operations
- `sudo deploy/gfctl {start|stop|restart}` — control the server.
- `deploy/gfctl status` — component health.
- `deploy/gfctl backup` — on-demand DB backup (also runs daily at 04:30 via
  `gf-backup.timer`).
- `sudo deploy/gfctl restore <folder>` — restore a backup from `/opt/gfserver/backup/`.

## Troubleshooting
- A component keeps restarting → `journalctl -u gf-<name> -n 50`.
- DB connection errors → confirm `gf_app` works:
  `PGPASSWORD=... psql -h 127.0.0.1 -U gf_app -d gf_gs -c '\dt'`.
- A component fails with an exec/format error → the server binaries are 32-bit.
  A 64-bit Ubuntu kernel normally runs static 32-bit ELF directly. If it does
  not, enable multiarch:
  `sudo dpkg --add-architecture i386 && sudo apt update && sudo apt install -y libc6:i386`.

## Portal (Block 3a)

`install.sh` also installs Apache + PHP-FPM and serves the portal from
`/opt/gfserver/web/public` on port 80.

1. Point your portal domain's DNS A record at the VPS, and set
   `PORTAL_DOMAIN`, `ADMIN_ACCOUNT` and the `GF_MAIL_FROM*` values in
   `deploy/gfserver.env` before running `install.sh`.
2. After install, enable HTTPS:
   `sudo certbot --apache -d <your-portal-domain>`
   certbot adds the TLS virtual host and the HTTP-to-HTTPS redirect.
3. Configure an SMTP relay for outgoing mail (account confirmation and
   password reset). Until a relay is configured, mail sending will fail.
4. Grant additional portal admins at any time:
   `sudo deploy/gfctl add-admin <username>`

Verify: browse to the domain — the portal home page should load.

## Forum (Block 3b)

`install.sh` also installs phpBB 3.3 under `/opt/gfserver/phpbb`, served on
`FORUM_DOMAIN`. Single sign-on is provided by the `gfserver/sso` extension —
players log in with their game account.

1. Before running `install.sh`, set `FORUM_DOMAIN`, `FORUM_DB_PASSWORD` and
   the `FORUM_ADMIN_*` values in `deploy/gfserver.env`, and point the forum
   subdomain's DNS A record at the VPS.
2. Confirm `PHPBB_VERSION` in `install.sh` is the current phpBB 3.3.x security
   release before installing.
3. After install, enable HTTPS for the forum:
   `sudo certbot --apache -d <your-forum-domain>`
4. Verify SSO: open the forum, log in with an existing game account — the
   phpBB user is created automatically on first login.

**phpBB updates:** the phpBB framework under `/opt/gfserver/phpbb` is not part
of this repository. Apply phpBB security releases manually (download the new
version, follow phpBB's update procedure); the `gfserver/sso` extension is
re-deployed from the repo by re-running `install.sh`.
