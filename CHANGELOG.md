# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Settings now allow choosing the terminal PTY backend (`Node.js`, `PHP/Swoole`, or automatic fallback), with the same preference applied by automatic startup and service controls
- Diagnostics now report system and managed Node.js runtimes plus both PTY implementations, and can update the managed runtime to Node.js 22, 24 LTS, or 26 Current
- Real PHP Language Server (Intelephense) powering the code editor, replacing the static `stubs-generated.json` approach with a proper LSP pipeline — delivering PhpStorm-like fluidity:
  - `lsp.js`: a WebSocket↔stdio bridge (port 3057) that spawns Intelephense per connection and (de)frames LSP JSON-RPC; started automatically by `middleware.php` alongside the PTY server
  - Secure relay in the Swoole WebSocket server (`plugins/Message/server/server.php`): LSP traffic tunnels through the existing `wss://host/<token>-lsp` proxy (a per-token `Channel` + worker keeps the `initialize` handshake intact), so it also works for remote access
  - Lightweight LSP client in `modalEditCode.html` wiring Monaco providers to the language server: **completion** (with `completionItem/resolve` and auto-imports), **hover/quick documentation** (rich markdown: signature, description, `@param`, links — matching the reference screenshot), **signature help** (parameter hints), **go-to-definition**, and live **diagnostics** via `publishDiagnostics`
  - `intelephense` added as an npm dependency
- Backend PHP keyword/snippet catalog: `r.php` now emits a `keywords` section in `stubs-generated.json` (81 language constructs such as `if`, `print`, `echo`, `foreach`, `function`), mirroring the editor so the server is the source of truth for suggestions
- New `phpstanCheck` endpoint (`plugins/Request/apps/phpstanCheck.php`) that runs PHPStan on the current file/code and returns JSON diagnostics, surfaced as PHPStan markers in the Monaco editor on save
- New `formatCode` endpoint (`plugins/Request/apps/formatCode.php`) that formats PHP via `php-cs-fixer` (@PSR12) when available, falling back to the `nikic/php-parser` pretty-printer; the editor's document formatter now routes through it (prettier as client-side fallback)

### Changed

- The editor now sources its intelligence from the PHP Language Server: when the LSP is connected, the previous `stubs-generated.json`/`codeGenerate` completion path is retired and used only as an automatic fallback if the language server is unavailable
- The editor completion catalog now consumes backend-provided `keywords` (with a local fallback), keeping frontend and backend suggestions in sync
- Fixed `phpstan.neon` by removing options unsupported by PHPStan 2.x (`checkMissingIterableValueType`, `checkGenericClassInNonGenericObjectType`) so static analysis runs again

### Fixed

- Intelephense false positive "Undefined function 'Co\run'" (and other `vendor/` symbols): the LSP client sent `initialize` with `rootUri: null`, so Intelephense never indexed the workspace/`vendor` (including `swoole/ide-helper`). The `lsp.js` bridge now injects the project root as `rootUri`/`rootPath`/`workspaceFolders` into the `initialize` request, so the Swoole stubs are indexed and `Co\run` (and friends) are recognized
- Editor hover showing two stacked description boxes for the same symbol (e.g. `Co\run`): Intelephense returns a single markdown hover that concatenates every definition found (separated by `---`), so a symbol defined in more than one stub (`swoole/ide-helper` declares `Co\run` in both `alias_ns.php` and the library core) rendered twice. `modalEditCode.html` now collapses duplicate hover sections by symbol header (case-insensitive), keeping a single box like PhpStorm
- Editor UI freeze (e.g. the connection toast's progress bar stalling) when opening or saving a file: the legacy stubs pipeline ran synchronously on every open/save — fetching `/codeGenerate`, building 1200+ function signatures, and re-scanning the whole file with regex for inlay hints — blocking the main thread. When the Language Server owns the current PHP file the heavy `/codeGenerate` rebuild is now skipped (only the lightweight fallback providers are re-registered) and inlay hints only process signatures actually present in the code, so the main thread stays responsive
- PHPStan false positive shown in the editor (e.g. "Instantiated class `libspech\Sip\trunkController` not found. phpstan") while the hover popup simultaneously showed the class definition: the `phpstanCheck` endpoint runs PHPStan with the root project's autoload, so classes living in sub-projects under `files/` (which are not part of that autoload) were flagged as "not found" even though Intelephense — which indexes the whole workspace — resolved them correctly. When the Language Server owns the current PHP file it is now the sole diagnostics authority: `runPhpStanDiagnostics` clears any stale `phpstan` markers and skips the redundant static-analysis call, so the false positive disappears (PHPStan still runs as a fallback when the LSP is unavailable)
- Parameter name inlay hints no longer working: the previous UI-freeze fix disabled the legacy inlay hints for LSP-owned files without a replacement (community Intelephense does not expose an `inlayHintProvider`), so parameter hints vanished. Inlay hints are restored for every file and kept fast by only processing signatures whose name actually appears in the current code (instead of scanning the whole file for all 1200+ catalog entries on every render), so hints work again without re-introducing the freeze
- Member autocompletion (e.g. `$phone->c` on an instance) showing wrong icons and a duplicated description box: `wordBasedSuggestions` was enabled, so Monaco harvested identifiers from other open tabs/models (`->call(`, `->setCallerId(`, `->setCallId(` in `example.php`/`transfer.php`) and offered them as `Text`/"abc" items whose icon did not match the member type, conflicting with the LSP's real methods. Word-based suggestions are now disabled (`wordBasedSuggestions: false` + `suggest.showWords: false`) so icons reflect the actual symbol kind. The two stacked description boxes came from the completion documentation concatenating duplicate definitions (same `---`-separated markdown as hover, for a symbol indexed in more than one place such as `libspech\Sip\trunkController`); `docToMonaco` now runs completion/resolve documentation through the same `dedupeHoverMarkdown` collapse, showing a single box like PhpStorm
- Member autocompletion no longer suggesting anything at all (e.g. typing `$phone->` produced an empty list) after word-based suggestions were disabled: the editor file model was never registered with the Language Server because `openModel` bailed out for the current file. The filename comes from an element's `textContent` and can carry trailing whitespace/newline, so `"file.php\n"` failed the `/\.php$/i` check (`$` does not match before a trailing `\n` without the `m` flag) and the model's URI was never tracked — leaving the LSP completion provider with no document to query while the (now word-based-free) editor had no fallback. `openModel` now trims the name before the PHP check, the LSP completion provider lazily registers the active model when its URI is still missing, and the legacy stub provider falls back with `ownsModel(model)` instead of a global `isReady()` so suggestions never come up empty when the LSP has not yet claimed the current model
- Opening a file in the editor fetched `/getFile` twice: the `#namefilemodaledit` `MutationObserver` is guarded by `window.blockingMonitor`, but the guard was reset synchronously right after `newTab.click()`. Since the observer callback runs as a microtask, the programmatic click's redundant text write was processed *after* the guard had already been cleared, re-triggering the whole open flow (and a second `/getFile`). The reset is now deferred to a later microtask (`Promise.resolve().then(...)`), so the observer sees the guard still active for the click's mutation and ignores it — the file is fetched once
- Typing `>` (i.e. `->`) sent the `textDocument/completion` command twice over the LSP websocket: Monaco can invoke `provideCompletionItems` twice for the same event (the trigger character firing suggest plus the content change firing quick suggestions). The LSP completion provider now coalesces identical in-flight requests (keyed by document URI + position), reusing the pending promise instead of emitting a duplicate websocket command

## [0.1.0] - 2025-12-05

### Added

- Token management system with new `manage-tokens.php` script ([d53fc52](https://github.com/berzersks/filemanager/commit/d53fc52), [ca970f1](https://github.com/berzersks/filemanager/commit/ca970f1))
- Script to download and use PHP 8.5.0 ZTS binary in psalm workflow ([ea16342](https://github.com/berzersks/filemanager/commit/ea16342), [01df7f0](https://github.com/berzersks/filemanager/commit/01df7f0), [8c77125](https://github.com/berzersks/filemanager/commit/8c77125))

### Changed

- Improved token validation in `checkToken.php` with enhanced request handling
- Refactored `run-tests.php` for better test execution workflow
- Updated `phpstan.neon` configuration for static analysis
- Enhanced `server.php` with additional functionality
- Updated `.gitignore` with new exclusion patterns

[Unreleased]: https://github.com/berzersks/filemanager/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/berzersks/filemanager/releases/tag/v0.1.0
