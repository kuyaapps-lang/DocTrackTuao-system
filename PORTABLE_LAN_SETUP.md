# Portable LAN HTTPS setup

This guide runs DocTrack on a Windows/XAMPP laptop that may move between home,
office, and trusted mobile-hotspot networks. It is intentionally **LAN-only**:
do not configure router port forwarding, public DNS, Cloudflare Tunnel, or a
public firewall rule.

The production path is:

```text
Trusted phone or PC -- HTTPS :443 --> Caddy -- loopback :8080 --> Apache --> Laravel public/
```

Laravel retains responsibility for authentication, RBAC, office scope, document
workflow, attachments, and QR validation. Caddy only provides HTTPS and reverse
proxies requests. MySQL remains local to the server and is never exposed.

## Reversibility and rollback

This setup is designed to be reversible. It does not require database changes,
migrations, new users, `APP_KEY` changes, or a public network service. Do not
overwrite XAMPP configuration files: make timestamped copies first and keep the
Caddyfile outside this repository.

Before any machine-level change, back up these files in their existing folders:

```powershell
Copy-Item C:\xampp\apache\conf\httpd.conf C:\xampp\apache\conf\httpd.conf.before-doctrack-lan-YYYYMMDDHHMMSS.bak
Copy-Item C:\xampp\apache\conf\extra\httpd-vhosts.conf C:\xampp\apache\conf\extra\httpd-vhosts.conf.before-doctrack-lan-YYYYMMDDHHMMSS.bak
Copy-Item .env .env.before-doctrack-lan-YYYYMMDDHHMMSS.bak
```

Keep the backup filenames out of Git. Do not store copied Caddy certificates or
private keys in the project.

If the LAN setup is unsuitable, roll back in this order:

1. Stop the Caddy Windows service/process using the same local service method
   used to start it. Because the example disables Caddy's network admin API,
   do not expect remote administration to be available.
2. Restore the two Apache configuration backups, restart Apache, and verify the
   original local DocTrack URL works.
3. Remove the local DNS entry for `doctrack.home.arpa` from the router/DNS
   server (or undo any temporary hosts-file entries).
4. Remove only the firewall rule created by this guide:

   ```powershell
   Remove-NetFirewallRule -DisplayName 'DocTrack Caddy HTTPS (Private LAN)'
   ```

5. Restore the prior `.env` values for `APP_URL`, `TRUSTED_HOSTS`, and
   `SESSION_SECURE_COOKIE`, then run `php artisan config:clear` and
   `php artisan cache:clear`.
6. Remove the Caddy root certificate from a client **only after confirming it
   is not used by another internal Caddy service**. Identify it by the local
   Caddy issuer/subject in that device's certificate manager; never remove an
   unrelated organization or system root certificate.

The repository files can be removed later in a focused Git change. Do not use
`git reset --hard` or restore the full scanner component, because this working
tree already contains unrelated uncommitted work.

## Before changing anything

1. Confirm the current machine and keep a known-good database backup. Do not
   restore, migrate fresh, replace `.env`, or regenerate `APP_KEY` for this
   setup.
2. Build the client assets from the project root:

   ```powershell
   npm run build
   ```

   Clients use Laravel's built files from `public/build`; they must not connect
   to Vite's development server.
3. Copy—not move—`deploy/Caddyfile.example` and
   `deploy/apache/doctrack-lan.conf.example` to your local server configuration
   area. Keep any Caddy data, certificates, and private keys outside Git.

After the HTTPS hostname is working, an administrator must update the existing
server's `.env` manually (do not copy `.env.example` over it):

```dotenv
APP_URL=https://doctrack.home.arpa
TRUSTED_HOSTS=localhost,127.0.0.1,::1,doctrack.home.arpa
SESSION_SECURE_COOKIE=true
```

Then clear Laravel's cached configuration:

```powershell
php artisan config:clear
php artisan cache:clear
```

`TRUSTED_HOSTS` is required because the application deliberately rejects
unrecognized Host headers. `SESSION_SECURE_COOKIE` keeps any session cookies
HTTPS-only; protected API calls continue to use the existing Sanctum Bearer
token flow and are not being converted to cookie authentication.

## Stable local name

Use `doctrack.home.arpa` as the service name. Do not use a raw IP address as
the normal HTTPS URL: a laptop receives a new address when it joins another
network. Avoid `.local` unless you deliberately operate mDNS/Bonjour.

Each trusted network must resolve this name to the laptop's current private IP.
The preferred approach is a local DNS entry on that network's router/DNS server:

```text
doctrack.home.arpa -> 192.168.x.x  (the laptop's current private IPv4 address)
```

When changing home, office, or hotspot, update that local DNS record to the new
address. The name and certificate remain the same. The helper below displays the
current address, but does not alter DNS or firewall state:

```powershell
.\start-doctrack-lan.ps1
```

For a Windows-only temporary test, an administrator can add the same mapping to
the client computer's hosts file. This is not practical for phones; use trusted
router DNS for normal mobile access.

## Configure Apache (loopback only)

1. Back up XAMPP's Apache configuration before editing it.
2. In Apache's **main configuration**, replace any LAN-facing `Listen 80` with
   `Listen 127.0.0.1:80`. Do not leave an Apache `Listen 0.0.0.0:80`, generic
   `Listen 80`, or direct Apache HTTPS listener enabled for LAN access. Then
   add `Listen 127.0.0.1:8080` for this virtual host.
3. Adapt and include `deploy/apache/doctrack-lan.conf.example`.
4. Verify its `DocumentRoot` points to **this project's `public` folder**, not
   the repository root.
5. Restart Apache, then from the server verify:

   ```powershell
   Invoke-WebRequest http://127.0.0.1:8080 -UseBasicParsing
   ```

Apache uses `Require local`; direct LAN clients must not be able to use port
8080. Caddy is the only LAN entry point.

## Configure Caddy local HTTPS

1. Download Caddy for Windows from its official site and place it in a
   protected local location. Caddy itself is free and Apache-2.0 licensed.
   Paid support is optional; no subscription or paid feature is required.
2. Copy the example Caddyfile outside the repository and validate it:

   ```powershell
   caddy validate --config C:\ProgramData\Caddy\Caddyfile --adapter caddyfile
   ```

3. Run Caddy initially from an elevated PowerShell so it can create/use its
   local CA and bind HTTPS, then configure a Windows service only after testing:

   ```powershell
   caddy run --config C:\ProgramData\Caddy\Caddyfile --adapter caddyfile
   ```

4. Caddy attempts to trust its root certificate on the server when it starts.
   If it cannot, run:

   ```powershell
   caddy trust
   ```

   `caddy trust` changes the server trust store and normally needs administrator
   approval. It does **not** trust phones or other PCs. For device deployment,
   export/copy only the public `root.crt` from the configured Caddy data
   directory: `C:\ProgramData\Caddy\data\pki\authorities\local\root.crt`.
   Never copy `root.key` or any private key to a client.

5. After the localhost HTTPS check passes, install the validated configuration
   as the automatic Windows service `DocTrackCaddy`. The service must use the
   copied binary and Caddyfile in `C:\ProgramData\Caddy` so that its persistent
   CA directory remains `C:\ProgramData\Caddy\data`. Verify its status with:

   ```powershell
   Get-Service DocTrackCaddy
   ```

5. Browse `https://doctrack.home.arpa` from the server. A valid, trusted page
   confirms the Caddy-to-Apache path.

Never click through a certificate warning and never disable certificate checks.
If a warning appears, stop and fix DNS or certificate trust.

## Trust authorized devices

Caddy's `tls internal` root CA must be securely copied to and trusted by each
authorized device. Treat the root certificate as an administrative asset; do
not share it publicly and do not install it on untrusted devices.

### Windows PC

An administrator imports the Caddy root certificate into **Local Computer >
Trusted Root Certification Authorities**. Restart the browser and verify the
certificate chain before signing in.

### Android phone

Use Android Settings to install the CA certificate, then open the HTTPS URL in
Chrome and verify there is no certificate warning before allowing camera access.
Device-management policies can block user CAs; in that case an administrator
must provide an approved device-management/root-certificate process. Do not
bypass the warning.

### iPhone/iPad

Install the certificate as a profile, then explicitly enable full trust in
**Settings > General > About > Certificate Trust Settings**. Open the HTTPS URL
in Safari and verify its certificate before allowing camera use.

## Windows Firewall (manual, administrator approval required)

Create one inbound rule for Caddy:

```powershell
New-NetFirewallRule -DisplayName 'DocTrack Caddy HTTPS (Private LAN)' `
  -Direction Inbound -Action Allow -Protocol TCP -LocalPort 443 -Profile Private
```

Do **not** create inbound rules for MySQL (3306), Apache (8080), Laravel's
development server (8000), or Vite (5173). Set every public/untrusted Wi-Fi or
hotspot network to Windows' **Public** profile; the rule above will not apply.

## Temporary HTTP diagnostic server

For short trusted-network diagnostics only:

```powershell
.\start-doctrack-lan.ps1 -DevelopmentServer
```

It runs `php artisan serve --host=0.0.0.0` and prints the temporary HTTP URL.
Do not use it for regular operation, do not open it through the firewall, and do
not expect live mobile camera scanning: mobile browsers require a secure context
for `getUserMedia` on a LAN address. Use Caddy HTTPS instead.

## Mobile QR camera checklist

- [ ] Phone is connected to the trusted local network; client isolation is off.
- [ ] `doctrack.home.arpa` resolves to the current server IP.
- [ ] The Caddy root certificate is trusted by the phone browser with no warning.
- [ ] The phone opens `https://doctrack.home.arpa` successfully.
- [ ] Camera permission is allowed for this site.
- [ ] The scanner opens and prefers the rear camera.
- [ ] A valid QR retrieves the expected document or runs its existing permitted workflow.
- [ ] An invalid/void/unavailable QR shows the existing error message.
- [ ] Closing or scanning stops the camera indicator/stream.
- [ ] A wrong-role or wrong-office operation is rejected by the existing API.

## Required manual verification

Repository checks cannot verify a physical network, router DNS, phone certificate
trust, browser camera permission, or Wi-Fi isolation. Test these manually at
each home/office network before relying on the system:

1. Server, Windows client, Android, and iPhone access the HTTPS URL.
2. Login/logout works and API calls stay same-origin (`/api/...`).
3. Authorized document retrieval, attachment authorization, QR scan, and
   forwarding/receipt behavior still respect existing role and office rules.
4. Change to the other network, update its local DNS record, and repeat the
   HTTPS/camera test.
5. Confirm ports 3306, 8080, 8000, and 5173 are unreachable from another LAN
   device.

## Troubleshooting

| Symptom | Check |
|---|---|
| Name does not open | Verify local DNS resolves `doctrack.home.arpa` to the current private IPv4 address. |
| Certificate warning | Stop. Verify the hostname, root CA installation, and browser/device policy; do not continue through the warning. |
| Caddy returns 502 | Verify Apache is running on `127.0.0.1:8080` and the virtual-host path is correct. |
| Camera button asks for a photo | The page is not a trusted HTTPS context, or the browser cannot provide live camera access. Fix HTTPS/certificate trust first. |
| Camera is blocked | Allow camera permission for the HTTPS hostname; close other apps using the camera. |
| Device cannot connect | Confirm it is on the same trusted network, client isolation is disabled, and only port 443 is permitted on the Private firewall profile. |
