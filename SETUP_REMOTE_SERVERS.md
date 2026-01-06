# Remote Server Auto-Update Setup Guide

## Overview
**Dual-Repository Workflow:**
- **Dev Repo:** https://github.com/built-responsive/ACPS-8.0 (paid account, you push here)
- **Production Fork:** https://github.com/alleycatphoto/acps-server (auto-mirrored)

**When you push to built-responsive/ACPS-8.0:**
1. GitHub Actions mirrors changes to alleycatphoto/acps-server
2. 3 remote servers auto-pull from alleycatphoto/acps-server:
   - **Hawks Nest** - `C:\UniserverZ\vhosts\acps` (user: Conphoserv)
   - **Hawk Moon** - `C:\UniserverZ\vhosts\acps` (user: Conphoserv)
   - **Zip** - `C:\UniserverZ\vhosts\acps` (user: Conphoserv)

---

## Initial Setup on Each Remote Server

### 1. Install Git on Each Server
On each Windows server:
```powershell
# Download Git for Windows: https://git-scm.com/download/win
# Or via Chocolatey:
choco install git -y
```

### 2. Clone the Repo on Each Server
On Hawks Nest (and each location):
```powershell
cd C:/UniServerZ/vhosts
git clone https://github.com/alleycatphoto/acps-server.git acps
cd acps
git checkout main
composer install --no-dev --optimize-autoloader
```

### 3. Setup SSH Access (for GitHub Actions)
On your **dev machine**, generate a single SSH key for user Conphoserv:
```powershell
# Generate single key for all 3 servers (user: Conphoserv)
ssh-keygen -t ed25519 -C "conphoserv-servers" -f conphoserv_key
```

Copy the **public key** to all 3 servers:
```powershell
# On each server (Hawks Nest, Hawk Moon, Zip), logged in as Conphoserv:
mkdir C:\Users\Conphoserv\.ssh -ErrorAction SilentlyContinue
Add-Content C:\Users\Conphoserv\.ssh\authorized_keys (Get-Content conphoserv_key.pub)
```

---

## GitHub Secrets Configuration

Add these secrets at: https://github.com/alleycatphoto/acps-server/settings/secrets/actions

### Hawks Nest Secrets:
- `HAWKSNEST_HOST`: IP address or hostname (e.g., `192.168.1.100` or `hawksnest.local`)
- `HAWKSNEST_USER`: Windows username (e.g., `Administrator`)
- `HAWKSNEST_PATH`: `C:\\UniserverZ\\vhosts\\acps`
- `HAWKSNEST_SSH_KEY`: Contents of `hawksnest_key` (private key)
built-responsive/ACPS-8.0/settings/secrets/actions

### Mirror Secrets:
- `ALLEYCATPHOTO_TOKEN`: Personal Access Token from alleycatphoto account
  - Go to: https://github.com/settings/tokens
  - Create token with `repo` scope
  - Allows pushing to alleycatphoto/acps-server

### Server Secrets:
- `HAWKSNEST_HOST`: IP or hostname (e.g., `192.168.1.100` or `hawksnest.local`)
- `HAWKMOON_HOST`: IP or hostname (e.g., `192.168.1.101` or `hawkmoon.local`)
- `ZIP_HOST`: IP or hostname (e.g., `192.168.1.102` or `zip.local`)
- `SERVER_PATH`: `C:\\UniserverZ\\vhosts\\acps` (same for all 3)
- `CONPHOSERV_SSH_KEY`: Contents of `conphoserv_key` (private key for all 3 servers)
2. GitHub Actions workflow triggers (`.github/workflows/deploy.yml`)
3. GitHub SSH's into each server and runs: `git pull origin main`
4. Each server updates its local code automatically

### Manual (PowerShe**built-responsive/ACPS-8.0**: `git push origin main`
2. GitHub Actions workflow triggers (`.github/workflows/deploy.yml`):
   - **Step 1:** Mirrors code to **alleycatphoto/acps-server**
   - **Step 2:** SSH's into Hawks Nest, Hawk Moon, Zip
   - **Step 3:** Each server runs: `git pull origin main` (from alleycatphoto/acps-server)
3. All 3 servers update automatically as user **Conphoserv**
.\deploy.ps1 all on servers:
```powershell
# Trigger all 3 servers to pull
.\deploy.ps1 all

# Trigger individual servers
.\deploy.ps1 hawksnest
.\deploy.ps1 hawkmoon
.\deploy.ps1 zip
```

**Note:** Manual script SSH's directly to servers. Mirror to alleycatphoto happens via GitHub Actions only.
## Configuration File

Edit `deploy.config.json` to update server details:
```json
{
  "servers": {Conphoserv",
      "path": "C:\\UniserverZ\\vhosts\\acps"
    },
    "hawkmoon": {
      "host": "hawkmoon.local",
      "user": "Conphoserv",
      "path": "C:\\UniserverZ\\vhosts\\acps"
    },
    "zip": {
      "host": "zip.local",
      "user": "Conphoserv
    "location3": {
      "host": "192.168.3.100",
      "user": "Administrator",
      "path": "C:\\UniserverZ\\vhosts\\acps"
    }
  }
}
```

---

## Troubleshooting

### Server Won't Pull
2. Verify SSH access: `ssh Conphoserv@hawksnest.local`
3. Check GitHub Actions logs: https://github.com/built-responsive/ACPS-8.0/actions
4. Verify alleycatphoto fork is synced: https://github.com/alleycatphoto/acps-server
3. Check GitHub Actions logs: https://github.com/alleycatphoto/acps-server/actions

### Merge Conflicts on Server
If a server has local changes:
```powershell
cd C:\UniserverZ\vhosts\acps
git stash
git pull origin main
git stash pop
```

### SSH Permission Denied
- Verify public key is in `~/.ssh/authorized_keys` on the server
- Check SSH service is running: `Get-Service sshd`
- Windows 10/11: Enable OpenSSH Server in Windows Features

---

## Security Notes

- **Private keys**: Never commit `*_key` files to git (only `.pub` public keys)
- **GitHub Secrets**: Store private keys in GitHub Secrets only
- **SSH Access**: Limit SSH access to specific IPs if possible
- **.env files**: Not transferred (excluded in git). Each server needs its own `.env`

---

## Testing

# Make a small change in built-responsive repo
echo "// test" >> README.md
git add README.md
git commit -m "Test auto-deploy and mirror"
git push origin main

# Check GitHub Actions: https://github.com/built-responsive/ACPS-8.0/actions
# Verify mirror: https://github.com/alleycatphoto/acps-server (should have test commit)
# SSH into Hawks Nest and verify: ssh Conphoserv@hawksnest.local "cd C:/UniServerZ/vhosts/acps && git log"
# Check GitHub Actions: https://github.com/alleycatphoto/acps-server/actions
# SSH into Hawks Nest and verify: cd C:\UniserverZ\vhosts\acps && git log
```

## Summary

**Your Workflow:**
1. Develop on `v2.acps.dev` (Florida)
2. Commit to `built-responsive/ACPS-8.0` (paid GitHub)
3. Push to `main`
4. **Auto-magic:**
   - Code mirrors to `alleycatphoto/acps-server`
   - Hawks Nest pulls latest
   - Hawk Moon pulls latest
   - Zip pulls latest
5. All 3 servers running as user **Conphoserv**

*No manual FTP. No zip files. Just code and deploy

*Babe, now every push updates all 3 locations automatically. No manual FTP, no zips.*
