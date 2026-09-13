<?php
/**
 * 2TOOLNE TTS — TEXT-TO-SPEECH & VOICE CLONING CONTROLLER
 * Handles public client requests and worker orchestration endpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../storage/CloudAuthHelper.php';
require_once __DIR__ . '/../services/TtsJobService.php';
require_once __DIR__ . '/../services/TtsVoiceService.php';
require_once __DIR__ . '/../services/TtsBillingService.php';

class TtsController {

    /**
     * Helper to authenticate GPU worker requests.
     */
    private static function requireWorkerAuth(): array {
        $user = CloudAuthHelper::getCurrentUser();
        if ($user) {
            if (!empty($user['is_admin']) || !empty($user['is_worker'])) {
                return $user;
            }
            if (!empty($user['ai_access_key']['scopes'])) {
                $scopes = $user['ai_access_key']['scopes'];
                if (in_array('tts.worker', $scopes, true) || in_array('*', $scopes, true)) {
                    return $user;
                }
            }
        }

        Router::error('Worker authentication required (tts.worker scope or worker credentials)', 403, 'FORBIDDEN');
        exit;
    }

    // =========================================================================
    // PUBLIC CLIENT ENDPOINTS
    // =========================================================================

    /**
     * POST /api/v1/tts/jobs
     */
    public static function createJob(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        try {
            $job = TtsJobService::createJob($user, $body);
            Router::json([
                'success' => true,
                'job' => $job,
            ], 201);
        } catch (InvalidArgumentException $e) {
            Router::error($e->getMessage(), 400, 'INVALID_ARGUMENT');
        } catch (Throwable $e) {
            Router::error($e->getMessage(), 500, 'TTS_JOB_CREATE_FAILED');
        }
    }

    /**
     * GET /api/v1/tts/jobs
     */
    public static function listJobs(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $filters = [
            'status' => $_GET['status'] ?? null,
            'active' => !empty($_GET['active']),
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 20,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0,
        ];

        $res = TtsJobService::listJobs((string)$user['id'], $filters);
        Router::json(array_merge(['success' => true], $res));
    }

    /**
     * GET /api/v1/tts/jobs/{id}
     */
    public static function getJob(array $params, array $body): void {
        $jobId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $isAdminOrWorker = !empty($user['is_admin']) || !empty($user['is_worker']);
        $userIdFilter = $isAdminOrWorker ? null : (string)$user['id'];

        $job = TtsJobService::getJob($jobId, $userIdFilter);
        if (!$job) {
            Router::error('TTS job not found or unauthorized', 404, 'NOT_FOUND');
        }

        Router::json([
            'success' => true,
            'job' => $job,
        ]);
    }

    /**
     * POST /api/v1/tts/jobs/{id}/cancel
     */
    public static function cancelJob(array $params, array $body): void {
        $jobId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        try {
            $res = TtsJobService::cancelJob($jobId, (string)$user['id']);
            Router::json($res);
        } catch (Throwable $e) {
            Router::error($e->getMessage(), 400, 'CANCEL_FAILED');
        }
    }

    /**
     * GET /api/v1/tts/capabilities
     */
    public static function getCapabilities(array $params, array $body): void {
        $caps = TtsVoiceService::getEngineCapabilities();
        Router::json([
            'success' => true,
            'capabilities' => $caps,
            'engines' => array_keys($caps),
        ]);
    }

    /**
     * GET /api/v1/tts/voices
     */
    public static function listVoices(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        $lang = $_GET['language'] ?? ($_GET['lang'] ?? null);

        if ($user) {
            $voices = TtsVoiceService::listVoices((string)$user['id'], $lang);
        } else {
            $rawPresets = TtsVoiceService::getPresetVoices($lang);
            $formattedPresets = array_map([TtsVoiceService::class, 'formatPublicVoice'], $rawPresets);
            $voices = [
                'presets' => $formattedPresets,
                'custom' => [],
                'all' => $formattedPresets,
                'data' => $formattedPresets,
            ];
        }

        Router::json([
            'success' => true,
            'data' => $voices['data'] ?? $voices['all'],
            'presets' => $voices['presets'] ?? [],
            'custom' => $voices['custom'] ?? [],
            'all' => $voices['all'] ?? [],
            'capabilities' => TtsVoiceService::getEngineCapabilities(),
        ]);
    }

    /**
     * POST /api/v1/tts/voices
     */
    public static function createVoice(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        try {
            $voice = TtsVoiceService::createVoiceProfile((string)$user['id'], $body);
            $cleanData = [
                'voice_id' => $voice['id'],
                'id' => $voice['id'],
                'name' => $voice['name'],
                'reference_language' => $voice['reference_language'],
                'supported_target_languages' => $voice['supported_target_languages'],
                'clone_mode' => $voice['clone_mode'],
                'preview_url' => $voice['preview_url'],
                'status' => $voice['status'] ?? 'ACTIVE',
                'created_at' => $voice['created_at'] ?? date('Y-m-d H:i:s'),
            ];
            Router::json([
                'success' => true,
                'data' => $cleanData,
                'voice' => $cleanData,
            ], 201);
        } catch (InvalidArgumentException $e) {
            Router::error($e->getMessage(), 400, 'INVALID_ARGUMENT');
        } catch (Throwable $e) {
            Router::error($e->getMessage(), 400, 'VOICE_CREATION_FAILED');
        }
    }

    /**
     * DELETE /api/v1/tts/voices/{id}
     */
    public static function deleteVoice(array $params, array $body): void {
        $voiceId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error('Authentication required', 401, 'UNAUTHORIZED');
        }

        $ok = TtsVoiceService::deleteVoiceProfile($voiceId, (string)$user['id']);
        if (!$ok) {
            Router::error('Voice profile not found or already deleted', 404, 'NOT_FOUND');
        }

        Router::json([
            'success' => true,
            'message' => 'Voice profile deleted successfully',
        ]);
    }

    // =========================================================================
    // INTERNAL WORKER ENDPOINTS (Requires tts.worker scope or worker secret)
    // =========================================================================

    /**
     * POST /api/v1/internal/tts/jobs/claim
     */
    public static function claimJob(array $params, array $body): void {
        self::requireWorkerAuth();

        $workerId = trim($body['worker_id'] ?? '');
        if (empty($workerId)) {
            Router::error('worker_id is required', 400);
        }

        $capabilities = !empty($body['capabilities']) && is_array($body['capabilities']) ? $body['capabilities'] : null;
        $supportedEngines = !empty($capabilities['engines'])
            ? (array)$capabilities['engines']
            : (!empty($body['supported_engines']) ? (array)$body['supported_engines'] : ['QWEN3_TTS', 'MOCK']);
        $leaseSeconds = (int)($body['lease_seconds'] ?? (defined('TTS_WORKER_LEASE_SECONDS') ? TTS_WORKER_LEASE_SECONDS : 90));

        try {
            $job = TtsJobService::claimJob($workerId, $supportedEngines, $leaseSeconds, $capabilities);
            Router::json([
                'success' => true,
                'claimed' => !empty($job),
                'data' => [
                    'job' => $job,
                ],
                'job' => $job,
            ]);
        } catch (Throwable $e) {
            Router::error($e->getMessage(), 500, 'CLAIM_FAILED');
        }
    }

    /**
     * POST /api/v1/internal/tts/jobs/{id}/heartbeat
     */
    public static function heartbeatJob(array $params, array $body): void {
        self::requireWorkerAuth();

        $jobId = $params['id'] ?? '';
        $workerId = trim($body['worker_id'] ?? '');
        if (empty($workerId)) {
            Router::error('worker_id is required', 400);
        }

        $extendSeconds = (int)($body['lease_seconds'] ?? (defined('TTS_WORKER_LEASE_SECONDS') ? TTS_WORKER_LEASE_SECONDS : 90));

        $res = TtsJobService::heartbeat($jobId, $workerId, $extendSeconds);
        Router::json($res);
    }

    /**
     * POST /api/v1/internal/tts/jobs/{id}/progress
     */
    public static function progressJob(array $params, array $body): void {
        self::requireWorkerAuth();

        $jobId = $params['id'] ?? '';
        $workerId = trim($body['worker_id'] ?? '');
        $progress = (int)($body['progress'] ?? 0);
        $status = trim($body['status'] ?? 'GENERATING');

        try {
            $ok = TtsJobService::updateProgress($jobId, $workerId, $progress, $status);
            Router::json(['success' => $ok]);
        } catch (Throwable $e) {
            Router::error($e->getMessage(), 400, 'PROGRESS_UPDATE_FAILED');
        }
    }

    /**
     * POST /api/v1/internal/tts/jobs/{id}/complete
     */
    public static function completeJob(array $params, array $body): void {
        self::requireWorkerAuth();

        $jobId = $params['id'] ?? '';
        $workerId = trim($body['worker_id'] ?? '');

        try {
            $job = TtsJobService::completeJob($jobId, $workerId, $body);
            Router::json([
                'success' => true,
                'job' => $job,
            ]);
        } catch (Throwable $e) {
            Router::error($e->getMessage(), 400, 'JOB_COMPLETE_FAILED');
        }
    }

    /**
     * POST /api/v1/internal/tts/jobs/{id}/fail
     */
    public static function failJob(array $params, array $body): void {
        self::requireWorkerAuth();

        $jobId = $params['id'] ?? '';
        $workerId = trim($body['worker_id'] ?? '');
        $errorCode = trim($body['error_code'] ?? 'GENERATION_ERROR');
        $errorMessage = trim($body['error_message'] ?? 'Worker encountered an error during TTS generation');
        $retryable = !empty($body['retryable']);

        try {
            $job = TtsJobService::failJob($jobId, $workerId, $errorCode, $errorMessage, $retryable);
            Router::json([
                'success' => true,
                'job' => $job,
            ]);
        } catch (Throwable $e) {
            Router::error($e->getMessage(), 400, 'JOB_FAIL_FAILED');
        }
    }

    /**
     * GET /api/v1/internal/tts/voices/{id}
     */
    public static function getVoiceDetails(array $params, array $body): void {
        self::requireWorkerAuth();

        $voiceId = $params['id'] ?? '';
        $voice = TtsVoiceService::getVoiceProfile($voiceId);
        if (!$voice) {
            Router::error('Voice profile not found', 404, 'NOT_FOUND');
        }

        Router::json([
            'success' => true,
            'voice' => $voice,
        ]);
    }

    /**
     * GET /api/v1/tts/voices/{id}/preview
     * Public/User preview endpoint: streams voice sample audio without leaking internal storage keys.
     */
    public static function previewVoice(array $params, array $body): void {
        $voiceId = $params['id'] ?? '';
        $user = CloudAuthHelper::getCurrentUser();
        $userId = $user ? (string)$user['id'] : null;

        TtsVoiceService::streamVoicePreview($voiceId, $userId);
    }

    /**
     * GET /api/v1/internal/tts/voices/{id}/reference-audio
     */
    public static function streamVoiceReferenceAudio(array $params, array $body): void {
        self::requireWorkerAuth();

        $voiceId = $params['id'] ?? '';
        TtsVoiceService::streamReferenceAudio($voiceId);
    }
}
