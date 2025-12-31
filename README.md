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
- **Language**: PHP 8.1+
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
- **Runtime**: Node.js 18+
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

### Frontend
- **`index.html`** - Main application entry point
- **`plugins/Request/modules/modalEditCode.html`** - Code editor interface with:
  - Multi-tab file editing
  - AI-powered autocomplete
  - Integrated terminal
  - File tree browser

## Requirements

### System
- PHP 8.1+ (CLI)
- Swoole extension (matching your PHP version)
- Composer
- Node.js 18+ and npm

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

### 1. Clone Repository
```bash
git clone <repository-url>
cd lipc
```

### 2. Install PHP Dependencies
```bash
composer install
```

### 3. Install Node.js Dependencies
```bash
npm install
```

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
  "serverSettings": {
    "worker_num": 1,
    "max_request": 20000000,
    "max_coroutine": 20000000,
    "enable_coroutine": true,
    "open_http2_protocol": true,
    "ssl_cert_file": "fullchain.pem",
    "ssl_key_file": "privkey.pem"
  }
}
```

### Key Configuration Options
- **`host`** - Server bind address (default: `0.0.0.0`)
- **`port`** - WebSocket server port (default: `8080`)
- **`ssl`** - Path to SSL certificate directory
- **`autoload`** - Plugin directories to autoload
- **`allowExtensions`** - MIME types for static file serving
- **`serverSettings`** - Swoole server configuration

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

Currently, configuration is read from JSON files. The following environment variables could be implemented (TODO):

```bash
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
