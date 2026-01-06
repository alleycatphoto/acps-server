# SSH Key Setup for Conphoserv User

## 1. Generate SSH Key Pair (on your dev machine)

```powershell
# Generate a single SSH key for user Conphoserv (used on all 3 servers)
ssh-keygen -t ed25519 -C "conphoserv-acps-servers" -f conphoserv_key
```

**Output:**
- `conphoserv_key` (private key - keep secret!)
- `conphoserv_key.pub` (public key - copy to servers)

---

## 2. Install Public Key on Each Server

**On each server (HAWK, MOON, ZIP), logged in as Conphoserv:**

```powershell
# Create .ssh directory if it doesn't exist
mkdir C:\Users\Conphoserv\.ssh -ErrorAction SilentlyContinue

# Add public key to authorized_keys
# Copy the content of conphoserv_key.pub and run:
Add-Content C:\Users\Conphoserv\.ssh\authorized_keys "ssh-ed25519 AAAAC3Nza... (your public key here)"
```

**Or copy from your dev machine:**
```powershell
# Replace HAWK_IP with actual IP/hostname
$publicKey = Get-Content conphoserv_key.pub
ssh Owner@HAWK_IP "mkdir C:\Users\Owner\.ssh -ErrorAction SilentlyContinue; Add-Content C:\Users\Owner\.ssh\authorized_keys '$publicKey'"
```

---

## 3. Enable SSH Server on Windows Servers

**If SSH isn't working, enable it on each server:**

```powershell
# Install OpenSSH Server (if not already installed)
Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0

# Start and enable SSH service
Start-Service sshd
Set-Service -Name sshd -StartupType 'Automatic'

# Add firewall rule
New-NetFirewallRule -Name sshd -DisplayName 'OpenSSH Server (sshd)' -Enabled True -Direction Inbound -Protocol TCP -Action Allow -LocalPort 22
```

---

## 4. Test SSH Connection

**From your dev machine:**

```powershell
# Test connection to each server
ssh -i conphoserv_key Owner@HAWK_IP
ssh -i conphoserv_key Owner@MOON_IP
ssh -i conphoserv_key Owner@ZIP_IP
```

**Should connect without password prompt.**

---

## 5. Add Private Key to GitHub Secrets

**Read the private key content:**

```powershell
Get-Content conphoserv_key | clip
# Private key is now in clipboard
```

**Add to GitHub:**
1. Go to: https://github.com/built-responsive/ACPS-8.0/settings/secrets/actions
2. Click "New repository secret"
3. **Name:** `CONPHOSERV_SSH_KEY`
4. **Value:** Paste the entire private key (including `-----BEGIN OPENSSH PRIVATE KEY-----` and `-----END OPENSSH PRIVATE KEY-----`)
5. Save

---

## 6. Add Other GitHub Secrets

**Server hostnames/IPs:**
- `HAWK_HOST` - e.g., `192.168.1.10` or `hawk.local`
- `MOON_HOST` - e.g., `192.168.1.11` or `moon.local`
- `ZIP_HOST` - e.g., `192.168.1.12` or `zip.local`

**Server path (same for all):**
- `SERVER_PATH` - `C:\UniserverZ\vhosts\acps`

**AlleyCat token (see previous instructions):**
- `ALLEYCATPHOTO_TOKEN` - Personal Access Token from alleycatphoto account

---

## 7. Troubleshooting

### Permission Denied (publickey)
- Check public key is in `C:\Users\Owner\.ssh\authorized_keys`
- Check file permissions: `icacls C:\Users\Owner\.ssh\authorized_keys`
- Verify SSH service is running: `Get-Service sshd`

### Connection Refused
- Check firewall: `Get-NetFirewallRule -Name sshd`
- Verify port 22 is open
- Check SSH service: `Get-Service sshd`

### Host Key Verification Failed
- Accept host key manually first: `ssh Owner@HAWK_IP` (type "yes")
- Or use `-o StrictHostKeyChecking=no` (GitHub Actions does this)

---

## Security Notes

- **Private key** (`conphoserv_key`): Never commit to git, only store in GitHub Secrets
- **Public key** (`conphoserv_key.pub`): Safe to share, copy to all 3 servers
- **authorized_keys**: Only Owner user should have read/write access
- **Backup**: Save private key securely (password manager, encrypted backup)

---

*One key, three servers, automatic deploys.*
