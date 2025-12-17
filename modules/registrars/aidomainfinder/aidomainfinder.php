<?php
/**
 * WHMCS AI Domain Finder Registrar Module
 *
 * A WHMCS registrar module that generates creative domain name suggestions
 * using Google Gemini AI. Supports IDN domains, rate limiting, and customizable prompts.
 *
 * @package    WHMCS
 * @subpackage Registrar
 * @author     jwalcz
 * @copyright  2024
 * @license    MIT
 * @link       https://github.com/jwalcz/whmcs-ai-domain-finder
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Module\Registrar\Aidomainfinder\GeminiApi;

/**
 * Returns module metadata for WHMCS.
 *
 * Provides display name and API version information used by WHMCS
 * to identify and manage the module.
 *
 * @return array{DisplayName: string, APIVersion: string} Module metadata
 */
function aidomainfinder_MetaData()
{
    return [
        'DisplayName' => 'AI Domain Finder (Gemini)',
        'APIVersion' => '1.1',
    ];
}

/**
 * Returns module configuration options for the WHMCS admin interface.
 *
 * Defines the settings available in Domain Registrars configuration:
 * - ApiKey: Google Gemini API key (password field)
 * - Model: Gemini model selection (dropdown)
 * - DailyRateLimit: Maximum API calls per day
 * - MinuteRateLimit: Maximum API calls per minute
 * - Prompt: Customizable AI prompt template
 *
 * @return array<string, array<string, mixed>> Configuration options array
 */
function aidomainfinder_getConfigArray()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'AI Domain Finder',
        ],
        'ApiKey' => [
            'FriendlyName' => 'Gemini API Key',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Google Gemini API key (<a href="https://aistudio.google.com/apikey" target="_blank">Get API Key</a>)',
        ],
        'Model' => [
            'FriendlyName' => 'Gemini Model',
            'Type' => 'dropdown',
            'Options' => [
                'gemini-2.0-flash' => 'Gemini 2.0 Flash',
                'gemini-2.5-flash' => 'Gemini 2.5 Flash',
                'gemini-2.5-pro' => 'Gemini 2.5 Pro',
            ],
            'Default' => 'gemini-2.0-flash',
            'Description' => 'Gemini model to use for suggestions',
        ],
        'DailyRateLimit' => [
            'FriendlyName' => 'Daily Limit',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '1500',
            'Description' => 'Maximum API calls per day (Gemini 2.0 Flash free tier: 1500/day)',
        ],
        'MinuteRateLimit' => [
            'FriendlyName' => 'Per-Minute Limit',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '15',
            'Description' => 'Maximum API calls per minute (Gemini 2.0 Flash free tier: 15/min). <a href="https://ai.google.dev/pricing" target="_blank">Gemini API Pricing & Limits</a>',
        ],
        'Prompt' => [
            'FriendlyName' => 'AI Prompt',
            'Type' => 'textarea',
            'Rows' => '10',
            'Cols' => '60',
            'Default' => GeminiApi::DEFAULT_PROMPT,
            'Description' => 'Prompt sent to Gemini API. Available variables: {searchTerm}, {suggestionCount}, {tldList}',
        ],
    ];
}

/**
 * Returns domain suggestion configuration options.
 *
 * Defines settings for the Domain Lookup Configuration in WHMCS:
 * - SuggestionCount: Number of suggestions to generate (10-50)
 * - Temperature: AI creativity level (0.5-1.5)
 *
 * @return array<string, array<string, mixed>> Suggestion options array
 */
function aidomainfinder_DomainSuggestionOptions()
{
    return [
        'SuggestionCount' => [
            'FriendlyName' => 'Suggestion Count',
            'Type' => 'dropdown',
            'Options' => [
                '10' => '10',
                '20' => '20',
                '30' => '30',
                '50' => '50',
            ],
            'Default' => '30',
            'Description' => 'Number of domain suggestions to generate',
        ],
        'Temperature' => [
            'FriendlyName' => 'Creativity',
            'Type' => 'dropdown',
            'Options' => [
                '0.5' => 'Conservative',
                '0.7' => 'Balanced',
                '1.0' => 'Creative (Default)',
                '1.3' => 'Very Creative',
                '1.5' => 'Experimental',
            ],
            'Default' => '1.0',
            'Description' => 'Controls AI creativity level',
        ],
    ];
}

/**
 * Checks domain availability.
 *
 * Currently returns all domains as registered for debugging purposes.
 *
 * @param array $params WHMCS parameters
 *
 * @return \WHMCS\Domains\DomainLookup\ResultsList
 */
function aidomainfinder_CheckAvailability($params)
{
    $results = new \WHMCS\Domains\DomainLookup\ResultsList();

    // Debug logging
    logModuleCall('aidomainfinder', 'CheckAvailability', $params, '', '', isset($params['ApiKey']) ? [$params['ApiKey']] : []);

    $searchTerm = isset($params['searchTerm']) ? $params['searchTerm'] : '';
    $tldsToInclude = isset($params['tldsToInclude']) && is_array($params['tldsToInclude']) ? $params['tldsToInclude'] : [];

    if (empty($searchTerm) || empty($tldsToInclude)) {
        return $results;
    }

    $sld = mb_strtolower(trim($searchTerm), 'UTF-8');

    foreach ($tldsToInclude as $tld) {
        $tld = ltrim($tld, '.');
        $searchResult = new \WHMCS\Domains\DomainLookup\SearchResult($sld, $tld);
        $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_REGISTERED);
        $results->append($searchResult);
    }

    return $results;
}

/**
 * Generates domain suggestions using Google Gemini API.
 *
 * Calls the Gemini API with a customizable prompt to generate creative
 * domain name suggestions based on the user's search term.
 *
 * Features:
 * - Smart IDN detection: adjusts prompt based on whether search contains accents
 * - Rate limiting: respects daily and per-minute API limits
 * - Customizable prompt with variable substitution
 *
 * @param array $params WHMCS parameters containing:
 *                      - searchTerm: The search keyword for suggestions
 *                      - tldsToInclude: Array of available TLDs
 *                      - suggestionSettings: Array with SuggestionCount, Temperature
 *                      - ApiKey, Model, Prompt, etc. from module config
 *
 * @return \WHMCS\Domains\DomainLookup\ResultsList List of SearchResult objects with suggested domains
 */
function aidomainfinder_GetDomainSuggestions($params)
{
    $results = new \WHMCS\Domains\DomainLookup\ResultsList();

    try {
        $searchTerm = isset($params['searchTerm']) ? $params['searchTerm'] : '';
        $tldsToInclude = isset($params['tldsToInclude']) && is_array($params['tldsToInclude']) ? $params['tldsToInclude'] : [];
        $suggestionCount = isset($params['suggestionSettings']['SuggestionCount']) ? (int)$params['suggestionSettings']['SuggestionCount'] : 10;
        $temperature = isset($params['suggestionSettings']['Temperature']) ? $params['suggestionSettings']['Temperature'] : '1.0';

        if (empty($searchTerm)) {
            return $results;
        }

        $config = $params;
        $config['Temperature'] = $temperature;
        $gemini = new GeminiApi($config);
        $domains = $gemini->generateSuggestions($searchTerm, $suggestionCount, $tldsToInclude);

        if (empty($domains)) {
            // Error already logged in GeminiApi::generateSuggestions()
            return $results;
        }

        $isAsciiSearch = !GeminiApi::isIdn($searchTerm);
        $score = 100;
        foreach ($domains as $domain) {
            $lastDot = strrpos($domain, '.');
            if ($lastDot === false) {
                continue;
            }

            $sld = substr($domain, 0, $lastDot);
            $tld = substr($domain, $lastDot + 1);

            if (!GeminiApi::isValidIdnSld($sld)) {
                continue;
            }

            // Filter out IDN suggestions for ASCII searches
            if ($isAsciiSearch && GeminiApi::isIdn($sld)) {
                continue;
            }

            $searchResult = new \WHMCS\Domains\DomainLookup\SearchResult($sld, $tld);
            $searchResult->setStatus(\WHMCS\Domains\DomainLookup\SearchResult::STATUS_NOT_REGISTERED);
            $searchResult->setScore($score);

            $results->append($searchResult);
            $score--;
        }

    } catch (\Throwable $e) {
        $replaceVars = isset($params['ApiKey']) ? [$params['ApiKey']] : [];
        logModuleCall('aidomainfinder', 'GetDomainSuggestions', $params, $e->getMessage(), $e->getTraceAsString(), $replaceVars);
    }

    return $results;
}
