<?php
/**
 * Gemini API Handler for AI Domain Finder
 *
 * Provides integration with Google Gemini API for generating domain name suggestions.
 * Includes rate limiting and IDN support.
 *
 * @package    WHMCS
 * @subpackage Registrar\Aidomainfinder
 * @author     jwalcz
 * @copyright  2024
 * @license    MIT
 * @link       https://github.com/jwalcz/whmcs-ai-domain-finder
 */

namespace WHMCS\Module\Registrar\Aidomainfinder;

/**
 * Gemini API client for domain suggestions.
 *
 * Handles all communication with the Google Gemini API including:
 * - API authentication and request handling
 * - Rate limiting using WHMCS TransientData
 * - IDN (Internationalized Domain Name) detection and handling
 * - Prompt building with variable substitution
 *
 * @package WHMCS\Module\Registrar\Aidomainfinder
 */
class GeminiApi
{
    /**
     * Google Gemini API key.
     *
     * @var string
     */
    protected $apiKey;

    /**
     * Gemini model identifier (e.g., 'gemini-2.0-flash').
     *
     * @var string
     */
    protected $model;

    /**
     * Maximum allowed API calls per day.
     *
     * @var int
     */
    protected $dailyLimit;

    /**
     * Maximum allowed API calls per minute.
     *
     * @var int
     */
    protected $minuteLimit;

    /**
     * Custom prompt template with placeholders.
     *
     * @var string
     */
    protected $promptTemplate;

    /**
     * Temperature setting for AI creativity (0.0-2.0).
     *
     * @var float
     */
    protected $temperature;

    /**
     * Last error message from API call.
     *
     * @var string
     */
    protected $lastError;

    /**
     * Default prompt template for domain suggestions.
     *
     * Placeholders:
     * - {searchTerm}: User's search keyword
     * - {suggestionCount}: Number of suggestions to generate
     * - {tldList}: Comma-separated list of available TLDs
     * - {tldPriority}: Auto-generated TLD priority instruction
     * - {idnInstruction}: Auto-generated IDN policy instruction
     *
     * @var string
     */
    const DEFAULT_PROMPT = 'You are a domain name suggestion expert. Based on "{searchTerm}", generate {suggestionCount} creative domain name suggestions.

Available TLDs: {tldList}
{tldPriority}
{idnInstruction}
Requirements:
- Suggest memorable, brandable domain names
- Consider the language and market of the user
- Mix exact matches with creative alternatives
- Include keyword variations and synonyms
- Return ONLY domain names (e.g., "example.com"), one per line
- Do not include explanations, numbering, or any other text';

    /**
     * IDN instruction for searches containing accented characters.
     *
     * Instructs the AI to primarily use accented characters in suggestions
     * when the user's search term contains international characters.
     *
     * @var string
     */
    const IDN_INSTRUCTION_FULL = 'IDN Policy: The search term contains accented/international characters. You SHOULD use accented characters (like á, é, í, ó, ö, ő, ú, ü, ű) in domain suggestions to match the search intent.';

    /**
     * IDN instruction for ASCII-only searches.
     *
     * Instructs the AI to use only ASCII characters.
     * IDN suggestions are also filtered out in the module.
     *
     * @var string
     */
    const IDN_INSTRUCTION_MINIMAL = 'IDN Policy: Suggest ONLY ASCII domain names (a-z, 0-9, hyphens). Do NOT use any accented or international characters.';

    /**
     * Allowed Gemini model identifiers.
     *
     * @var string[]
     */
    const ALLOWED_MODELS = [
        'gemini-2.0-flash',
        'gemini-2.5-flash',
        'gemini-2.5-pro',
    ];

    /**
     * Checks if a string contains non-ASCII (IDN) characters.
     *
     * Uses regex to detect any character outside the ASCII range (0x00-0x7F).
     *
     * @param string $string The string to check.
     *
     * @return bool True if string contains non-ASCII characters.
     */
    public static function isIdn($string)
    {
        return preg_match('/[^\x00-\x7F]/', $string) === 1;
    }

    /**
     * Maximum length for a domain label (SLD) per RFC 1035.
     *
     * @var int
     */
    const MAX_LABEL_LENGTH = 63;

    /**
     * Validates an IDN-compatible second-level domain (SLD) per RFC 1035/5891.
     *
     * Allows Unicode letters, numbers, and hyphens. The SLD must:
     * - Not be empty
     * - Not exceed 63 characters (RFC 1035)
     * - Not start or end with a hyphen
     * - Contain only valid characters (\p{L}, \p{N}, -)
     *
     * @param string $sld The second-level domain to validate.
     *
     * @return bool True if the SLD is valid.
     */
    public static function isValidIdnSld($sld)
    {
        if (empty($sld)) {
            return false;
        }

        // RFC 1035: max 63 characters per label
        if (mb_strlen($sld, 'UTF-8') > self::MAX_LABEL_LENGTH) {
            return false;
        }

        // Cannot start or end with hyphen
        if (mb_substr($sld, 0, 1) === '-' || mb_substr($sld, -1) === '-') {
            return false;
        }

        // Allow Unicode letters (\p{L}), numbers (\p{N}), and hyphens
        return preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\-]*$/u', $sld) === 1;
    }

    /**
     * Creates a new GeminiApi instance.
     *
     * @param array $config Configuration array with the following keys:
     *                      - ApiKey: Google Gemini API key (required)
     *                      - Model: Gemini model ID (default: 'gemini-2.0-flash')
     *                      - DailyRateLimit: Max calls/day (default: 1500)
     *                      - MinuteRateLimit: Max calls/minute (default: 15)
     *                      - Temperature: AI creativity 0.0-2.0 (default: 1.0)
     *                      - Prompt: Custom prompt template (optional)
     */
    public function __construct(array $config = [])
    {
        $this->apiKey = isset($config['ApiKey']) ? $config['ApiKey'] : '';

        // Validate model against whitelist
        $model = isset($config['Model']) && !empty($config['Model']) ? $config['Model'] : 'gemini-2.0-flash';
        $this->model = in_array($model, self::ALLOWED_MODELS, true) ? $model : 'gemini-2.0-flash';

        $this->dailyLimit = isset($config['DailyRateLimit']) ? (int)$config['DailyRateLimit'] : 1500;
        $this->minuteLimit = isset($config['MinuteRateLimit']) ? (int)$config['MinuteRateLimit'] : 15;

        // Validate temperature (0.0-2.0 range)
        $temperature = isset($config['Temperature']) ? (float)$config['Temperature'] : 1.0;
        $this->temperature = max(0.0, min(2.0, $temperature));

        $this->promptTemplate = isset($config['Prompt']) && !empty(trim($config['Prompt']))
            ? $config['Prompt']
            : self::DEFAULT_PROMPT;
        $this->lastError = '';
    }

    /**
     * Returns the last error message.
     *
     * Call this after generateSuggestions() returns an empty array
     * to get details about what went wrong.
     *
     * @return string Error message or empty string if no error.
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Checks if current request is within rate limits.
     *
     * Uses WHMCS TransientData to track API call counts with keys:
     * - aidomainfinder_daily_YYYY-MM-DD (24h TTL)
     * - aidomainfinder_minute_YYYY-MM-DD-HH-MM (60s TTL)
     *
     * @return array{allowed: bool, reason: string|null} Result with:
     *               - allowed: Whether the request can proceed
     *               - reason: Error message if not allowed, null otherwise
     */
    public function checkRateLimit()
    {
        $transient = \WHMCS\TransientData::getInstance();

        $dailyKey = 'aidomainfinder_daily_' . date('Y-m-d');
        $minuteKey = 'aidomainfinder_minute_' . date('Y-m-d-H-i');

        $dailyCount = (int) $transient->retrieve($dailyKey);
        $minuteCount = (int) $transient->retrieve($minuteKey);

        if ($this->dailyLimit > 0 && $dailyCount >= $this->dailyLimit) {
            return [
                'allowed' => false,
                'reason' => 'Daily API limit reached (' . $this->dailyLimit . ' calls/day)'
            ];
        }

        if ($this->minuteLimit > 0 && $minuteCount >= $this->minuteLimit) {
            return [
                'allowed' => false,
                'reason' => 'Per-minute API limit reached (' . $this->minuteLimit . ' calls/minute)'
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Increments rate limit counters after a successful API call.
     *
     * Updates both daily and per-minute counters in WHMCS TransientData.
     * Daily counter has 24-hour TTL, minute counter has 60-second TTL.
     *
     * @return void
     */
    protected function incrementRateLimit()
    {
        $transient = \WHMCS\TransientData::getInstance();

        $dailyKey = 'aidomainfinder_daily_' . date('Y-m-d');
        $minuteKey = 'aidomainfinder_minute_' . date('Y-m-d-H-i');

        $dailyCount = (int) $transient->retrieve($dailyKey);
        $minuteCount = (int) $transient->retrieve($minuteKey);

        $transient->store($dailyKey, (string)($dailyCount + 1), 86400);
        $transient->store($minuteKey, (string)($minuteCount + 1), 60);
    }

    /**
     * Builds the final prompt from template with variable substitution.
     *
     * Replaces placeholders in the prompt template:
     * - {searchTerm}: User's search keyword
     * - {suggestionCount}: Number of suggestions
     * - {tldList}: Available TLDs
     * - {tldPriority}: TLD priority instruction based on TLD order
     * - {idnInstruction}: IDN policy based on search term
     *
     * @param string   $searchTerm      User's search keyword.
     * @param int      $suggestionCount Number of suggestions to request.
     * @param string[] $tlds            Array of TLDs in priority order.
     *
     * @return string Complete prompt ready for API call.
     */
    protected function buildPrompt($searchTerm, $suggestionCount, array $tlds)
    {
        $tldList = implode(', ', array_map(function($tld) {
            return '.' . ltrim($tld, '.');
        }, $tlds));

        $tldPriority = $this->buildTldPriorityInstruction($tlds);

        $idnInstruction = self::isIdn($searchTerm)
            ? self::IDN_INSTRUCTION_FULL
            : self::IDN_INSTRUCTION_MINIMAL;

        return str_replace(
            ['{searchTerm}', '{suggestionCount}', '{tldList}', '{tldPriority}', '{idnInstruction}'],
            [$searchTerm, $suggestionCount, $tldList, $tldPriority, $idnInstruction],
            $this->promptTemplate
        );
    }

    /**
     * Builds TLD priority instruction for the prompt.
     *
     * Generates an instruction that tells the AI to prioritize TLDs
     * based on their order in the array.
     *
     * @param string[] $tlds Array of TLDs in priority order.
     *
     * @return string TLD priority instruction.
     */
    protected function buildTldPriorityInstruction(array $tlds)
    {
        if (count($tlds) <= 1) {
            return '';
        }

        $firstTld = '.' . ltrim($tlds[0], '.');
        $secondTld = isset($tlds[1]) ? '.' . ltrim($tlds[1], '.') : '';

        $instruction = "TLD Priority: Distribute suggestions with preference for TLDs listed first.\n";
        $instruction .= "Use {$firstTld} for approximately 40% of suggestions";
        if ($secondTld) {
            $instruction .= ", {$secondTld} for approximately 25%";
        }
        $instruction .= ", and distribute the rest among other TLDs.";

        return $instruction;
    }

    /**
     * Generates domain suggestions using the Gemini API.
     *
     * Makes a POST request to the Gemini generateContent endpoint with
     * the constructed prompt. Handles rate limiting, error checking,
     * and response parsing.
     *
     * @param string   $searchTerm      The keyword to base suggestions on.
     * @param int      $suggestionCount Number of suggestions to generate.
     * @param string[] $tlds            Array of TLDs to include in suggestions.
     *
     * @return string[] Array of domain names (e.g., ['example.com', 'test.net'])
     *                  or empty array on failure. Call getLastError() for details.
     */
    public function generateSuggestions($searchTerm, $suggestionCount, array $tlds)
    {
        $this->lastError = '';

        if (empty($this->apiKey)) {
            $this->lastError = 'API key not configured';
            return [];
        }

        if (empty($searchTerm)) {
            $this->lastError = 'Search term is empty';
            return [];
        }

        $rateLimitCheck = $this->checkRateLimit();
        if (!$rateLimitCheck['allowed']) {
            $this->lastError = $rateLimitCheck['reason'];
            return [];
        }

        if (empty($tlds)) {
            $tlds = ['com', 'net', 'org', 'io'];
        }

        $prompt = $this->buildPrompt($searchTerm, $suggestionCount, $tlds);

        $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent?key=' . $this->apiKey;

        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $this->temperature
            ]
        ];

        // Log the API request
        $logRequest = [
            'model' => $this->model,
            'searchTerm' => $searchTerm,
            'suggestionCount' => $suggestionCount,
            'tlds' => $tlds,
            'temperature' => $this->temperature,
            'prompt' => $prompt,
        ];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($requestData),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Handle cURL errors
        if ($curlError) {
            $this->lastError = 'cURL error: ' . $curlError;
            $this->logApiCall($logRequest, '', $this->lastError);
            return [];
        }

        // Handle HTTP errors with descriptive messages
        if ($httpCode !== 200) {
            $this->lastError = $this->getHttpErrorMessage($httpCode, $response);
            $this->logApiCall($logRequest, $response, $this->lastError);
            return [];
        }

        $this->incrementRateLimit();

        $result = json_decode($response, true);

        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $this->lastError = 'Invalid API response format';
            $this->logApiCall($logRequest, $response, $this->lastError);
            return [];
        }

        $domainsText = trim($result['candidates'][0]['content']['parts'][0]['text']);
        $domainLines = array_filter(array_map('trim', explode("\n", $domainsText)));

        $domains = [];
        foreach ($domainLines as $domain) {
            $domain = preg_replace('/^[\d\.\)\-\s]+/', '', $domain);
            $domain = mb_strtolower(trim($domain), 'UTF-8');

            if (!empty($domain) && strpos($domain, '.') !== false) {
                $domains[] = $domain;
            }
        }

        // Log successful API call
        $this->logApiCall($logRequest, $response, $domains);

        return $domains;
    }

    /**
     * Logs API call to WHMCS Module Log.
     *
     * @param array        $request   Request data (prompt, model, etc.)
     * @param string       $response  Raw API response
     * @param array|string $processed Processed result (domains array or error message)
     *
     * @return void
     */
    protected function logApiCall(array $request, $response, $processed)
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $action = is_array($processed) ? 'GenerateSuggestions' : 'GenerateSuggestions_Error';

        logModuleCall(
            'aidomainfinder',
            $action,
            $request,
            $response,
            $processed,
            [$this->apiKey]
        );
    }

    /**
     * Returns a descriptive error message for HTTP error codes.
     *
     * @param int    $httpCode HTTP status code
     * @param string $response Raw API response for additional context
     *
     * @return string Descriptive error message
     */
    protected function getHttpErrorMessage($httpCode, $response)
    {
        $messages = [
            400 => 'Bad Request - Invalid request format',
            401 => 'Unauthorized - Invalid API key',
            403 => 'Forbidden - API key lacks required permissions',
            404 => 'Not Found - Invalid API endpoint or model',
            429 => 'Rate Limit Exceeded - Too many requests, please wait',
            500 => 'Internal Server Error - Gemini API error',
            503 => 'Service Unavailable - Gemini API temporarily unavailable',
        ];

        $message = isset($messages[$httpCode])
            ? $messages[$httpCode]
            : 'HTTP error: ' . $httpCode;

        // Try to extract error details from response
        $decoded = json_decode($response, true);
        if (isset($decoded['error']['message'])) {
            $message .= ' - ' . $decoded['error']['message'];
        }

        return $message;
    }
}
