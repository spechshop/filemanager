# LIPC — PHP Swoole + Node PTY WebSocket Server

> **📝 Note**: SSL certificates for localhost are **automatically generated** on first run. No manual certificate configuration needed for local development.

## Overview

LIPC is a modern web-based IDE and file management system built with PHP Swoole and Node.js. It combines a high-performance WebSocket server with a pseudo-terminal (PTY) interface, providing a complete development environment accessible through a web browser.

The system features:
- **Real-time code editing** with Monaco Editor
- **AI-powered code completion** using GitHub Copilot integration
- **Integrated terminal** sessions via WebSocket
- **File management** with compression/decompression support
- **Code refactoring** and formatting tools
- **PHP IntelliSense** with code analysis and symbol resolution
- **Multi-tab editing** with persistent sessions

## Stack

### Backend
- **Language**: PHP 8.3+ (latest security patch)
- **Runtime/Extension**: Swoole (WebSocket server, coroutines)
- **Package Manager**: Composer
- **Dependencies**:
  - `nikic/php-parser` - PHP code parsing and analysis
  - `symfony/translation-contracts` - Translation support
  - `varunsridharan/php-classmap-generator` - Classmap generation
  - `soyhuce/classmap-generator` - Classmap generation
- **Dev Dependencies**:
  - `phpstan/phpstan` - Static analysis tool

### Node.js Services
- **Runtime**: Node.js 22+
- **Package Manager**: npm
- **Dependencies**:
  - `ws` - WebSocket server
  - `node-pty` - Pseudo-terminal sessions
  - `express` - HTTP server framework
  - `puppeteer` - Headless browser automation
  - `puppeteer-core` - Puppeteer core without bundled browser
  - `puppeteer-extra` + `puppeteer-extra-plugin-stealth` - Enhanced Puppeteer with stealth mode

### Frontend
- **Monaco Editor** - VS Code-like editor
- **jQuery** - DOM manipulation
- **Xterm.js** - Terminal emulator
- **Bootstrap** - UI framework
- **Custom themes**: Nightfall, PHPStorm-inspired

## Key Entry Points

### PHP Server
- **`server.php`** - Supervisor process that restarts middleware in a loop
- **`middleware.php`** - Main Swoole WebSocket server
  - Loads config from `plugins/configInterface.json`
  - Starts WebSocket server on configured host/port (default: 0.0.0.0:8080)
  - Auto-spawns Node PTY service on port 6060
  - Hooks plugin handlers: `\plugins\server::open`, `::message`, `\plugins\Start\server::start`, `\plugins\Request\server::request`

### Node.js Services
- **`pty.js`** - PTY WebSocket server (port 6060)
  - Manages one shell session per WebSocket client
  - Persists terminal IDs in `terminals.json`
  - Session files stored in `files/`
- **`gpt.js`** - Puppeteer-based service (port 3090)
  - AI/GPT integration for code refactoring
- **`codex-agent.js`** - Official Codex app-server bridge (port 3091, loopback only)
  - Streams task progress, approvals, commands and diffs to the File Manager
  - Uses ChatGPT Business/Enterprise `CODEX_ACCESS_TOKEN`; it does not require an API key

### Frontend
- **`index.html`** - Main application entry point
- **`plugins/Request/modules/modalEditCode.html`** - Code editor interface with:
  - Multi-tab file editing
  - AI-powered autocomplete
  - Integrated terminal
  - File tree browser

## Requirements

### System
- PHP 8.3+ (latest security patch) (CLI)
- Swoole extension (matching your PHP version)
- Composer
- Node.js 22+ and npm (the installer can provision an isolated compatible runtime)
- Codex CLI when the Codex Agent service is enabled (installed automatically)

### Optional
- Git (for `importGit` functionality)

### Verification
```bash
# Check PHP version
php -v

# Check Swoole extension
php -m | grep swoole

# Check Node.js version
node -v
```

## Installation

### Quick Install

Run the installer directly with:

```bash
curl -fsSL https://raw.githubusercontent.com/spechshop/filemanager/refs/heads/newterm/installer.sh | bash
```

### 1. Clone Repository

Obtain a checkout over verified TLS and review its revision and installation scripts:

```bash
git -c http.sslVerify=true clone --branch newterm https://github.com/spechshop/filemanager.git
cd filemanager
git log -1 --oneline
# Review installer.sh and scripts/ before running the installer.
bash ./installer.sh
```

Do not disable certificate validation. Fix the CA bundle or configure your
organization's trusted CA. Executable releases have checked SHA256 digests;
Node's checksums are retrieved over verified HTTPS. Non-Git source archive
fallbacks require FILEMANAGER_SOURCE_SHA256 / FILEMANAGER_LIBSPECH_SHA256.
The standalone install-tmp.sh requires FILEMANAGER_ARCHIVE_SHA256 from an
independently reviewed archive before executing anything from that archive.

See [the hardening design](docs/security/hardening-design.txt) and
[deployment requirements](docs/security/hardening-deployment.txt) before production.
Linux >= 5.6 and Python >= 3.9 are required for descriptor-based filesystem IO.
Node.js >= 22 is required by the current bridges.

The installer keeps its PHP/Swoole runtime isolated under the `pcg` command;
it does not replace the system `php` binary.

The installer adds `filemanagerctl` and chooses system systemd, user systemd
with linger, or a cron-backed watchdog. PHP crashes recover after about two
seconds while supervision is active. `stop` holds the server down for the
current boot; `disable` also prevents startup after future reboots. Independent
installations use separate state and exclusive locks.

It also verifies the Node.js version. If the system runtime is older than 22,
it downloads a checksum-verified Node.js 24 LTS runtime into `.runtime/`, installs
the project dependencies, and installs the official `@openai/codex` CLI without
replacing the system-wide Node.js. The same repair is available later under
**Configurações > Diagnóstico**, where Node.js 22, 24 or 26 can be selected.

```bash
filemanagerctl status
filemanagerctl stop       # stop now, but keep autostart enabled
filemanagerctl start
filemanagerctl restart
filemanagerctl disable    # stop now and disable autostart
filemanagerctl enable
./filemanagerctl update   # replace an older system controller with this version
```

If the command could not be added to `PATH`, run `./filemanagerctl` from the
installation directory. Run `./filemanagerctl update` once when the command in
`/usr/local/bin` is an older copy; it is replaced by a link to the controller in
this installation. System services require root or an authorized noninteractive
sudo rule. To update supervision in an existing checkout without installing
packages or changing application data, run:

```bash
bash ./installer.sh --supervision-only
```

Existing administrative stops and disabled autostart are preserved during this
update. See [supervision, state, limitations and reboot validation](docs/lifecycle.md)
for the architecture and isolated lifecycle tests. The latest local results are
recorded in [lifecycle validation](docs/lifecycle-validation.md).

### 2. Install PHP Dependencies
```bash
composer install
```

### 3. Install Node.js Dependencies
```bash
npm install
```

### Managed terminal profile

Both terminal backends load `scripts/terminal-profile.sh` automatically. In a
new FileManager terminal, use `node`, `npm`, `npx`, `php`, `pcg` and `composer`
by name. The launchers use the managed runtimes even on a `noexec` home mount;
npm remains beside its libraries instead of being copied alone to `/dev/shm`.
Missing temporary launchers and Node copies are recovered automatically.

`npm install -g` uses this installation's `.runtime/npm-global` prefix. Its
JavaScript/shell commands and project `node_modules/.bin` commands become
available by name; npm scripts and npx use the same runtime handling. Native
addons and executables still need compatible libraries and executable storage.

For custom aliases and environment variables, create `.filemanager-profile`
in the project root. It is ignored by Git and preserved on reinstall. The
account's `.bashrc` is loaded first; no account profile is rewritten. These
settings apply to FileManager terminals, not unrelated SSH sessions. After
updating, restart FileManager and open a new terminal to load the profile.

### 4. SSL Certificates (Auto-generated)
SSL certificates for localhost are **automatically generated** on first run. No manual configuration needed for local development.

For production, update certificate paths in `plugins/configInterface.json`.

## Configuration

### Application Config: `plugins/configInterface.json`

```json
{
  "host": "0.0.0.0",
  "port": 8080,
  "ssl": ".",
  "fileManager": {
    "autoRestart": true,
    "services": {
      "pty": true,
      "lsp": true,
      "gpt": false,
      "codex": true
    }
  },
  "serverSettings": {
    "worker_num": 1,
    "max_request": 20000000,
    "max_coroutine": 20000000,
    "enable_coroutine": true,
    "enable_static_handler": false,
    "http_compression": false,
    "open_http2_protocol": true,
    "ssl_protocols": 96,
    "ssl_ciphers": "ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256",
    "open_tcp_nodelay": true,
    "tcp_fastopen": true,
    "enable_reuse_port": true,
    "ssl_cert_file": "fullchain.pem",
    "ssl_key_file": "privkey.pem"
  }
}
```

### Remote tunnels

Open **Configurações > Túneis** with an administrator token, enter the remote
service's local host (for example `127.0.0.1`) and port (`8080`), and select HTTP
or HTTPS. A host such as `https://localhost` also selects HTTPS. Create the tunnel
and copy its single-line command to a terminal on the other machine.

Select **Cloudflare** for a temporary provider URL or **Direto** for a fixed
port on the host running File Manager. Existing tunnels continue using Cloudflare.

In **Direto** mode, choose an unused port from `1024` to `65535` (for example
`18080`). Paste the command on the remote machine; **no sudo, npm or manual
Node.js installation is needed**. A compatible Node.js already in PATH is reused.
Otherwise the launcher automatically downloads a pinned Node.js runtime into the
tunnel's private temporary directory and verifies its SHA256 before execution.
It downloads through File Manager's verified cache first, with a fallback to the
runtime's original HTTPS host. Linux and macOS binaries are selected by platform;
Linux musl and older x64 glibc have compatible variants. Bash, curl and ordinary
system tools (tar/gzip and a SHA256 utility) bootstrap this setup. No system
packages, shell profiles or global Node.js installation are modified. The private
runtime and credentials are removed when the tunnel stops; its log is retained.
Downloads run in the background with authenticated heartbeats, so closing the
terminal or a long download does not expire the setup. The agent
opens an outbound WebSocket directly to the File Manager address. File Manager
listens on the selected port and forwards TCP streams to the remote service.
For example, `http://panel.example:8080` with port `18080` exposes an HTTP service
at `http://panel.example:18080`. The port follows File Manager's bind address;
a loopback-only installation remains local. Allow this port through the host
firewall and forward it through NAT if applicable. A reverse proxy serving the
panel must also forward WebSocket upgrades for `/fileManagerTunnelDirect`;
the service port must reach File Manager directly.

The direct address stays the same when reconnecting, without a provider expiry.
The agent retries temporary transport/server failures indefinitely, with delays
from 2 to 30 seconds. A server restart with the same address and persisted tunnel
registry does not require a new command. Revocation, session replacement and a
changed pinned TLS key still stop it. If Node crashes, the launcher allows three
restarts per execution, waiting 2, 4 and 8 seconds, then reports an error and
stops. Logs include exit status/signal and available cgroup OOM-counter evidence;
status 137 alone is not proof of an OOM. This applies to agents launched from the
updated File Manager, not already-running copies.
Raw TCP forwarding preserves paths, binary uploads, HTTP streaming,
WebSocket and SSE. HTTPS is passed through using the **remote service's**
certificate, which must be valid for the public hostname; the local self-signed
checkbox applies to Cloudflare mode. The service is accessible to anyone who can
reach that port, so use authentication in the exposed application. Stopping or
deleting the tunnel, rotating its command or revoking its owner closes the
listener and active streams. The command runs in the background and prints a
private log path, just like Cloudflare mode.

The generated command uses Bash and curl to download
`http://FILEMANAGER_IP:PORT/tunnel/KEY`. It prints a connection message before
the request, verifies that the response contains the complete File Manager agent,
and only then runs it. Empty responses, proxy/login pages, interrupted transfers
and revoked or already connected commands show an error and return a nonzero
status, without executing or printing the response body. Direct mode also shows
the first seconds of preparation output before returning to the prompt; further
progress is recorded at the printed private log path.
Its IP/domain, port and protocol come from the address used to open the panel,
so each installation generates its own address automatically. The configured
`127.0.0.1:8080` is the service **on the machine where you run the command**.
In Cloudflare mode, the agent needs only Bash and curl, without Python, Node.js,
jq or sudo; the File Manager address distributes the agent and receives status.

On Linux and macOS the script downloads the official Cloudflare `cloudflared`
executable into a temporary directory and verifies its SHA256 against the pinned
official release manifest in `scripts/tunnel-cloudflared.json`. An existing
`cloudflared` in PATH is reused. Downloads first use the File Manager, which
caches verified official binaries privately under `.runtime/tunnel-binaries/`
(or `FILEMANAGER_DATA_DIR/tunnel-binaries/`). This lets remote machines download
without direct GitHub access. Transient errors such as HTTP 504 are retried;
if downloading through the panel fails, the agent tries GitHub directly.
Both sources require the pinned SHA256 before execution.
The launcher starts the agent in the background
with `nohup`, prints its PID and private log path, and releases the terminal.
The tunnel keeps running after the terminal or SSH session closes. Use
**Desconectar** in the panel to stop it. It is not registered as a boot service.
Temporary credentials and executables are removed when the agent stops; its
private `agent.log` remains at the printed path for troubleshooting.

The panel displays the public `https://….trycloudflare.com` link after the
connection registers and refreshes its status every two seconds. HTTP, HTTPS
origins and WebSocket upgrades are supported. These are temporary Cloudflare
Quick Tunnels, without a Cloudflare account or a fixed hostname. The link is
public: use authentication in the exposed application. Cloudflare Quick Tunnels
do not support Server-Sent Events (SSE) and have provider connection limits.

The remote machine must reach the panel address used to generate the command.
Behind a reverse proxy, set `FILEMANAGER_ORIGIN` to the public panel origin.
For the default self-signed panel, curl falls back to the exact configured public
key pinned by SHA256, without changing system CAs. After replacing the server key,
generate a new command. For a self-signed
certificate on the **local target service**, enable the corresponding checkbox.

Each command uses a separate tunnel capability, never the administrator key.
**Desconectar**, deleting a tunnel, generating another command, or revoking its
owner stops its remote connection on the next heartbeat, normally within 15
seconds. Lost heartbeats remove the live link within 40 seconds. Definitions
persist privately under `.runtime/tunnels/` (or `FILEMANAGER_DATA_DIR/tunnels/`).

### Key Configuration Options
- **`host`** - Server bind address (default: `0.0.0.0`)
- **`port`** - WebSocket server port (default: `8080`)
- **`ssl`** - Path to SSL certificate directory
- **`fileManager.autoRestart`** - Restarts the middleware after a stop and when monitored PHP files change; it can also be changed from the File Manager settings panel
- **`fileManager.ptyBackend`** - Selects `node`, `php`, or `auto` (Node.js with PHP/Swoole fallback) for terminal sessions
- **`fileManager.services`** - Controls whether PTY, LSP, legacy GPT and Codex Agent services start with the File Manager; each service can also be started, stopped or restarted from the settings panel
- **`autoload`** - Plugin directories to autoload
- **`allowExtensions`** - MIME types for static file serving
- **`serverSettings`** - Swoole server configuration

The HTTP transport settings follow `berzersks/sipswoole`'s `manage/server.php`:
TLS 1.2/1.3 (`ssl_protocols: 96`), its TLS cipher list, HTTP/2, TCP Fast Open,
TCP_NODELAY, port reuse, and disabled HTTP compression and automatic static
handling. Worker count, application port, certificate paths and File Manager
upload/buffer limits remain application-specific.

With `host: "0.0.0.0"`, the server also listens on IPv6 (`::`) on the same
port when IPv6 is available. Startup lists IPv4 and IPv6 URLs, including
`https://[::1]:8080`. An explicit IPv4 or IPv6 host binds only that address;
IPv6 URLs use square brackets around the address.

The **Settings > System > Restart now** action restarts only the supervised web
server. PTY sessions, commands and auxiliary services stay alive. In
**Settings > Services**, **Restart** also starts an enabled service that is
currently stopped, which makes manual recovery available after a broader host
or service restart.

## Running

### Development Mode

**Terminal 1 - PHP Swoole Server:**
```bash
php server.php
```
This starts the supervisor that auto-restarts the middleware on crashes.

**Terminal 2 - Node PTY Server:**
```bash
node pty.js
```
Provides terminal sessions on port 6060.

**Terminal 3 (Optional) - GPT Service:**
```bash
node gpt.js
```
Provides AI refactoring on port 3090.

**Codex Agent (ChatGPT Business/Enterprise):**

Authenticate the operating-system user that runs the service with `codex login`.
The bridge uses that cached session when it is available. For trusted ChatGPT
Enterprise automation without a cached login, you may instead create a Codex
access token in the workspace admin console and keep it in the ignored `.env`
file. Do not expose this port publicly.

```bash
CODEX_ACCESS_TOKEN=your-access-token # optional when `codex login` is active
node codex-agent.js
```

The File Manager starts this service automatically when `fileManager.services.codex`
is enabled. The bridge listens only on `127.0.0.1:3091`; browsers connect through
the authenticated Swoole WebSocket relay.

If Node.js, npm dependencies, or the Codex CLI are missing or incompatible, open
**Configurações > Diagnóstico**, choose the desired Node.js line, and select
**Atualizar e reparar**. Installation
runs in the background and its progress log is shown in the same panel.
The same repair can be run from a terminal with `bash scripts/install-codex.sh . 24`
(use `preserve`, `22`, `24`, or `26` as the second argument).
Terminals created by either PTY backend put the managed Node.js and Codex runtime
first in `PATH`, so they use the versions prepared by this repair without changing
the server's global Node.js installation.

The permission selector below the Codex Agent composer is remembered in the browser:
`Apenas leitura` uses a read-only sandbox, `Agente` can write only inside the current
workspace, and `Agente full` disables both the sandbox and approval prompts after an
explicit warning.

### Production Mode

Use a process manager like `systemd`, `supervisor`, or `pm2`:

```bash
# PM2 example
pm2 start server.php --interpreter php --name lipc-server
pm2 start pty.js --name lipc-pty
pm2 start gpt.js --name lipc-gpt
pm2 save
```

## Access

Once running, access the application at:
- **HTTP**: `http://localhost:8080`
- **HTTPS**: `https://localhost:8080` (if SSL configured)

## Scripts

### Composer Scripts
No custom scripts defined. Standard commands:
```bash
composer install    # Install dependencies
composer update     # Update dependencies
composer dump-autoload  # Regenerate autoloader
```

### NPM Scripts
No custom scripts defined. Standard commands:
```bash
npm install         # Install dependencies
npm update          # Update dependencies
```

### Direct Commands
```bash
php server.php      # Start supervised server
php middleware.php  # Start middleware directly (no supervision)
node pty.js         # Start PTY server
node gpt.js         # Start GPT service
```

### Development Tools
```bash
# PHPStan static analysis
vendor/bin/phpstan analyse

# Generate PHP stubs
php stubGen.php

# Format PHP code
php formatter.php <file>
```

## Environment Variables

Application configuration is read from JSON files. The Codex Agent also reads
its credential from the ignored `.env` file:

```bash
CODEX_ACCESS_TOKEN=...       # Optional ChatGPT Enterprise Codex access token
APP_HOST=0.0.0.0           # Override host
APP_PORT=8080              # Override port
SSL_CERT_FILE=fullchain.pem  # SSL certificate path
SSL_KEY_FILE=privkey.pem   # SSL key path
```

## Ports

| Service | Port | Configurable | Description |
|---------|------|-------------|-------------|
| Swoole WebSocket | 8080 | Yes (configInterface.json) | Main application server |
| Node PTY | 6060 | No (hardcoded) | Terminal sessions |
| GPT Service | 3090 | No (hardcoded) | AI refactoring |
| Codex Agent | 3091 | No (hardcoded) | Enterprise coding tasks; loopback only |

## API Endpoints

The system exposes numerous endpoints under `plugins/Request/apps/`:

### File Operations
- `/getFile` - Retrieve file contents
- `/newFile` - Create new file/directory
- `/deleteFile` - Delete single file
- `/deleteMultipleFiles` - Batch delete
- `/renameItem` - Rename file/directory
- `/uploadFile` - Upload files
- `/downloadFile` - Download files
- `/cutCopy` - Cut/copy operations
- `/syncPath` - List directory contents with caching

### Compression
- `/compressMultipleFiles` - Create archives
- `/decompressFile` - Extract archives
- `/getArchiveDetails` - View archive contents

### Code Features
- `/codex` - AI code completion (GitHub Copilot)
- `/codeGenerate` - Generate IntelliSense data
- `/phpParser` - Parse PHP code structure
- `/refactorFile` - Format/refactor code
- `/searchInFile` - Search file contents

### Development Tools
- `/stub` - Generate PHP stubs
- `/treeDetails` - Directory tree structure
- `/freeRam` - Memory usage statistics
- `/checkToken` - Validate session tokens

## Token management

Open **Configurações > Tokens** with an administrator token to create access keys,
edit client names and expiration dates, configure administrator/terminal permissions
and allowed directories, reveal/copy keys, rotate keys, revoke or restore access,
and delete individual or expired records. New keys default to no administrative or
terminal permissions; restricted tokens require at least one existing absolute
directory. An administrator with no directory restriction can access all directories.

Keys appear masked until explicitly revealed. Rotating a key invalidates the old
key immediately; revocation keeps its record available for restoration. The current
administrative token cannot be revoked, rotated, deleted, or stripped of admin access
from its own session. Use another administrator token for those operations. Concurrent
edits require refreshing the list rather than overwriting newer changes.

Run the isolated regressions with `php scripts/security/token-manager-regression.php`
and `node --test scripts/security/token-manager-ui.cjs`.

## Recent Improvements (2025-12-03)

### Code Editor Enhancements
1. **Refactored suggestion system** with:
   - 7 reusable helper functions
   - Eliminated ~150 lines of duplicate code
   - Modern JavaScript (const/let, template literals, optional chaining)

2. **Performance optimizations**:
   - Suggestion caching (5-minute TTL)
   - Request cancellation (AbortController)
   - Cache size limiting (50 entries max)
   - ~70% reduction in API calls

3. **Multiple suggestions support**:
   - Returns 3 AI suggestions per request
   - Keyboard navigation (Alt+] next, Alt+[ previous)
   - Visual indicator `[1/3]`
   - Better suggestion quality (temperature: 0.2, top_p: 0.95)

4. **Bug fixes**:
   - Fixed Ctrl+Z undo functionality
   - Improved history management (LIFO stack)
   - Better error handling with specific messages

### File Size Calculation
- Replaced memory-intensive `du` command with cached approach
- Results cached for 2 minutes in `$GLOBALS`
- Reduced RAM usage by ~80% for large directories

## Tests

### PHP Validation Script
- **Script**: `run-tests.php` - Custom validation script
- **Run**: `php run-tests.php`
- **Checks performed**:
  - PHP syntax validation (all `.php` files)
  - JSON configuration validation (`composer.json`, `plugins/configInterface.json`)
  - Plugin directory structure verification
  - Composer installation and dependencies check
  - Main files existence check (`server.php`, `middleware.php`, `plugins/autoload.php`)
- **Output**: Generates `fixs.json` with suggested fixes for any issues found

### Static Analysis
- **Tool**: PHPStan
- **Run**: `vendor/bin/phpstan analyse`
- **Config**: `phpstan.neon`

### Node.js Tests
- **Status**: No tests configured
- **TODO**: Add test framework (Jest, Mocha, etc.)

## Project Structure

```
lipc/
├── server.php              # PHP supervisor
├── middleware.php          # Swoole WebSocket server
├── pty.js                  # Node PTY WebSocket server
├── gpt.js                  # Puppeteer GPT service
├── index.html              # Main application page
├── composer.json           # PHP dependencies
├── package.json            # Node dependencies
├── plugins/
│   ├── configInterface.json  # Main configuration
│   ├── autoload.php          # Plugin autoloader
│   ├── Request/              # HTTP request handlers
│   │   ├── apps/             # API endpoints (30+ files)
│   │   ├── components/       # Reusable UI components
│   │   ├── modules/          # Feature modules
│   │   ├── router/           # Route definitions
│   │   ├── template/         # Page templates
│   │   └── views/            # View renderers
│   ├── Start/                # Startup utilities
│   ├── Utils/                # Helper utilities
│   ├── Database/             # Database helpers
│   ├── Extension/            # Plugin extensions
│   ├── OpenConnection/       # WebSocket handlers
│   └── Message/              # Message processors
├── css/                    # Stylesheets
├── js/                     # JavaScript files
├── img/                    # Images
├── files/                  # PTY session working directory
├── vendor/                 # Composer dependencies
├── node_modules/           # Node dependencies
└── terminals.json          # Active terminal IDs

Certificate files (SSL/TLS):
├── fullchain.pem
├── privkey.pem
├── chain.pem
├── cert.pem
├── server.crt
├── server.key
├── localhost.crt
└── localhost.key
```

## Development Tips

### Debugging
1. Check Swoole extension:
   ```bash
   php -m | grep swoole
   ```

2. Test port availability:
   ```bash
   netstat -tulpn | grep 8080
   netstat -tulpn | grep 6060
   ```

3. View Swoole logs:
   ```bash
   tail -f /tmp/swoole.log  # If configured
   ```

4. Monitor PTY sessions:
   ```bash
   cat terminals.json
   ls -la files/
   ```

### Common Issues

**Server won't start:**
- Ensure port 8080 is available
- Verify Swoole extension is loaded
- Check file/directory write permissions

**PTY not working:**
- Confirm port 6060 is available
- Check `terminals.json` write permissions
- Verify `files/` directory is writable

**AI suggestions not working:**
- Check GitHub Copilot token in `codex.php`
- Verify network connectivity
- Review browser console for errors

## Security Notes

⚠️ **Important Security Considerations:**

1. **Do not commit production secrets**
   - Keep sensitive data out of version control
   - Use environment variables for secrets in production

2. **SSL/TLS Certificates**
   - Localhost certificates are auto-generated for development
   - For production: use proper certificates (Let's Encrypt, etc.)
   - Keep certificate files secure with proper permissions (600 for keys)

3. **File Access**
   - Application has full filesystem access
   - Implement proper access controls in production
   - Consider chroot/containerization

4. **Authentication**
   - Current token system is basic
   - Implement robust authentication for production
   - Add rate limiting for API endpoints

5. **Code Execution**
   - PTY sessions allow command execution
   - Restrict user permissions appropriately
   - Monitor for suspicious activity

**TODO**: Add comprehensive security layer with:
- User authentication system
- Role-based access control (RBAC)
- API rate limiting
- Audit logging
- Input validation/sanitization

## Performance Tuning

### Swoole Configuration
Adjust in `plugins/configInterface.json`:
```json
{
  "serverSettings": {
    "worker_num": 4,
    "max_request": 20000000,
    "max_coroutine": 20000000,
    "enable_coroutine": true,
    "package_max_length": 2147483648,
    "socket_buffer_size": 2147483648
  }
}
```

**Key settings:**
- `worker_num` - Number of worker processes (match CPU cores)
- `max_request` - Requests before worker restart
- `max_coroutine` - Max concurrent coroutines
- `enable_coroutine` - Enable coroutine mode
- `package_max_length` - Max package size (2GB)
- `socket_buffer_size` - Socket buffer size (2GB)

### Memory Optimization
- Cache is limited to 50 entries per feature
- File size calculations cached for 2 minutes
- Suggestion cache expires after 5 minutes

### Recommended Hardware
- **Minimum**: 2 CPU cores, 4GB RAM
- **Recommended**: 4+ CPU cores, 8GB+ RAM
- **Storage**: SSD recommended for file operations

## License

**No license file found.**

**TODO**: Add a LICENSE file to clarify usage rights. Common options:
- MIT License (permissive)
- Apache License 2.0 (permissive with patent grant)
- GPL v3 (copyleft)
- Proprietary/Commercial

## Contributing

**TODO**: Add CONTRIBUTING.md with guidelines for:
- Code style (PSR-12 for PHP)
- Commit message format
- Pull request process
- Issue reporting

## Roadmap

Future improvements:
- [ ] Add comprehensive test suite (PHPUnit, Jest)
- [ ] Implement user authentication system
- [ ] Add role-based access control
- [ ] Environment variable configuration
- [ ] Docker/container support
- [ ] Plugin marketplace
- [ ] Real-time collaboration features
- [ ] Mobile-responsive interface
- [ ] Theme customization
- [ ] Language server protocol (LSP) support
- [ ] Git integration UI
- [ ] Database GUI
- [ ] API documentation (OpenAPI/Swagger)

## Security checks

See [the security audit and reproducible checks](docs/security/audit.md) before deployment. The custom CLI/Swoole runtime must be rebuilt separately when PHP publishes security fixes; system package updates do not replace it.

## Changelog

### 2025-12-03
- ✨ Refactored code editor with 7 helper functions
- ⚡ Added suggestion caching (5-minute TTL)
- ⚡ Implemented request cancellation (AbortController)
- ⚡ Optimized file size calculation with 2-minute cache
- 🐛 Fixed Ctrl+Z undo functionality
- ✨ Added multiple suggestions (3 per request)
- ✨ Keyboard navigation for suggestions (Alt+[ / Alt+])
- 📝 Improved code quality and reduced duplication (~150 lines)
- 📝 Updated README with comprehensive documentation

### Earlier
- Initial implementation of Swoole WebSocket server
- Monaco Editor integration
- PTY terminal sessions
- GitHub Copilot code completion
- PHP IntelliSense with symbol resolution
- File management with compression support

---

**Generated**: 2025-12-05
**Version**: 1.0.0
**Status**: Active Development
