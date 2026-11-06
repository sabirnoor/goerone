<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;

class WhatsAppMediaService
{
    protected $apiVersion;
    protected $baseUrl;

    /**
     * Full path to ffmpeg binary.
     * PHP's web process (www-data) does NOT inherit your shell PATH,
     * so 'ffmpeg' alone will return exit code 127 (not found).
     */
    private const FFMPEG_BIN = '/usr/bin/ffmpeg';

    public function __construct()
    {
        $this->apiVersion = config('services.meta.api_version', 'v24.0');
        $this->baseUrl    = "https://graph.facebook.com/{$this->apiVersion}";
    }

    /* =========================================
     | Upload Media to WhatsApp
     |
     | ROOT-CAUSE FIX:
     | The frontend always sends type=audio for voice notes, but Safari (and
     | some Chromium builds) record into an MP4 container. PHP's getMimeType()
     | does a magic-bytes check and returns "video/mp4" for that container,
     | causing detectType() to return "video" and skipping OGG conversion
     | entirely — the message is then sent as a video, which WhatsApp rejects
     | silently or delivers wrongly.
     |
     | Solution: accept an explicit $requestType from the controller
     | (the "type" field the frontend already sends). If it is "audio", trust
     | it and run the conversion regardless of what PHP thinks the mime is.
     ========================================= */
    public function upload(
        UploadedFile $file,
        string $accessToken,
        string $phoneNumberId,
        ?string $requestType = null   // ← NEW: pass $request->input('type') from controller
    ): string {
        // Detect type from mime first, then let the explicit requestType override.
        // This keeps image/video/document working exactly as before.
        $mimeType     = $this->detectType($file);
        $type         = $requestType ?? $mimeType;

        // Log::info('[WA Media] ── UPLOAD START ──', [
        //     'originalName' => $file->getClientOriginalName(),
        //     'phpMimeType'  => $file->getMimeType(),   // what PHP magic-bytes says
        //     'phpDetected'  => $mimeType,              // what detectType() resolved
        //     'requestType'  => $requestType,           // what the frontend sent
        //     'resolvedType' => $type,                  // what we will actually use
        //     'sizeBytes'    => $file->getSize(),
        //     'realPath'     => $file->getRealPath(),
        //     'phoneNumId'   => $phoneNumberId,
        // ]);

        // ── Audio: always convert to OGG/Opus ──────────────────────────────
        // Safari records audio/mp4 → PHP sees video/mp4 → mime detection fails.
        // Chrome records audio/webm → PHP may see video/webm.
        // In both cases the frontend sends type=audio, so $type is now correct.
        if ($type === 'audio') {
            // Log::info('[WA Media] Audio type confirmed — starting MP3 conversion', [
            //     'originalMime' => $file->getMimeType(),
            // ]);
            $file = $this->convertToMp3($file);
            // Log::info('[WA Media] Conversion complete', [
            //     'convertedPath' => $file->getRealPath(),
            //     'convertedSize' => $file->getSize(),
            //     'convertedMime' => $file->getMimeType(),
            // ]);
        }

        // Mime type for the multipart Content-Type header sent to Meta.
        // audio/mpeg (MP3) is officially supported by WhatsApp Cloud API for voice.
        $uploadMime = $type === 'audio' ? 'audio/mpeg' : $file->getMimeType();
        $fileName   = $type === 'audio' ? 'voice.mp3' : $file->getClientOriginalName();

        $appsecretProof = $this->generateAppsecretProof($accessToken);
        $url = "https://graph.facebook.com/v24.0/{$phoneNumberId}/media?appsecret_proof={$appsecretProof}";

        // Log::info('[WA Media] Sending multipart upload to Meta', [
        //     'url'        => preg_replace('/appsecret_proof=[^&]+/', 'appsecret_proof=***', $url),
        //     'type'       => $type,
        //     'uploadMime' => $uploadMime,
        //     'fileName'   => $fileName,
        // ]);

        $response = Http::withToken($accessToken)
            ->asMultipart()
            ->post($url, [
                [
                    'name'     => 'messaging_product',
                    'contents' => 'whatsapp',
                ],
                [
                    'name'     => 'type',
                    'contents' => $type,
                ],
                [
                    'name'     => 'file',
                    'contents' => fopen($file->getRealPath(), 'r'),
                    'filename' => $fileName,
                    // Explicit Content-Type on the file part — required by Meta API.
                    // Without it, audio uploads are rejected even if 'type'=>'audio' is set.
                    'headers'  => ['Content-Type' => $uploadMime],
                ],
            ]);

        $data = $response->json();

        // Log::info('[WA Media] Meta upload response', [
        //     'httpStatus' => $response->status(),
        //     'mediaId'    => $data['id'] ?? null,
        //     'errorCode'  => $data['error']['code'] ?? null,
        //     'errorMsg'   => $data['error']['message'] ?? null,
        //     'rawBody'    => $response->body(),
        // ]);

        if (!isset($data['id'])) {
            Log::error('[WA Media] ✗ Upload FAILED — no media ID returned', [
                'httpStatus'  => $response->status(),
                'resolvedType' => $type,
                'uploadMime'  => $uploadMime,
                'fileName'    => $fileName,
                'error'       => $data['error'] ?? $response->body(),
            ]);
            throw new \Exception($data['error']['message'] ?? 'Media upload failed');
        }

        // Log::info('[WA Media] ✓ Upload SUCCESS', ['mediaId' => $data['id']]);

        return $data['id'];
    }

    /* =========================================
     | Detect Media Type from MIME
     | NOTE: do NOT rely on this alone for voice notes —
     | Safari/Chrome MP4 audio comes through as video/mp4.
     | Always let the explicit request type override this.
     ========================================= */
    protected function detectType(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        return 'document';
    }

    /* =========================================
     | Get Media Download URL
     ========================================= */
    public function getUrl(string $mediaId, string $accessToken, $user = null): string
    {
        $appsecretProof = $this->generateAppsecretProof($accessToken, $user);
        $response = Http::withToken($accessToken)
            ->get("https://graph.facebook.com/v24.0/{$mediaId}", [
                'appsecret_proof' => $appsecretProof,
            ]);

        $data = $response->json();

        if (!isset($data['url'])) {
            Log::error('[WA Media] Failed to fetch media URL', [
                'mediaId'    => $mediaId,
                'httpStatus' => $response->status(),
                'body'       => $response->body(),
            ]);
            throw new \Exception('Unable to fetch media URL');
        }

        return $data['url'];
    }

    /* =========================================
     | Get Media Metadata (URL + mime_type etc.)
     ========================================= */
    public function getMeta(string $mediaId, string $accessToken): array
    {
        // Log::info('[WA Media] Fetching media meta', ['mediaId' => $mediaId]);

        $appsecretProof = $this->generateAppsecretProof($accessToken);
        $response = Http::withToken($accessToken)
            ->get("https://graph.facebook.com/v24.0/{$mediaId}", [
                'appsecret_proof' => $appsecretProof,
            ]);

        if (!$response->successful()) {
            Log::error('[WA Media] Failed to fetch media meta', [
                'mediaId'    => $mediaId,
                'httpStatus' => $response->status(),
                'body'       => $response->body(),
            ]);
            throw new \Exception('Unable to fetch media metadata');
        }

        $meta = $response->json();

        // Log::info('[WA Media] Media meta fetched OK', [
        //     'mediaId'  => $mediaId,
        //     'mimeType' => $meta['mime_type'] ?? 'unknown',
        //     'fileSize' => $meta['file_size'] ?? 'unknown',
        // ]);

        return $meta;
    }

    /* =========================================
     | Convert Audio → MP3 (WhatsApp voice)
     |
     | Switched from OGG/Opus to MP3 because OGG was uploading successfully
     | (Meta returned a mediaId) but messages were silently not delivering.
     | WhatsApp Cloud API officially supports audio/mpeg (MP3) and it delivers
     | reliably across all device types.
     ========================================= */
    private function convertToMp3(UploadedFile $file): UploadedFile
    {
        // Log::info('[WA Media] [FFmpeg] ── CONVERSION START ──', [
        //     'ffmpegBin'   => self::FFMPEG_BIN,
        //     'binExists'   => file_exists(self::FFMPEG_BIN) ? 'YES' : 'NO ← THIS IS THE PROBLEM',
        //     'inputPath'   => $file->getRealPath(),
        //     'inputMime'   => $file->getMimeType(),
        //     'inputSize'   => $file->getSize(),
        //     'tmpDir'      => sys_get_temp_dir(),
        //     'tmpWritable' => is_writable(sys_get_temp_dir()) ? 'YES' : 'NO ← THIS IS THE PROBLEM',
        // ]);

        if (!file_exists(self::FFMPEG_BIN)) {
            Log::error('[WA Media] [FFmpeg] Binary not found', ['path' => self::FFMPEG_BIN]);
            throw new \Exception(
                'ffmpeg not found at ' . self::FFMPEG_BIN . '. Run: which ffmpeg on the server.'
            );
        }

        $inputPath  = $file->getRealPath();
        $outputPath = sys_get_temp_dir() . '/' . uniqid('voice_') . '.mp3';

        // Log::info('[WA Media] [FFmpeg] Paths resolved', [
        //     'inputPath'  => $inputPath,
        //     'outputPath' => $outputPath,
        // ]);

        register_shutdown_function(function () use ($outputPath) {
            if (file_exists($outputPath)) {
                Log::debug('[WA Media] [FFmpeg] Cleaning up temp file', ['path' => $outputPath]);
                @unlink($outputPath);
            }
        });

        // ── Audio compression pipeline ───────────────────────────────────────
        //
        // Goal: smallest possible file that still sounds clear for voice.
        //
        // Filter chain (applied in order):
        //
        //  highpass=f=80
        //      Cut frequencies below 80 Hz (rumble, handling noise, wind).
        //      Human voice starts at ~85 Hz — nothing useful is lost.
        //
        //  lowpass=f=8000
        //      Cut frequencies above 8 kHz. Voice intelligibility lives in
        //      300–3400 Hz; 8 kHz is generous. Removes hiss and background noise
        //      that wastes bits without helping clarity.
        //
        //  afftdn=nf=-25
        //      FFT-based noise reduction. Suppresses steady-state background noise
        //      (fan hum, AC, road noise). nf=-25 dB is conservative.
        //
        //  acompressor=threshold=-18dB:ratio=4:attack=5:release=50:makeup=3
        //      Dynamic range compressor — levels out loud/quiet speech so the
        //      encoder can target a lower average bitrate.
        //
        //  loudnorm=I=-16:TP=-1.5:LRA=11
        //      EBU R128 loudness normalisation for consistent perceived volume.
        //
        // Encoding (MP3 via libmp3lame):
        //  -acodec libmp3lame : MP3 encoder, universally supported
        //  -b:a 64k           : 64 kbps — plenty for voice, keeps file small
        //  -ar 44100          : 44.1 kHz sample rate (MP3 standard)
        //  -ac 1              : mono — voice notes are always mono
        //
        // ────────────────────────────────────────────────────────────────────────
        $filterChain = implode(',', [
            'highpass=f=80',
            'lowpass=f=8000',
            'afftdn=nf=-25',
            'acompressor=threshold=-18dB:ratio=4:attack=5:release=50:makeup=3',
            'loudnorm=I=-16:TP=-1.5:LRA=11',
        ]);

        $command = sprintf(
            '%s -y -i %s -vn -af %s -acodec libmp3lame -b:a 64k -ar 44100 -ac 1 %s 2>&1',
            self::FFMPEG_BIN,
            escapeshellarg($inputPath),
            escapeshellarg($filterChain),
            escapeshellarg($outputPath)
        );

        // Log::info('[WA Media] [FFmpeg] Executing', ['command' => $command]);

        $output     = [];
        $returnCode = -1;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);

        // Log::info('[WA Media] [FFmpeg] exec() result', [
        //     'returnCode'      => $returnCode,
        //     // 0   → success
        //     // 1   → ffmpeg error (bad input format, missing codec, etc.)
        //     // 127 → binary not in PATH — make sure FFMPEG_BIN is the full path
        //     'outputExists'    => file_exists($outputPath) ? 'YES' : 'NO',
        //     'outputSizeBytes' => file_exists($outputPath) ? filesize($outputPath) : 0,
        //     'ffmpegOutput'    => $outputStr,
        // ]);

        if ($returnCode !== 0 || !file_exists($outputPath) || filesize($outputPath) === 0) {
            Log::error('[WA Media] [FFmpeg] ✗ Conversion FAILED', [
                'returnCode'   => $returnCode,
                'command'      => $command,
                'ffmpegOutput' => $outputStr,
                'inputMime'    => $file->getMimeType(),
                'inputSize'    => $file->getSize(),
            ]);
            throw new \Exception(
                'Audio conversion failed (exit ' . $returnCode . '). Check laravel.log for details.'
            );
        }

        $outSize  = filesize($outputPath);
        $inSize   = $file->getSize();
        $savedPct = $inSize > 0 ? round((1 - $outSize / $inSize) * 100, 1) : 0;

        // Log::info('[WA Media] [FFmpeg] ✓ Conversion SUCCESS', [
        //     'outputPath'      => $outputPath,
        //     'inputSizeBytes'  => $inSize,
        //     'outputSizeBytes' => $outSize,
        //     'savedPercent'    => "{$savedPct}%",
        // ]);

        return new UploadedFile(
            $outputPath,
            'voice.mp3',
            'audio/mpeg',
            null,
            true  // test mode — skips is_uploaded_file() check on temp path
        );
    }

    /* =========================================
     | Generate appsecret_proof HMAC
     ========================================= */
    // private function generateAppsecretProof(string $accessToken): string
    // {
    //     $appSecret = meta_app_secret();

    //     if (empty($appSecret)) {
    //         Log::error('[WA Media] META_APP_SECRET is not configured');
    //         throw new \Exception('Meta App Secret is not configured');
    //     }

    //     return hash_hmac('sha256', $accessToken, $appSecret);
    // }

    private function generateAppsecretProof(string $accessToken, $user = null): string
    {
        // Log::info('🔐 generateAppsecretProof START', [
        //     'has_user' => !empty($user),
        //     'has_user_secret' => !empty($user?->details?->META_APP_SECRET)
        // ]);

        try {

            if (!empty($user?->details?->META_APP_SECRET)) {
                $appSecret = $user->details->META_APP_SECRET;
            } else {
                $appSecret = meta_app_secret();
            }

            if (empty($appSecret)) {
                Log::error('❌ Meta App Secret EMPTY');
                throw new \Exception('Meta App Secret is not configured');
            }

            $proof = hash_hmac('sha256', $accessToken, $appSecret);

            return $proof;
        } catch (\Throwable $e) {

            Log::error('❌ generateAppsecretProof FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}
