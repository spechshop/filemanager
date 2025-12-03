<?php

namespace libspech\Sip;

class trunkController
{
    public bool $callableRingInvoked = false;
    public mixed $username;
    public mixed $password;
    public mixed $host;
    public mixed $port;
    public \Swoole\Coroutine\Socket $socket;
    public int $expires;
    public string $localIp;
    public string $callId;
    public int $timestamp = 0;
    public int $audioReceivePort;
    public string $nonce = "";
    public bool $isRegistered = false;
    public int $csq;
    public int $ssrc;
    public bool $error = false;
    public bool $callActive = false;
    public int $timeoutCall;
    public string $callerId;
    public int $registerCount = 0;
    public int $socketPortListen = 0;
    public int $connectTimeout = 30;
    public array $headersNeedAuthorization = ["Proxy-Authenticate" => "Proxy-Authorization", "WWW-Authenticate" => "Authorization"];
    public array $progressCodes = [100, 180, 181, 182, 183];
    public array $successCodes = [200, 202, 204];
    public array $failureCodes = [403, 484, 404, 503, 405, 406, 408, 488, 410, 500, 501, 502, 504, 505, 513, 580, 600, 603, 604, 606];
    public string $bufferAudio = "";
    public mixed $sequenceNumber = 0;
    public array $dtmfList = [];
    public int $lastTime = 0;
    public bool $receiveBye = false;
    public $headers200;
    public string $calledNumber;
    public int $audioRemotePort = 0;
    public string $audioRemoteIp = "";
    public array $volumesAverage = [];
    public array $originVolumes = [];
    public bool $allowBuffer = false;
    public array $byeRecovery = [];
    public $onFailedCallback;
    public $onAnswerCallback;
    public ?string $fileRecord = null;
    public bool $speakWait = false;
    public int $speakWaitTime = 0;
    public array $speakWaitSequence = [];
    public int|float $lastSpeakTime = 0;
    public bool $blockSpeak = false;
    public int|float $speakTimeStart = 0;
    public bool $startSpeak = false;
    public int $totalSequence = 3;
    public array $socketsList = [];
    public bool $inTransfer = false;
    public mixed $currentMethod = null;
    public mixed $globalInfo = [];
    public array $dtmfCallbacks = [];
    public array $supportedCodecs = [0 => ["rtpmap:0 PCMU/8000"], 18 => ["rtpmap:18 G729/8000", "fmtp:18 annexb=no"], 101 => ["rtpmap:101 telephone-event/8000", "fmtp:101 0-16"], 8 => ["rtpmap:8 PCMA/8000"]];
    public array $members = [];
    public bool|string $domain = false;
    public array $ssrcSequences = [];
    public string $currentState = "";
    public string $codecMediaLine = "";
    public array $codecRtpMap = [];
    public array $box = [];
    public array $bufferWriteSound = [];
    public array $volumeCodec = [];
    public $rtpSocket;
    public $remoteIp;
    public $remotePort;
    public bool $newSound = false;
    public string $audioFilePath = '';
    public int $currentCodec = 8;
    public \bcg729Channel $channel;
    public array $listeners = [];
    public bool $enableAudioRecording = false;
    public string $recordAudioBuffer = '';
    public array $dtmfClicks = [];
    public $onHangupCallback;
    public int $closeCallInTime = 0;
    public $onRingingCallback;
    public $socketInUse;
    public $waitingEnd = 0;
    public $onReceiveAudioCallback = null;
    public int $speakStartThreshold = 2;
    public int $speakEndThreshold = 3;
    public $prefix = '';
    public array $ptsRegistered = [];
    public array $ptsDtmfRegistered = [];
    public array $mapLearn = [];
    public $codecName;
    public $frequencyCall;
    public \Closure $onBuildAudio;
    public \libspech\Rtp\rtpChannel $rtpChannel;
    public $bcgChannel;
    public function __construct(mixed $username, mixed $password, mixed $host, mixed $port = 5060, mixed $domain = false)
    {
    }
    public function modelOptions(): array
    {
    }
    public static function extractVia(string $line): array
    {
    }
    public static function getWavDuration($file): string
    {
    }
    public function mountLineCodecSDP(string $codec = 'PCMA/8000'): array
    {
    }
    public function defineCodecs(array $codecs = [8, 101]): void
    {
    }
    public static function codecsMapper(array $codecs = ['PCMA', 'PCMU', 'RTP2833']): array
    {
    }
    public function __invoke()
    {
    }
    public function removeMember(string $username): void
    {
    }
    public function isMember(string $username): bool
    {
    }
    public function saveGlobalInfo(string $key, $value): void
    {
    }
    public function decodePcmuToPcm(string $input): string
    {
    }
    public function proxyMedia(array $options): false|int
    {
    }
    public function record(string $file): void
    {
    }
    public function onFailed(\Closure $callback): void
    {
    }
    public function onAnswer(callable $callback): void
    {
    }
    public function onRinging(\Closure $param): void
    {
    }
    public function volumeAverage(string $pcm): float
    {
    }
    public function send2833($digits, int $durationMs = 200, int $volume = 10): void
    {
    }
    public function call(string $to, $maxRings = 120): bool
    {
    }
    public function modelInvite(string $to, $prefix = "", $options = []): array
    {
    }
    public static function getSDPModelCodecs(array $sdpAttributes): array
    {
    }
    public static function parseArgumentRtpMap(string $line): array
    {
    }
    /**
     * @throws RandomException
     */
    public static function renderURI(array $uriData): string
    {
    }
    public function checkAuthHeaders(array $headers)
    {
    }
    public function ackModel(array $headers): array
    {
    }
    public static function extractURI($line): array
    {
    }
    public function unblockCoroutine(): bool
    {
    }
    public bool|\libspech\Rtp\MediaChannel $mediaChannel;
    public function receiveMedia(): void
    {
    }
    public ?\Closure $onDtmfCallable;
    public function onKeyPress(callable $callback): void
    {
    }
    public function addMember(string $username): void
    {
    }
    public function onBeforeAudioBuild(\Closure $closure): void
    {
    }
    public function getModelCancel($called = false): array
    {
    }
    public function close(): void
    {
    }
    public function __destruct()
    {
    }
    public function decodePcmaToPcm(string $input): string
    {
    }
    public function onHangup(callable $callback): void
    {
    }
    public function bye(): void
    {
    }
    public function addListener(mixed $receiveIp, string $receivePort): void
    {
    }
    public function defineTimeout(int $time): void
    {
    }
    public function extractRTPPayload(string $packet): ?string
    {
    }
    public function PCMToPCMUConverter(string $pcmData): string
    {
    }
    public function linearToPCMU(int $pcm): int
    {
    }
    public function setCallId(string $callId): void
    {
    }
    public function setCallerId(string $callerId): void
    {
    }
    public function declareVolume($ipPort, $user, $c): void
    {
    }
    public function register(int $maxWait = 5): bool
    {
    }
    public function sendDtmf(string $digit): bool
    {
    }
    public function saveBufferToWavFile(string $caminho, string $audioBuffer): void
    {
    }
    public function mixPcmArray(array $chunks): string
    {
    }
    public function registerByeRecovery(array $byeClient, array $destination, $socketPreserve): void
    {
    }
    /**
     * Verifica se o proxy media já está ativo para esta chamada
     */
    public function isProxyMediaActive(): bool
    {
    }
    /**
     * Obtém o ID do proxy ativo
     */
    public function getProxyId(): ?string
    {
    }
    /**
     * Força a parada do proxy media
     */
    public function stopProxyMedia(): void
    {
    }
    public function clearAudioBuffer(): void
    {
    }
    public function registerDtmfCallback(string $dtmf, callable $callback): void
    {
    }
    public function resetTimeout(): void
    {
    }
    public function transfer(string $to): ?bool
    {
    }
    public function transferGroup(string $groupName, $retry = 0)
    {
    }
    public function onReceiveAudio(\Closure $param)
    {
    }
    public function setAudioFile(string $audioFile)
    {
    }
    public function defineAudioFile(string $audioFile): void
    {
    }
    /**
     * Extrai PCM bruto e informações do arquivo WAV
     * @param string $wavFile Caminho do arquivo WAV
     * @return array ['pcm' => string, 'sampleRate' => int, 'bitsPerSample' => int, 'numChannels' => int, 'chunkSize' => int]
     */
    public function loadWavFile(string $wavFile): array
    {
    }
    public function getCid()
    {
    }
    /**
     * Gera um pacote RTP DTMF conforme RFC 2833.
     */
    public function generateDtmfPacket(string $dtmf, bool $endOfEvent = false, int $volume = 0x0, int $duration = 400): string
    {
    }
}
namespace libspech\Network;

class network
{
    public static function getLocalIp(): ?string
    {
    }
    public static function isPrivateIp(?string $ip): bool
    {
    }
    public static function isPublicIp(?string $ip): bool
    {
    }
    public static function getFreePort($type = 'udp'): ?int
    {
    }
    public static function isPortAvailable(int $port, $type = 'udp'): bool
    {
    }
    /** retorna o ip resolvido caso seja 127.0.0.1 por exemplo, retornará 10.0.2.6 */
    public static function resolveAddress(mixed $address): ?string
    {
    }
}
namespace libspech\Rtp;

class rtpChannel
{
    public const RTP_VERSION = 2;
    public const RTP_HEADER_FORMAT = 'CCnNN';
    public const RTP_HEADER_SIZE = 12;
    public const FINAL_PACKET_COUNT = 3;
    // Tipos de payload de áudio
    public const PAYLOAD_PCMU = 0;
    // G.711 µ-law
    public const PAYLOAD_PCMA = 8;
    // G.711 A-law
    public const PAYLOAD_G729 = 18;
    // G.729
    public const PAYLOAD_DTMF = 101;
    // RFC 2833 DTMF
    public int $payloadType;
    public int $sampleRate;
    public int $packetTimeMs;
    public int $samplesPerPacket;
    public int $sequenceNumber;
    public int $timestamp;
    public int $ssrc;
    public bool $markerBit;
    public ?int $dtmfStartTimestamp = null;
    public ?\libspech\Rtp\DtmfEvent $currentDtmfEvent = null;
    public \bcg729Channel $bcg729Channel {
        get {
            return $this->bcg729Channel;
        }
        set {
            $this->bcg729Channel = $value;
        }
    }
    public \opusChannel $opusChannel;
    public function __construct(int $payloadType = self::PAYLOAD_PCMU, int $sampleRate = 8000, int $packetTimeMs = 20, ?int $ssrc = null)
    {
    }
    public function setNewPtDTMF(int $v): void
    {
    }
    public function validatePayloadType(int $payloadType): void
    {
    }
    public function validateSampleRate(int $sampleRate): void
    {
    }
    public function validatePacketTime(int $packetTimeMs): void
    {
    }
    public function setSsrc(int $ssrc): void
    {
    }
    public function setPayloadType(int $payloadType): void
    {
    }
    public function setSampleRate(int $sampleRate): void
    {
    }
    public function setFrequency(int $frequency): void
    {
    }
    public function setMarkerBit(bool $marker = true): void
    {
    }
    public function buildAudioPacket(string $audioPayload, bool $incrementTimestamp = true): string
    {
    }
    /**
     * Constrói um pacote RTP para forward de DTMF (telephone-event)
     * Mantém o timestamp original do evento DTMF e usa o PT de telephone-event correto
     *
     * @param string $dtmfPayload Payload raw do pacote DTMF (4 bytes: event, flags, duration)
     * @param int $dtmfTimestamp Timestamp original do evento DTMF (deve ser constante durante o evento)
     * @param bool $markerBit Se true, define o marker bit (primeiro pacote do evento)
     * @return string Pacote RTP completo para envio
     */
    public function buildDtmfForwardPacket(string $dtmfPayload, int $dtmfTimestamp, bool $markerBit = false): string
    {
    }
    public function buildRtpHeader(int $payloadType, int $timestamp): string
    {
    }
    public function finalizeDtmfSequence(): array
    {
    }
    public function sendFinalDtmfPackets(\Closure $packetSender, int $packetIntervalMs = 0, $extra = false): void
    {
    }
    public function resetDtmfState(): void
    {
    }
    public function generateDtmfSequence(string $dtmfString, int $eventDurationMs = 100): array
    {
    }
    public function sendDtmfSequence(string $dtmfString, callable $packetSender, callable $finalPacketSender, int $eventDurationMs = 100, int $pauseBetweenDigitsMs = 50, int $packetIntervalMs = 10): void
    {
    }
    public function initializeDtmfEvent(\libspech\Rtp\DtmfEvent $event): void
    {
    }
    public function sendSingleDtmf(string $digit, callable $packetSender, int $eventDurationMs = 80, int $volume = 10): void
    {
    }
    public function rfc2833(string $digit, callable $packetHandler, callable $finalPacketHandler, $extra = null): void
    {
    }
    public function sendMultipleDtmf(array $digits, callable $packetSender, int $intervalMs = 100): void
    {
    }
    public function getChannelInfo(): array
    {
    }
}
class AdaptiveBuffer
{
    public function __construct(string $callId, int $initialBufferMs = 50, int $maxBufferMs = 200)
    {
    }
    /**
     * Habilita o buffer adaptativo
     */
    public function enable(): void
    {
    }
    /**
     * Desabilita o buffer adaptativo
     */
    public function disable(): void
    {
    }
    /**
     * Adiciona um pacote ao buffer
     */
    public function addPacket(array $packetData, array $qualityAnalysis = []): bool
    {
    }
    /**
     * Retira um pacote do buffer quando apropriado
     */
    public function getPacket(float $timeout = 0.02): ?array
    {
    }
    /**
     * Força adaptação baseada em recomendações externas
     */
    public function applyRecommendation(array $recommendation): void
    {
    }
    /**
     * Obtém estatísticas do buffer
     */
    public function getStats(): array
    {
    }
    /**
     * Reset do buffer e métricas
     */
    public function reset(): void
    {
    }
    /**
     * Fecha o buffer
     */
    public function close(): void
    {
    }
}
namespace libspech\Sip;

#[\libspech\Sip\AllowDynamicProperties]
class sip
{
    public static string $data;
    public static function parse($dataString = false): ?array
    {
    }
    protected static function sdpData(string $data): ?array
    {
    }
    public static function normalizeArrayKey(string $nameKey, string $normalized, array $data): array
    {
    }
    public static function security(array $data, array $info): false|string
    {
    }
    public static function extractVia(string $line): array
    {
    }
    public static function extractURI($line): array
    {
    }
    public static function getTrunkByUserFromDatabase($user): ?array
    {
    }
    public static function generateResponse($username, $realm, $password, $nonce, $uri, $method): string
    {
    }
    public static function generateAuthorizationHeader($username, $realm, $password, $nonce, $uri, $method): string
    {
    }
    public static function liteSecurity(mixed $data): false|string
    {
    }
    public static function findUsername(string $user): ?array
    {
    }
    public static function findUsernameByAddress(string $address): ?array
    {
    }
    public static function teachVia($user, $info): string
    {
    }
    public static function getVia(array $headers): ?array
    {
    }
    public static function csq(mixed $stringOrArray): string
    {
    }
    public static function letters(string $string): string
    {
    }
    public static function expireEvent(callable $callback)
    {
    }
    public static function findUserByAddress(array $address): ?string
    {
    }
    public static function processRtpPacket(string $packet): void
    {
    }
    public static function extractDtmfEvent(string $payload): array
    {
    }
    public static function loadBestCallerId(mixed $number): string
    {
    }
    public static function generateResponseProxy(string $username, string $password, string $realm, string $nonce, string $uri, string $method, string $qop = "auth", string $nc = "00000001", ?string $cnonce = null): string
    {
    }
    public static function renderSolution(array $solution): string
    {
    }
    public static function extractDtmfDetails(string $packet): array
    {
    }
    public static function getTrunkById(mixed $param)
    {
    }
    public function resolveHandler(): ?bool
    {
    }
    public function method(): ?string
    {
    }
    public static function renderURI(array $uriData): string
    {
    }
}
namespace libspech\Rtp;

class DtmfEvent
{
    // Dígitos 0-9
    public const int DTMF_0 = 0;
    public const int DTMF_1 = 1;
    public const int DTMF_2 = 2;
    public const int DTMF_3 = 3;
    public const int DTMF_4 = 4;
    public const int DTMF_5 = 5;
    public const int DTMF_6 = 6;
    public const int DTMF_7 = 7;
    public const int DTMF_8 = 8;
    public const int DTMF_9 = 9;
    // Símbolos especiais
    public const int DTMF_STAR = 10;
    // *
    public const int DTMF_HASH = 11;
    // #
    // Letras A- int
    public const int DTMF_A = 12;
    public const int DTMF_B = 13;
    public const int DTMF_C = 14;
    public const int DTMF_D = 15;
    /**
     * @param int $event Código do evento DTMF (0-15)
     * @param int $volume Volume do evento (0-63, padrão 10)
     * @param int $duration Duração em amostras de timestamp
     * @throws InvalidArgumentException
     */
    public function __construct(int $event, int $volume = 10, int $duration = 0)
    {
    }
    /**
     * Converte caractere para código de evento DTMF
     */
    public static function charToEvent(string $char): int
    {
    }
    public function markEnd(): void
    {
    }
    public function setDuration(int $duration): void
    {
    }
    public function generatePayload(): string
    {
    }
    public function setEnd(bool $param): void
    {
    }
}
class MediaChannel
{
    public bool $active = true;
    public int $connectTimeout = 10;
    public function onReceive(callable $callback): void
    {
    }
    public function onDtmf(callable $callback): void
    {
    }
    public function onVadChange(callable $callback): void
    {
    }
    /**
     * Habilita o sistema de adaptação automática
     */
    public function enableAdaptation(bool $useBuffer = true): void
    {
    }
    /**
     * Desabilita o sistema de adaptação automática
     */
    public function disableAdaptation(): void
    {
    }
    public \Swoole\Coroutine\Socket $socket;
    /**
     * Array de membros com estrutura:
     * [
     *     'address' => string,
     *     'port' => int,
     *     'codec' => string,
     *     'pt' => int,
     *     'ssrc' => int,
     *     'timestamp' => int,
     *     'config' => array,
     *     'opus' => ?opusChannel,
     *     'frequency' => int,
     *     'rtpChannel' => rtpChannel
     * ]
     *
     * @var array<string, array{address: string, port: int, codec: string, pt: int, ssrc: int, timestamp: int, config: array, opus: ?opusChannel, frequency: int, rtpChannel: rtpChannel}>
     */
    public array $members = [];
    public int $defaultCodec = 8;
    public \bcg729Channel $channelEncode;
    public \bcg729Channel $channelDecode;
    public array $ptCodecs = [
        18 => 'G729',
        // G729
        101 => 'telephone-event',
    ];
    public array $ptCodecsFrequency = [
        'G729' => 8000,
        // G729
        'telephone-event' => 8000,
    ];
    public ?\opusChannel $opusChannel = null;
    public string $callId;
    public array $codecMapper = [];
    public $onReceiveCallable = null;
    public $onDtmfCallable = null;
    public $onVadChangeCallable = null;
    public $onRecordingCallable = null;
    public bool $vadEnabled = false;
    public bool $isVoiceActive = false;
    public bool $recordingEnabled = false;
    // Cache para detectar retransmissões RFC 4733
    public array $audioMetrics = ['total_packets' => 0, 'lost_packets' => 0, 'avg_energy' => 0.0, 'voice_time' => 0.0, 'silence_time' => 0.0];
    public array $registeredIds = [];
    public array $rChannels = [];
    public function block($callback = null): void
    {
    }
    public function unblock(): void
    {
    }
    public function mixPcmArray(array $chunks): string
    {
    }
    public array $rtpChans = [];
    public \Swoole\Coroutine\Socket $eventSock;
    public function __construct(\Swoole\Coroutine\Socket $socket, string $callId)
    {
    }
    public function resolveCodecNameFromPt(int $pt): ?string
    {
    }
    public function resolveFrequencyFromPt(int $pt): int
    {
    }
    public function enableVAD(float $threshold = 2.0): void
    {
    }
    /**
     * Define o valor mínimo de energia para registrar um ID pelo VAD
     */
    public function setVadRegistrationThreshold(float $threshold): void
    {
    }
    /**
     * Define o timeout em segundos para IDs registrados pelo VAD
     */
    public function setVadTimeout(int $timeoutSeconds): void
    {
    }
    public array $openChannels = [];
    public int $portList = 0;
    public array $options = [];
    public int $retrys = 0;
    public mixed $ssrc = 0;
    public function generateDeterministicSsrc(string $ipPort): int
    {
    }
    public function start(): void
    {
    }
    public function isMember(string $id): bool
    {
    }
    public function addMember(array $peer): void
    {
    }
    public function close(): void
    {
    }
    public function registerPtCodecs(array $ptCodecs): void
    {
    }
    public function packetOnTimeout(callable $param): void
    {
    }
    public mixed $packetOnTimeoutCallable = false;
    public function getFrequencyFromPtCodec(int $pt)
    {
    }
}
class rtpc
{
    public string $rawPacket = '';
    public int $version = 2;
    public int $padding = 0;
    public int $extension = 0;
    public int $cc = 0;
    public int $marker = 0;
    public int $payloadType = 0;
    public int $sequence = 0;
    public int $timestamp = 0;
    public int $ssrc = 0;
    public string $payloadRaw = '';
    public function __construct(?string $packet)
    {
    }
    public function getCodec(): int
    {
    }
    public function __destruct()
    {
    }
    public function setPayloadType($payloadType = 0): void
    {
    }
    public function setSequence(int $sequence): void
    {
    }
    public function setTimestamp(int $timestamp): void
    {
    }
    public function setSsrc(int $ssrc): void
    {
    }
    public function setMarker(int $marker): void
    {
    }
    /**
     * Constrói o pacote RTP completo com o payload codificado
     * usando os valores atuais do cabeçalho
     *
     * @param false|string $encoded O payload codificado a ser adicionado ao cabeçalho RTP
     * @return false|string Pacote RTP completo ou false em caso de erro
     */
    public function build(false|string $encoded): string
    {
    }
    public function verbose(): void
    {
    }
    public function getSequence()
    {
    }
}
namespace libspech\Cache;

class cache
{
    public static function arrayShift(string $key): mixed
    {
    }
    public static function get(string $nameKey): mixed
    {
    }
    public static function set(string $string, mixed $queue): void
    {
    }
    public static function join(string $key, $value): bool
    {
    }
    public static function subJoin(string $key, string $subKey, mixed $value): bool
    {
    }
    public static function subDefine(string $key, string $subKey, mixed $value): void
    {
    }
    public static function findConnection($username): ?array
    {
    }
    public static function getConnections(): array
    {
    }
    public static function global(): ?array
    {
    }
    public static function deleteConnection($username): void
    {
    }
    public static function updateConnections(mixed $connections): void
    {
    }
    public static function persistExpungeCall(string $callId, $warning = false): void
    {
    }
    public static function define(string $key, mixed $value): bool
    {
    }
    public static function increment(string $key): void
    {
    }
    public static function decrement(string $key): void
    {
    }
    public static function countCallsByUser(string $username, \libspech\Cache\ServerSocket $socket): int
    {
    }
    public static function unset(string $key, string $subKey): void
    {
    }
    public static function sum(string $keyIntCounter, mixed $value): void
    {
    }
}
class rpcClient
{
    protected string $host;
    protected int $port;
    protected \Swoole\Coroutine\Socket $socket;
    protected array $pendingRequests = [];
    protected bool $isRunning = false;
    public function __construct(string $host = '127.0.0.1', int $port = 9503)
    {
    }
    /**
     * Inicia corrotina para receber respostas UDP
     */
    protected function startReceiveLoop(): void
    {
    }
    /**
     * Processa resposta recebida e resolve a requisição pendente
     */
    protected function handleResponse(string $data): void
    {
    }
    /**
     * Gera token único para requisição
     */
    protected function generateToken(): string
    {
    }
    public function rpcSet(string $cid, array $data): ?string
    {
    }
    protected function sendRaw(array $request, int $timeout = 5, int $maxRetries = 3): ?string
    {
    }
    public function rpcGet(string $cid): ?string
    {
    }
    public function rpcDelete(string $cid): ?string
    {
    }
    public function rpcGetNonRunning(): ?string
    {
    }
    /**
     * Limpa requisições pendentes antigas (mais de 30 segundos)
     */
    public function cleanupPendingRequests(): void
    {
    }
    /**
     * Para o loop de recepção e fecha o socket
     */
    public function stop(): void
    {
    }
    /**
     * Método de fechamento manual
     */
    public function close(): void
    {
    }
    /**
     * Destructor para garantir que o socket seja fechado
     */
    public function __destruct()
    {
    }
}
namespace libspech\Cli;

class cli
{
    const MENU = "(l) Listar conexões          \n(t) Listar troncos           \n(c) Listar contas            \n(b) Banir IP                 \n(d) Desbanir IP              \n(i) Listar IPs banidos\n(p) Trocar Porta do Servidor Web\n(e) Executar EVAL-CODE\n(r) Reiniciar Servidor Web\n(a) Listar chamadas\n(x) Permitir debug        \n(q) Encerrar servidor        " . PHP_EOL;
    public static function show(): void
    {
    }
    public static function color($color, $message): string
    {
    }
    public static function menuCallback($menuCallback): void
    {
    }
    public static function pcl(string $message, string $color = 'white'): void
    {
    }
    public static function cl(string $color, string $message): string
    {
    }
}
namespace libspech\Packet;

class renderMessages
{
    public static function generateBye(array $headers200)
    {
    }
    public static function respondUserNotFound(array $headers, $optionalMessage = "Tente novamente"): string
    {
    }
    public static function baseResponse(array $headers, string $statusCode, string $statusMessage, array $additionalHeaders = []): string
    {
    }
    public static function respondForbidden(array $headers, string $message = "Forbidden"): string
    {
    }
    public static function respondOptions(array $headers): string
    {
    }
    public static function respond100Trying(array $headers, $statusMessage = 'Trying...'): string
    {
    }
    public static function respond202Accepted(array $headers): string
    {
    }
    public static function respondAckModel(array $headers): string
    {
    }
    public static function respond200OK(array $headers, string $body = ""): string
    {
    }
    public static function modelBye(mixed $byeNumber, mixed $callId, ?string $localIp, mixed $from, mixed $to, mixed $csq, string $authorization)
    {
    }
    public static function respond486Busy(mixed $backupHeaders, $message = "Busy Here"): string
    {
    }
    public static function respond487RequestTerminated(mixed $backupHeaders)
    {
    }
    public static function e503Un(mixed $headers, $message = "Tente novamente"): string
    {
    }
    public static function e491RequestPending(mixed $headers, $message = "Request Pending"): string
    {
    }
    public static function modelMessage(array $headers, $message = "")
    {
    }
    public static function generateModelOptions(array $headers, $respondPort): array
    {
    }
}
namespace libspech\Sip;

function secureAudioVoip(string $filename, bool $forceMono = true): bool
{
}
function wavChunks(string $file)
{
}
function getInfoAudio(string $filename): array
{
}
/**
 * Calcula o tamanho do chunk PCM para um determinado sample rate e duração
 *
 * @param int $sampleRate Taxa de amostragem (Hz)
 * @param int $channels Número de canais (1=mono, 2=stereo)
 * @param int $bitsPerSample Bits por sample (8, 16, 24, 32)
 * @param float $durationMs Duração em milissegundos (padrão: 20ms)
 * @return int Tamanho do chunk em bytes
 *
 * Exemplos:
 * - 8kHz, mono, 16-bit, 20ms = 320 bytes
 * - 16kHz, mono, 16-bit, 20ms = 640 bytes
 * - 48kHz, mono, 16-bit, 20ms = 1920 bytes
 */
function calculateChunkSize(int $sampleRate, int $channels = 1, int $bitsPerSample = 16, float $durationMs = 20.0): int
{
}
/**
 * Normaliza o volume de um buffer PCM 16-bit little-endian
 * Útil após resampling para evitar clipping/distorção
 *
 * @param string $pcmData Buffer PCM 16-bit LE
 * @param float $targetPeak Pico alvo (0.0 a 1.0, padrão 0.85 = -1.4dB)
 * @return string PCM normalizado
 */
function normalizePcm(string $pcmData, float $targetPeak = 0.85): string
{
}
/**
 * Aplica atenuação simples em um buffer PCM (reduz volume)
 *
 * @param string $pcmData Buffer PCM 16-bit LE
 * @param float $gain Ganho (0.0 a 1.0), ex: 0.5 = -6dB
 * @return string PCM atenuado
 */
function attenuatePcm(string $pcmData, float $gain = 0.7): string
{
}
function secure_random_bytes(int $length): string
{
}
function encodePcmToPcma(string $data): string
{
}
function encodePcmToPcmu(string $data): string
{
}
/**
 * Sleep interrompível que verifica condições a cada 100ms
 * Permite que operações longas sejam interrompidas rapidamente
 *
 * @param int $ms Milissegundos para dormir
 * @param trunkController|null $phone Instância do telefone para verificar closing/error
 * @return bool Retorna true se completou, false se foi interrompido
 */
function interruptibleSleep(int $sec, &$abort): bool
{
}
namespace libspech\Sip;

function generateWavHeaderUlaw(int $dataLength, int $sampleRate = 8000, int $channels = 1): string
{
}
function getHost($address): string
{
}
function getPort($address): string
{
}
function arrayToString($array): string
{
}
function mixAudios(array $inputFiles, string $outputFile): bool
{
}
function extractRTPPayload(string $packet): ?string
{
}
function waveHead3(int $dataLength, int $sampleRate, int $channels, int $audioFormat): string
{
}
function waveHead(int $dataLength, int $sampleRate, int $channels, int $audioFormat): string
{
}
function generateWavHeader(int $dataSize, int $sampleRate, int $channels): string
{
}
function linear2ulaw(int $pcm_val): int
{
}
function linear2alaw(int $pcm_val): int
{
}
function searchSegment(int $val, array $table): int
{
}
function ulaw2linear(int $u_val): int
{
}
function alaw2linear(int $a_val): int
{
}
function alaw2ulaw(int $aval): int
{
}
function ulaw2alaw(int $uval): int
{
}
function pcm2ulaw_(string $pcm): string
{
}
function pcmToUlaw(string $pcm): string
{
}
function ulawToPcm(string $ulaw): string
{
}
function pcmToAlaw(string $pcm): string
{
}
function alawToPcm(string $alaw): string
{
}
function alawToUlaw(string $alaw): string
{
}
function ulawToAlaw(string $ulaw): string
{
}
function volumeAverage(string $pcm): float
{
}
function traduzDTMF(int $evento): string
{
}
function gerarDTMF_PCM(string $digito, float $duracao = 0.2, int $sampleRate = 8000): string
{
}
/**
 * Decodifica dados L16 Big Endian para PCM Little Endian
 *
 * @param string $l16Data Dados L16 em formato big endian
 * @return string Dados PCM em formato little endian
 */
function l16BigEndianToPcm(string $l16Data): string
{
}
function resampleDemoForFastDebug($pcm, $src, $dst, $ptime, $channels = 1): string
{
}