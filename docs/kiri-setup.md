# Tierza — Local Development with Kiri Container Runtime

This guide explains how to use **Kiri** (https://runkiri.com/) as a lightweight container runtime alternative to Docker Desktop for local Tierza development on Windows (WSL2).

---

## 1. Why Kiri?

Kiri is a minimal, developer-focused container engine running on a dedicated Alpine WSL2 instance. In comparison to Docker Desktop:

| Metric | Docker Desktop | Kiri | Improvement |
| :--- | :--- | :--- | :--- |
| **Idle Host Memory (RAM)** | ~500 MB – 1.2 GB GUI/Service | ~33 MB (`kiri-win.exe`) | **> 90% reduction** |
| **WSL VM Memory (6 containers)** | ~2.5 GB – 4.0 GB (`vmmem`) | ~1.17 GB (`vmmemWSL`) | **~60% reduction** |
| **Base Disk Footprint** | 10.46 GB (3.2 GB app + 6.5 GB VHDX) | ~286 MB base (108 MB VHDX) | **97.3% base footprint reduction** |
| **Active wp-env Disk Footprint** | ~16 GB | ~6.4 GB (all images & layers) | **~60% total disk reduction** |

---

## 2. Prerequisites & Installation

### Prerequisites
- Windows 11 / 10 64-bit with WSL2 enabled.
- WSL kernel `6.x` or higher.

### Installation
1. Download and install Kiri from [runkiri.com](https://runkiri.com/) or via the installer.
2. Launch Kiri (`kiri-win.exe`). It initializes an Alpine Linux WSL distribution named `kiri`.
3. To ensure the Kiri daemon automatically boots whenever WSL initializes, add the boot command to `/etc/wsl.conf` inside the `kiri` distribution:
   ```powershell
   wsl -d kiri -u root sh -c "grep -q 'kiri-start' /etc/wsl.conf || printf '\n[boot]\ncommand = /usr/local/bin/kiri-start\n' >> /etc/wsl.conf"
   ```
4. Verify Kiri is running:
   ```powershell
   wsl -l -v
   # Output should show 'kiri' with State 'Running' and Version 2
   ```

---

## 3. Docker CLI & Context Configuration

Kiri provides a standard Docker-compatible API over a Windows named pipe (`\\.\pipe\kiri`).

### Context Management
To switch between Kiri and Docker Desktop:

```powershell
# Set Kiri as the active Docker context
docker context use kiri

# Verify active context
docker context ls
# Expected output:
# NAME            TYPE         DESCRIPTION                               DOCKER ENDPOINT
# default         moby         Current default context                   npipe:////./pipe/docker_engine
# kiri *          moby         npipe:////./pipe/kiri                     npipe:////./pipe/kiri

# Switch back to Docker Desktop if needed
docker context use default
```

### Buildx Builder Instance
Kiri manages its own Buildkit node (`kiri-build0`):
```powershell
docker buildx use kiri
```

---

## 4. Running WordPress Environment (`@wordpress/env`)

Tierza's standard npm commands work seamlessly under Kiri once the active context is `kiri`:

```powershell
# 1. Ensure Kiri is active
docker context use kiri

# 2. Start the local environment
npm run env:start
# Starts WordPress on http://localhost:8888 and Tests on http://localhost:8889

# 3. Stop the environment
npm run env:stop

# 4. Clean and reinitialize if needed
npm run env:clean
```

---

## 5. Environment Reproducibility & Cold Rebuilds

### Understanding wp-env Image Caching
`@wordpress/env` generates temporary Dockerfiles in `~/.wp-env/<config-hash>/` based on its built-in templates.
- **Normal Workflow:** Once images are built in Kiri (`...-wordpress`, `...-tests-wordpress`, `...-cli`, `...-tests-cli`), `npm run env:start` reuses the local cached images directly. Startup takes ~3–11 seconds without touching or rebuilding Dockerfiles.
- **Data Persistence:** WordPress uploads, MariaDB databases, and Tierza plugin mounts reside in Docker named volumes and local mounts. Stopping `wp-env` or restarting Kiri preserves all posts, products, and configurations.

### Cold Rebuilds in Restricted Network Environments
In restricted network environments where external endpoints (`composer.github.io` or `repo.packagist.org`) may encounter timeouts during container builds, follow this reproducible cold-build procedure:

1. **Pre-cache base images:**
   ```powershell
   docker pull wordpress:latest
   docker pull wordpress:cli
   docker pull mariadb:lts
   docker pull composer:latest
   ```

2. **Trigger image build with local composer copy if needed:**
   If `@wordpress/env` triggers a fresh build from scratch and external signature fetching times out, adjust the generated Dockerfile lines 41–44 to transfer composer from the pre-cached image:
   ```dockerfile
   COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
   ```
   This eliminates external Composer network dependencies without modifying `node_modules` or altering repository code.

---

## 6. Known Issues & Workarounds

### 1. Port 53 / UDP DNS Collision (`Address in use os error 98`)
- **Symptom:** Starting containers or bridge networks returns `dns: dns bind: Address in use (os error 98)`.
- **Cause:** A standalone containerized Buildkit daemon (e.g., `buildx_buildkit_default`) bound UDP port 53 on `172.19.0.1`, colliding with Kiri's embedded bridge DNS resolver.
- **Resolution:**
  ```powershell
  docker rm -f buildx_buildkit_default
  docker buildx use kiri
  ```

### 2. WSL Distribution Daemon Boot
- **Symptom:** `error during connect: open //./pipe/kiri: The system cannot find the file specified` after Windows reboot.
- **Resolution:** Ensure `/usr/local/bin/kiri-start` is configured in `/etc/wsl.conf` under `[boot]` (see Section 2).

---

## 7. Verification & Health Checks

Run the following checks to verify full stack health:

```powershell
# 1. Check container status (6 containers should be Up)
docker ps

# 2. Test HTTP response
Invoke-WebRequest -Uri "http://localhost:8888" -UseBasicParsing

# 3. Run full real MariaDB integration tests (69 tests)
npm run test:db

# 4. Run Playwright E2E browser tests (12 tests)
npm run test:e2e
```
