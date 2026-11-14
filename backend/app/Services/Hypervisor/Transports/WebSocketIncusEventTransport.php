<?php

declare(strict_types=1);

namespace App\Services\Hypervisor\Transports;

use App\Exceptions\FingerprintMismatchException;
use App\Exceptions\MissingPinException;
use App\Models\Host;
use App\Services\Hypervisor\Contracts\IncusEventTransportInterface;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\Hypervisor\TlsPeerCertificateFetcherInterface;
use App\Services\PanelCertificateService;
use Illuminate\Support\Facades\Log;
use OpenSSLCertificate;
use Override;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use RuntimeException;

/**
 * WebSocket transport connecting to Incus /1.0/events?type=lifecycle&all-projects=true with mTLS.
 */
class WebSocketIncusEventTransport implements IncusEventTransportInterface
{
    public function __construct(
        protected readonly ?PanelCertificateService $certService = null,
        protected readonly ?TlsPeerCertificateFetcherInterface $tlsPeerFetcher = null,
    ) {}

    #[Override]
    public function listen(Host $host, callable $onEvent, ?callable $shouldStop = null): void
    {
        $retryCount = 0;
        $maxDelay = 60;

        while ($shouldStop === null || ! $shouldStop()) {
            try {
                $this->connectAndStream($host, $onEvent, $shouldStop);
                $retryCount = 0; // Reset counter on normal connection lifecycle
            } catch (\Throwable $e) {
                $retryCount++;
                $delay = min((int) (2 ** $retryCount), $maxDelay);

                Log::warning(sprintf(
                    'Incus event stream disconnected from host [%s] (%s): %s. Reconnecting in %ds...',
                    $host->name,
                    $host->endpoint_url,
                    $e->getMessage(),
                    $delay
                ));

                if ($shouldStop !== null && $shouldStop()) {
                    break;
                }

                sleep($delay);
            }
        }
    }

    /**
     * Establish mTLS WebSocket connection and stream events.
     *
     * @param  callable(array<string, mixed>): void  $onEvent
     * @param  (callable(): bool)|null  $shouldStop
     */
    public function connectAndStream(Host $host, callable $onEvent, ?callable $shouldStop = null): void
    {
        $certService = $this->certService ?? app(PanelCertificateService::class);
        $clientCert = $certService->getActiveCertificate() ?? $certService->getRetiringCertificate();

        if ($clientCert === null || ! $clientCert->isUsable()) {
            throw new RuntimeException('No usable panel certificate available for mTLS WebSocket connection.');
        }

        // Parse host endpoint
        $endpoint = $host->endpoint_url;
        $parsed = parse_url($endpoint);
        $targetHost = $parsed['host'] ?? '127.0.0.1';
        $port = isset($parsed['port']) ? (int) $parsed['port'] : 8443;
        $isSsl = ($parsed['scheme'] ?? 'https') === 'https';

        // Write temp cert and key files with 0600 mode for stream context
        $certFile = tempnam(sys_get_temp_dir(), 'incus_ws_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'incus_ws_key_');

        if ($certFile === false || $keyFile === false) {
            throw new RuntimeException('Failed to allocate temporary files for mTLS connection.');
        }

        try {
            file_put_contents($certFile, $clientCert->certificate);
            file_put_contents($keyFile, $clientCert->private_key);
            @chmod($certFile, 0600);
            @chmod($keyFile, 0600);

            $sslOptions = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'capture_peer_cert' => true,
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
            ];

            $context = stream_context_create([
                'ssl' => $sslOptions,
            ]);

            $remoteUri = $isSsl ? sprintf('ssl://%s:%d', $targetHost, $port) : sprintf('tcp://%s:%d', $targetHost, $port);
            $errno = 0;
            $errstr = '';

            $socket = @stream_socket_client(
                $remoteUri,
                $errno,
                $errstr,
                10.0,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($socket === false) {
                throw new RuntimeException(sprintf('Failed to connect to %s: %s', $remoteUri, $errstr ?: 'Connection refused'));
            }

            // Cryptographically verify peer certificate on the SAME connection
            if ($isSsl && $host->driver !== 'fake') {
                $peerCert = $this->capturePeerCertificate($socket);

                if (! ($peerCert instanceof OpenSSLCertificate)) {
                    @fclose($socket);
                    throw new RuntimeException(sprintf('Failed to capture peer certificate for host [%s].', $host->name));
                }

                $storedPin = $host->server_public_key_pin !== null ? trim($host->server_public_key_pin) : '';
                $storedFingerprint = $host->server_fingerprint !== null ? trim($host->server_fingerprint) : '';

                if ($storedPin === '' && $storedFingerprint === '') {
                    $host->status = 'not_trusted';
                    $host->save();
                    @fclose($socket);

                    throw new MissingPinException(sprintf('Host [%s] requires a public key pin in operational mode.', $host->name));
                }

                $pem = '';
                openssl_x509_export($peerCert, $pem);
                /** @var string $pem */
                $pin = HypervisorGateway::computePinFromCertificate($pem);
                $fingerprint = strtolower((string) openssl_x509_fingerprint($pem, 'sha256'));

                $mismatch = false;
                if ($storedPin !== '') {
                    if ($pin !== $storedPin) {
                        $mismatch = true;
                    }
                } else {
                    if ($fingerprint !== strtolower($storedFingerprint)) {
                        $mismatch = true;
                    }
                }

                if ($mismatch) {
                    @fclose($socket);
                    $host->status = 'fingerprint_mismatch';
                    $host->save();

                    throw new FingerprintMismatchException(
                        actualFingerprint: '',
                        expectedFingerprint: '',
                        message: 'Server certificate public key does not match pinned public key.'
                    );
                }
            }

            stream_set_timeout($socket, 5);

            // Send WebSocket upgrade request
            $wsKey = base64_encode(random_bytes(16));
            $hostHeader = $port === 80 || $port === 443 ? $targetHost : sprintf('%s:%d', $targetHost, $port);

            $headers = [
                'GET /1.0/events?type=lifecycle&all-projects=true HTTP/1.1',
                'Host: '.$hostHeader,
                'Upgrade: websocket',
                'Connection: Upgrade',
                'Sec-WebSocket-Key: '.$wsKey,
                'Sec-WebSocket-Version: 13',
            ];

            if ($host->token !== null && $host->token !== '') {
                $headers[] = 'Authorization: Bearer '.$host->token;
            }

            $request = implode("\r\n", $headers)."\r\n\r\n";
            @fwrite($socket, $request);

            // Read HTTP upgrade response
            $responseHeader = '';
            while (! feof($socket)) {
                $line = fgets($socket, 1024);
                if ($line === false || $line === "\r\n" || $line === "\n") {
                    break;
                }
                $responseHeader .= $line;
            }

            if (! str_contains($responseHeader, '101 Switching Protocols')) {
                @fclose($socket);
                throw new RuntimeException('Incus refused WebSocket upgrade: '.trim($responseHeader));
            }

            // Connection upgraded! Now process WebSocket frames
            $checker = new CloseFrameChecker;
            $buffer = new MessageBuffer(
                $checker,
                function (MessageInterface $msg) use ($onEvent): void {
                    $payload = $msg->getPayload();
                    /** @var array<string, mixed>|null $data */
                    $data = json_decode($payload, true);
                    if (is_array($data)) {
                        $onEvent($data);
                    }
                },
                function (FrameInterface $frame) use ($socket): void {
                    if ($frame->getOpcode() === Frame::OP_PING) {
                        $pong = new Frame($frame->getPayload(), true, Frame::OP_PONG);
                        @fwrite($socket, $pong->getContents());
                    }
                },
                false // Server frames sent to client are not masked
            );

            while ($shouldStop === null || ! $shouldStop()) {
                $read = [$socket];
                $write = null;
                $except = null;

                $numChanged = @stream_select($read, $write, $except, 1, 0);
                if ($numChanged === false) {
                    break;
                }

                if ($numChanged > 0) {
                    $chunk = @fread($socket, 8192);
                    if ($chunk === false || $chunk === '') {
                        if (feof($socket)) {
                            break;
                        }
                    } else {
                        $buffer->onData($chunk);
                    }
                }
            }

            @fclose($socket);
        } finally {
            if (file_exists($certFile)) {
                @unlink($certFile);
            }
            if (file_exists($keyFile)) {
                @unlink($keyFile);
            }
        }
    }

    /**
     * Capture peer certificate from stream context options.
     *
     * @param  resource  $socket
     */
    protected function capturePeerCertificate($socket): ?OpenSSLCertificate
    {
        $params = stream_context_get_params($socket);
        $ssl = $params['options']['ssl'] ?? null;
        $peerCert = is_array($ssl) ? ($ssl['peer_certificate'] ?? null) : null;

        return $peerCert instanceof OpenSSLCertificate ? $peerCert : null;
    }
}
