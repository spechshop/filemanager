"use strict";

const { spawn } = require("node:child_process");
const fs = require("node:fs");
const http = require("node:http");
const os = require("node:os");
const path = require("node:path");
const readline = require("node:readline");
const { WebSocketServer, WebSocket } = require("ws");

const ROOT = fs.realpathSync(__dirname);
const ENV_FILE = path.join(ROOT, ".env");
if (fs.existsSync(ENV_FILE)) {
    if (typeof process.loadEnvFile !== "function") {
        throw new Error("O Codex Agent requer Node.js 20.12 ou mais recente para carregar o arquivo .env.");
    }
    process.loadEnvFile(ENV_FILE);
}

const HOST = "127.0.0.1";
const PORT = Number.parseInt(process.env.CODEX_AGENT_PORT || "3091", 10);
const FILES_ROOT = fs.realpathSync(path.join(ROOT, "files"));
const MAX_MESSAGE_BYTES = 128 * 1024;
const MAX_PROMPT_CHARS = 64 * 1024;
const REQUEST_TIMEOUT_MS = 30_000;
const TURN_LIMIT_PER_MINUTE = 10;
const PERMISSION_MODES = Object.freeze({
    "read-only": Object.freeze({ approvalPolicy: "on-request", sandbox: "read-only" }),
    agent: Object.freeze({ approvalPolicy: "on-request", sandbox: "workspace-write" }),
    "agent-full": Object.freeze({ approvalPolicy: "never", sandbox: "danger-full-access" }),
});

let codexProcess = null;
let codexReady = false;
let stopping = false;
let restartAttempt = 0;
let nextRpcId = 1;

const clients = new Set();
const pendingRpc = new Map();
const pendingServerRequests = new Map();
const threadOwners = new Map();
const threadWorkspaces = new Map();
const activeTurns = new Map();
const threadTokenUsage = new Map();

function redact(value) {
    return String(value)
        .replace(/(CODEX_ACCESS_TOKEN\s*[=:]\s*)\S+/gi, "$1[redacted]")
        .replace(/\b(sk-[A-Za-z0-9_-]{12,})\b/g, "[redacted]");
}

function log(message) {
    process.stderr.write(`[codex-agent] ${redact(message)}\n`);
}

function findCodexBinary() {
    const pathCandidates = (process.env.PATH || "")
        .split(path.delimiter)
        .filter(Boolean)
        .map((directory) => path.join(directory, "codex"));
    const candidates = [
        process.env.CODEX_BIN,
        path.join(ROOT, ".runtime", "codex", "bin", "codex"),
        path.join(ROOT, ".runtime", "node", "bin", "codex"),
        ...pathCandidates,
        path.join(os.homedir(), ".local", "bin", "codex"),
        "/usr/local/bin/codex",
        "/usr/bin/codex",
        "/home/lotus/.local/bin/codex"
    ].filter(Boolean);
    for (const candidate of candidates) {
        try {
            fs.accessSync(candidate, fs.constants.X_OK);
            return candidate;
        } catch {
            // Try the next known installation location.
        }
    }
    throw new Error("Codex CLI não foi encontrado no runtime do File Manager, no PATH nem em ~/.local/bin.");
}

function isInside(base, candidate) {
    const relative = path.relative(base, candidate);
    return relative === "" || (!relative.startsWith("..") && !path.isAbsolute(relative));
}

function resolveWorkspace(value) {
    const requested = typeof value === "string" ? value.trim() : "";
    if (requested.includes("\0")) throw new Error("Diretório de trabalho inválido.");

    const normalized = requested.replaceAll("\\", "/");
    const relative = normalized.replace(/^\/+/, "");
    const relatives = [relative];
    if (relative === "files") {
        relatives.push("");
    } else if (relative.startsWith("files/")) {
        relatives.push(relative.slice("files/".length));
    }

    const candidates = relatives.map((item) => path.resolve(FILES_ROOT, item || "."));
    if (relative && relative !== "files" && !relative.startsWith("files/")) {
        candidates.push(path.resolve(ROOT, relative));
    }
    if (path.isAbsolute(normalized)) candidates.push(path.resolve(normalized));

    for (const candidate of new Set(candidates)) {
        let real;
        try {
            real = fs.realpathSync(candidate);
        } catch {
            continue;
        }
        if (!isInside(ROOT, real) || !fs.statSync(real).isDirectory()) continue;
        return real;
    }

    throw new Error("A pasta selecionada não existe ou está fora do projeto do File Manager.");
}

function resolveEditorFile(workspace, value) {
    const requested = typeof value === "string" ? value.trim() : "";
    if (!requested || requested.length > 4096 || requested.includes("\0")) {
        throw new Error("Referência de arquivo inválida.");
    }

    const normalized = requested.replaceAll("\\", "/");
    const relative = normalized.replace(/^\/+/, "");
    const candidates = [];
    if (path.isAbsolute(normalized)) candidates.push(path.resolve(normalized));
    // publicPayload removes FILES_ROOT before paths reach the browser (for
    // example, files/onago/document.md becomes /onago/document.md). Restore
    // that public path here before trying paths relative to the workspace.
    candidates.push(path.resolve(FILES_ROOT, relative));
    candidates.push(path.resolve(workspace, relative));
    candidates.push(path.resolve(ROOT, relative));

    for (const candidate of new Set(candidates)) {
        let real;
        try {
            real = fs.realpathSync(candidate);
        } catch {
            continue;
        }
        // A pasta da conversa define o cwd do agente, mas não limita os links
        // que o usuário pode abrir no editor. Para referências, basta o caminho
        // existir e apontar para um arquivo real.
        if (!fs.statSync(real).isFile()) continue;
        return real.replaceAll(path.sep, "/");
    }

    throw new Error("O arquivo referenciado não existe ou não é um arquivo válido.");
}

function publicPath(value) {
    if (typeof value !== "string") return value;
    return value.split(FILES_ROOT).join("") || "/";
}

function publicPayload(value) {
    if (typeof value === "string") return publicPath(value);
    if (Array.isArray(value)) return value.map(publicPayload);
    if (!value || typeof value !== "object") return value;
    return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, publicPayload(item)]));
}

function sendClient(client, payload) {
    if (client.readyState !== WebSocket.OPEN) return;
    client.send(JSON.stringify(publicPayload(payload)));
}

function broadcastStatus(status, message) {
    for (const client of clients) {
        sendClient(client, { type: "status", status, message });
    }
}

function sendCodex(message) {
    if (!codexProcess || !codexProcess.stdin.writable) {
        throw new Error("Codex app-server não está disponível.");
    }
    codexProcess.stdin.write(`${JSON.stringify(message)}\n`);
}

function rpc(method, params = {}, timeoutMs = REQUEST_TIMEOUT_MS) {
    if (!codexProcess) return Promise.reject(new Error("Codex app-server não está disponível."));
    const id = nextRpcId++;
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            pendingRpc.delete(id);
            reject(new Error(`Tempo limite excedido em ${method}.`));
        }, timeoutMs);
        pendingRpc.set(id, { method, resolve, reject, timer });
        try {
            sendCodex({ method, id, params });
        } catch (error) {
            clearTimeout(timer);
            pendingRpc.delete(id);
            reject(error);
        }
    });
}

function notification(method, params = {}) {
    sendCodex({ method, params });
}

function rejectPending(error) {
    for (const pending of pendingRpc.values()) {
        clearTimeout(pending.timer);
        pending.reject(error);
    }
    pendingRpc.clear();
    pendingServerRequests.clear();
}

function findThreadId(message) {
    const params = message && message.params;
    return params?.threadId || params?.thread?.id || null;
}

function containsHttpStatus(value, expected) {
    if (!value || typeof value !== "object") return false;
    if (value.httpStatusCode === expected) return true;
    return Object.values(value).some((item) => containsHttpStatus(item, expected));
}

function ownersForThread(threadId) {
    if (!threadId) return clients;
    return threadOwners.get(threadId) || new Set();
}

function rememberThread(client, thread, workspace) {
    const threadId = thread?.id;
    if (!threadId) return;
    if (!threadOwners.has(threadId)) threadOwners.set(threadId, new Set());
    threadOwners.get(threadId).add(client);
    threadWorkspaces.set(threadId, workspace);
    const active = Array.isArray(thread.turns)
        ? thread.turns.find((turn) => turn.status === "inProgress")
        : null;
    if (active?.id) activeTurns.set(threadId, active.id);
}

function responseForServerRequest(record, payload) {
    const method = record.method;
    if (method === "item/commandExecution/requestApproval" || method === "item/fileChange/requestApproval") {
        const allowed = new Set(["accept", "acceptForSession", "decline", "cancel"]);
        const decision = allowed.has(payload.decision) ? payload.decision : "decline";
        return { decision };
    }

    if (method === "item/tool/requestUserInput") {
        const answers = {};
        const source = payload.answers && typeof payload.answers === "object" ? payload.answers : {};
        for (const [questionId, answer] of Object.entries(source)) {
            const list = Array.isArray(answer) ? answer : [answer];
            answers[questionId] = {
                answers: list.map((item) => String(item).trim()).filter(Boolean).slice(0, 3),
            };
        }
        return { answers };
    }

    if (method === "item/permissions/requestApproval") {
        if (payload.decision !== "accept") return { permissions: {}, scope: "turn" };
        const requested = record.params?.permissions || {};
        const workspace = threadWorkspaces.get(record.params?.threadId);
        const granted = {};

        if (requested.network?.enabled === true) granted.network = { enabled: true };

        const reads = Array.isArray(requested.fileSystem?.read)
            ? requested.fileSystem.read.filter((entry) => workspace && isInside(workspace, path.resolve(entry)))
            : [];
        const writes = Array.isArray(requested.fileSystem?.write)
            ? requested.fileSystem.write.filter((entry) => workspace && isInside(workspace, path.resolve(entry)))
            : [];
        if (reads.length || writes.length) granted.fileSystem = { read: reads, write: writes };

        return {
            permissions: granted,
            scope: payload.scope === "session" ? "session" : "turn",
        };
    }

    if (method === "mcpServer/elicitation/request") {
        return { action: "cancel", content: null };
    }

    throw new Error("Tipo de aprovação não suportado pelo File Manager.");
}

function autoDeclineServerRequest(message) {
    const method = message.method;
    let result;
    if (method === "item/commandExecution/requestApproval" || method === "item/fileChange/requestApproval") {
        result = { decision: "cancel" };
    } else if (method === "item/tool/requestUserInput") {
        result = { answers: {} };
    } else if (method === "item/permissions/requestApproval") {
        result = { permissions: {}, scope: "turn" };
    } else if (method === "mcpServer/elicitation/request") {
        result = { action: "cancel", content: null };
    } else {
        result = { decision: "cancel" };
    }
    try {
        sendCodex({ id: message.id, result });
    } catch {
        // The app-server is already unavailable.
    }
}

function handleCodexMessage(message) {
    if (Object.hasOwn(message, "id") && !message.method) {
        const pending = pendingRpc.get(message.id);
        if (!pending) return;
        pendingRpc.delete(message.id);
        clearTimeout(pending.timer);
        if (message.error) {
            const error = new Error(message.error.message || `Falha em ${pending.method}.`);
            error.code = message.error.code;
            pending.reject(error);
        } else {
            pending.resolve(message.result);
        }
        return;
    }

    if (Object.hasOwn(message, "id") && message.method) {
        const threadId = findThreadId(message);
        const owners = [...ownersForThread(threadId)].filter((client) => client.readyState === WebSocket.OPEN);
        if (!owners.length) {
            autoDeclineServerRequest(message);
            return;
        }

        const owner = owners[0];
        const timer = setTimeout(() => {
            const record = pendingServerRequests.get(message.id);
            if (!record) return;
            pendingServerRequests.delete(message.id);
            autoDeclineServerRequest(message);
        }, 10 * 60_000);
        pendingServerRequests.set(message.id, {
            owner,
            method: message.method,
            params: message.params || {},
            timer,
        });
        sendClient(owner, {
            type: "server.request",
            serverRequestId: message.id,
            method: message.method,
            params: message.params || {},
        });
        return;
    }

    if (!message.method) return;
    const threadId = findThreadId(message);
    if (message.method === "error" && containsHttpStatus(message.params?.error, 401)) {
        codexReady = false;
        message.params.error.message = "CODEX_ACCESS_TOKEN foi recusado (HTTP 401). Gere ou copie um token Codex válido do workspace e reinicie o serviço.";
        broadcastStatus("error", message.params.error.message);
    }
    if (message.method === "turn/started" && threadId && message.params?.turn?.id) {
        activeTurns.set(threadId, message.params.turn.id);
    }
    if (message.method === "turn/completed" && threadId) {
        activeTurns.delete(threadId);
    }
    if (message.method === "thread/tokenUsage/updated" && threadId && message.params?.tokenUsage) {
        threadTokenUsage.set(threadId, message.params.tokenUsage);
    }
    if (["thread/archived", "thread/deleted"].includes(message.method) && threadId) {
        threadTokenUsage.delete(threadId);
    }
    for (const owner of ownersForThread(threadId)) {
        sendClient(owner, { type: "event", method: message.method, params: message.params || {} });
    }
}

async function initializeCodex() {
    await rpc("initialize", {
        clientInfo: { name: "lotus_filemanager", title: "Lotus File Manager", version: "0.1.0" },
        capabilities: { experimentalApi: true },
    });
    notification("initialized", {});
    await rpc("account/read", { refreshToken: false });
    codexReady = true;
    restartAttempt = 0;
    broadcastStatus("ready", "Codex Enterprise conectado.");
    log("Codex app-server inicializado.");
}

function scheduleCodexRestart() {
    if (stopping) return;
    const delays = [1_000, 2_000, 5_000, 10_000, 30_000];
    const delay = delays[Math.min(restartAttempt++, delays.length - 1)];
    broadcastStatus("restarting", `Codex indisponível; nova tentativa em ${Math.ceil(delay / 1000)}s.`);
    setTimeout(startCodex, delay);
}

function startCodex() {
    if (stopping || codexProcess) return;
    if (!process.env.CODEX_ACCESS_TOKEN?.trim()) {
        log("CODEX_ACCESS_TOKEN não foi definido.");
        broadcastStatus("error", "CODEX_ACCESS_TOKEN não foi definido no servidor.");
        scheduleCodexRestart();
        return;
    }

    codexReady = false;
    let executable;
    try {
        executable = findCodexBinary();
    } catch (error) {
        log(error.message);
        broadcastStatus("error", error.message);
        scheduleCodexRestart();
        return;
    }
    const managedPaths = [
        path.join(ROOT, ".runtime", "node", "bin"),
        path.join(ROOT, ".runtime", "codex", "bin"),
        process.env.PATH || "",
    ].filter(Boolean).join(path.delimiter);
    codexProcess = spawn(executable, ["app-server", "--listen", "stdio://"], {
        cwd: ROOT,
        env: {...process.env, PATH: managedPaths},
        stdio: ["pipe", "pipe", "pipe"],
    });

    const current = codexProcess;
    const lines = readline.createInterface({ input: current.stdout });
    lines.on("line", (line) => {
        try {
            handleCodexMessage(JSON.parse(line));
        } catch (error) {
            log(`Mensagem inválida do app-server: ${error.message}`);
        }
    });
    current.stderr.on("data", (chunk) => {
        const text = redact(chunk).trim();
        if (text) log(text.slice(0, 2000));
    });
    current.on("error", (error) => log(`Não foi possível iniciar Codex: ${error.message}`));
    current.on("exit", (code, signal) => {
        if (codexProcess !== current) return;
        codexProcess = null;
        codexReady = false;
        rejectPending(new Error("Codex app-server foi encerrado."));
        log(`Codex app-server encerrou (code=${code ?? "null"}, signal=${signal ?? "none"}).`);
        scheduleCodexRestart();
    });

    initializeCodex().catch((error) => {
        log(`Falha ao inicializar Codex: ${error.message}`);
        broadcastStatus("error", error.message);
        current.kill("SIGTERM");
    });
}

function requireThread(client, threadId) {
    if (typeof threadId !== "string" || !threadOwners.get(threadId)?.has(client)) {
        throw new Error("Conversa do Codex inválida para esta conexão.");
    }
    return threadWorkspaces.get(threadId);
}

async function verifyThreadWorkspace(threadId, workspace) {
    const result = await rpc("thread/read", { threadId, includeTurns: false });
    const cwd = result?.thread?.cwd ? fs.realpathSync(result.thread.cwd) : null;
    if (!cwd || cwd !== workspace) throw new Error("A conversa pertence a outra pasta.");
    return result.thread;
}

function checkTurnRate(client) {
    const now = Date.now();
    client.turnStarts = (client.turnStarts || []).filter((time) => now - time < 60_000);
    if (client.turnStarts.length >= TURN_LIMIT_PER_MINUTE) {
        throw new Error("Muitas tarefas iniciadas em pouco tempo. Aguarde um minuto.");
    }
    client.turnStarts.push(now);
}

function textInput(value) {
    const text = typeof value === "string" ? value.trim() : "";
    if (!text) throw new Error("Digite uma tarefa para o Codex.");
    if (text.length > MAX_PROMPT_CHARS) throw new Error("A tarefa ultrapassa o limite de 64 KiB.");
    return [{ type: "text", text }];
}

function permissionMode(message) {
    const mode = typeof message.permissionMode === "string" ? message.permissionMode.trim() : "agent";
    if (!Object.hasOwn(PERMISSION_MODES, mode)) {
        throw new Error("Modo de permissão do Codex inválido.");
    }
    return mode;
}

function threadPolicy(message) {
    const policy = PERMISSION_MODES[permissionMode(message)];
    return {
        approvalPolicy: policy.approvalPolicy,
        sandbox: policy.sandbox,
    };
}

function turnPolicy(workspace, message) {
    const mode = permissionMode(message);
    const policy = PERMISSION_MODES[mode];
    let sandboxPolicy;
    if (mode === "read-only") {
        sandboxPolicy = { type: "readOnly", networkAccess: false };
    } else if (mode === "agent-full") {
        sandboxPolicy = { type: "dangerFullAccess" };
    } else {
        sandboxPolicy = {
            type: "workspaceWrite",
            writableRoots: [workspace],
            networkAccess: false,
        };
    }

    return {
        cwd: workspace,
        approvalPolicy: policy.approvalPolicy,
        sandboxPolicy,
    };
}

function modelPreferences(message) {
    const model = typeof message.model === "string" ? message.model.trim() : "";
    const reasoningEffort = typeof message.reasoningEffort === "string"
        ? message.reasoningEffort.trim().toLowerCase()
        : "";
    if (model && (!/^[a-z0-9][a-z0-9._:+-]{0,127}$/i.test(model))) {
        throw new Error("Modelo do Codex inválido.");
    }
    if (reasoningEffort && !/^[a-z][a-z0-9_-]{0,31}$/i.test(reasoningEffort)) {
        throw new Error("Nível de raciocínio do Codex inválido.");
    }
    return { model: model || null, reasoningEffort: reasoningEffort || null };
}

function threadPreferenceOverrides(message) {
    const preferences = modelPreferences(message);
    const overrides = {};
    if (preferences.model) overrides.model = preferences.model;
    if (preferences.reasoningEffort) {
        overrides.config = { model_reasoning_effort: preferences.reasoningEffort };
    }
    return overrides;
}

async function applyThreadPreferences(threadId, message) {
    const preferences = modelPreferences(message);
    if (!preferences.model && !preferences.reasoningEffort) return null;
    return rpc("thread/settings/update", {
        threadId,
        model: preferences.model,
        effort: preferences.reasoningEffort,
    });
}

async function performAction(client, message) {
    if (!codexReady && !["health", "file.resolve"].includes(message.action)) {
        throw new Error("Codex ainda não está pronto.");
    }
    const action = message.action;

    if (action === "health") {
        return { ready: codexReady, tokenConfigured: Boolean(process.env.CODEX_ACCESS_TOKEN?.trim()) };
    }

    if (action === "model.list") {
        return rpc("model/list", { limit: 100, includeHidden: false });
    }

    if (action === "thread.list") {
        const workspace = resolveWorkspace(message.workspace);
        return rpc("thread/list", {
            cwd: workspace,
            limit: 50,
            sortKey: "updated_at",
            sortDirection: "desc",
            // Threads opened through this embedded app-server are persisted as
            // `vscode` by current Codex releases. Keep `appServer` too so the
            // history remains compatible if Codex starts using that source.
            sourceKinds: ["vscode", "appServer"],
        });
    }

    if (action === "thread.start") {
        const workspace = resolveWorkspace(message.workspace);
        const result = await rpc("thread/start", {
            cwd: workspace,
            ...threadPolicy(message),
            serviceName: "lotus_filemanager",
            ...threadPreferenceOverrides(message),
        });
        rememberThread(client, result?.thread, workspace);
        return result;
    }

    if (action === "thread.resume") {
        const workspace = resolveWorkspace(message.workspace);
        const thread = await verifyThreadWorkspace(message.threadId, workspace);
        const result = await rpc("thread/resume", {
            threadId: thread.id,
            cwd: workspace,
            ...threadPolicy(message),
            ...threadPreferenceOverrides(message),
        });
        rememberThread(client, result?.thread || thread, workspace);
        return result;
    }

    if (action === "thread.read") {
        const workspace = requireThread(client, message.threadId);
        await verifyThreadWorkspace(message.threadId, workspace);
        const result = await rpc("thread/read", { threadId: message.threadId, includeTurns: true });
        return { ...result, tokenUsage: threadTokenUsage.get(message.threadId) || null };
    }

    if (action === "file.resolve") {
        const workspace = requireThread(client, message.threadId);
        // Codifica o caminho para que publicPayload não o converta em um caminho
        // relativo antes de ele chegar ao editor. O navegador o decodifica após
        // esta validação de pertencimento ao workspace.
        return { editorPath: encodeURIComponent(resolveEditorFile(workspace, message.filePath)) };
    }

    if (action === "thread.rename") {
        requireThread(client, message.threadId);
        const name = typeof message.name === "string" ? message.name.trim() : "";
        if (!name) throw new Error("Digite um nome para a conversa.");
        if (name.length > 120) throw new Error("O nome da conversa deve ter no máximo 120 caracteres.");
        await rpc("thread/name/set", { threadId: message.threadId, name });
        return { threadId: message.threadId, name };
    }

    if (action === "thread.archive") {
        requireThread(client, message.threadId);
        const result = await rpc("thread/archive", { threadId: message.threadId });
        threadOwners.delete(message.threadId);
        threadWorkspaces.delete(message.threadId);
        activeTurns.delete(message.threadId);
        threadTokenUsage.delete(message.threadId);
        return result;
    }

    if (action === "turn.start") {
        const workspace = requireThread(client, message.threadId);
        if (activeTurns.has(message.threadId)) throw new Error("Esta conversa já possui uma tarefa ativa.");
        checkTurnRate(client);
        await applyThreadPreferences(message.threadId, message);
        const result = await rpc("turn/start", {
            threadId: message.threadId,
            input: textInput(message.text),
            ...turnPolicy(workspace, message),
        });
        if (result?.turn?.id) activeTurns.set(message.threadId, result.turn.id);
        return result;
    }

    if (action === "thread.settings.update") {
        requireThread(client, message.threadId);
        const result = await applyThreadPreferences(message.threadId, message);
        return { applied: Boolean(result), preferences: modelPreferences(message) };
    }

    if (action === "turn.steer") {
        requireThread(client, message.threadId);
        const turnId = activeTurns.get(message.threadId);
        if (!turnId) throw new Error("Não existe tarefa ativa para orientar.");
        return rpc("turn/steer", {
            threadId: message.threadId,
            expectedTurnId: turnId,
            input: textInput(message.text),
        });
    }

    if (action === "turn.interrupt") {
        requireThread(client, message.threadId);
        const turnId = activeTurns.get(message.threadId);
        if (!turnId) throw new Error("Não existe tarefa ativa para interromper.");
        return rpc("turn/interrupt", { threadId: message.threadId, turnId });
    }

    if (action === "server.respond") {
        const record = pendingServerRequests.get(message.serverRequestId);
        if (!record || record.owner !== client) throw new Error("A solicitação já expirou ou não pertence a esta sessão.");
        const result = responseForServerRequest(record, message);
        clearTimeout(record.timer);
        pendingServerRequests.delete(message.serverRequestId);
        sendCodex({ id: message.serverRequestId, result });
        return { resolved: true };
    }

    throw new Error("Ação do Codex não permitida.");
}

const server = http.createServer((request, response) => {
    if (request.url === "/healthz" || request.url === "/readyz") {
        const readyProbe = request.url === "/readyz";
        response.writeHead(readyProbe && !codexReady ? 503 : 200, { "Content-Type": "application/json" });
        response.end(JSON.stringify({ running: true, ready: codexReady }));
        return;
    }
    response.writeHead(404).end();
});

const wsServer = new WebSocketServer({ noServer: true, maxPayload: MAX_MESSAGE_BYTES });

server.on("upgrade", (request, socket, head) => {
    const remote = request.socket.remoteAddress || "";
    const loopback = remote === "127.0.0.1" || remote === "::1" || remote === "::ffff:127.0.0.1";
    if (request.url !== "/agent" || !loopback) {
        socket.write("HTTP/1.1 403 Forbidden\r\n\r\n");
        socket.destroy();
        return;
    }
    wsServer.handleUpgrade(request, socket, head, (client) => wsServer.emit("connection", client));
});

wsServer.on("connection", (client) => {
    clients.add(client);
    client.isAlive = true;
    sendClient(client, {
        type: "status",
        status: codexReady ? "ready" : "starting",
        message: codexReady ? "Codex Enterprise conectado." : "Inicializando Codex...",
    });

    client.on("pong", () => { client.isAlive = true; });
    client.on("error", (error) => {
        log(`Conexão WebSocket inválida encerrada: ${error.message}`);
        if (client.readyState !== WebSocket.CLOSED) client.terminate();
    });
    client.on("message", async (raw) => {
        let message;
        try {
            if (raw.length > MAX_MESSAGE_BYTES) throw new Error("Mensagem muito grande.");
            message = JSON.parse(raw.toString("utf8"));
            if (!message || typeof message !== "object" || typeof message.action !== "string") {
                throw new Error("Mensagem inválida.");
            }
            const data = await performAction(client, message);
            sendClient(client, {
                type: "response",
                requestId: message.requestId || null,
                action: message.action,
                ok: true,
                data,
            });
        } catch (error) {
            sendClient(client, {
                type: "response",
                requestId: message?.requestId || null,
                action: message?.action || null,
                ok: false,
                error: error.message || "Falha ao processar a solicitação.",
            });
        }
    });

    client.on("close", () => {
        clients.delete(client);
        for (const owners of threadOwners.values()) owners.delete(client);
        for (const [id, record] of pendingServerRequests.entries()) {
            if (record.owner !== client) continue;
            clearTimeout(record.timer);
            pendingServerRequests.delete(id);
            autoDeclineServerRequest({ id, method: record.method, params: record.params });
        }
    });
});

const heartbeat = setInterval(() => {
    for (const client of clients) {
        if (!client.isAlive) {
            client.terminate();
            continue;
        }
        client.isAlive = false;
        client.ping();
    }
}, 30_000);

function shutdown(signal) {
    if (stopping) return;
    stopping = true;
    log(`Encerrando após ${signal}.`);
    clearInterval(heartbeat);
    for (const client of clients) client.close(1001, "Serviço reiniciado");
    if (codexProcess) codexProcess.kill("SIGTERM");
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 3_000).unref();
}

process.on("SIGINT", () => shutdown("SIGINT"));
process.on("SIGTERM", () => shutdown("SIGTERM"));
process.on("uncaughtException", (error) => {
    log(`Erro não tratado: ${error.stack || error.message}`);
    if (codexProcess) codexProcess.kill("SIGTERM");
    process.exit(1);
});
process.on("unhandledRejection", (error) => log(`Promise rejeitada: ${error?.stack || error}`));

server.listen(PORT, HOST, () => {
    log(`Bridge ouvindo em ws://${HOST}:${PORT}/agent.`);
    startCodex();
});
