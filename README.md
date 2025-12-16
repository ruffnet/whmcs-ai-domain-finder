# AI Domain Finder for WHMCS

A WHMCS registrar module that generates creative domain name suggestions using Google Gemini AI. This is a suggestion-only module - it does not register domains.

## Features

- **AI-Powered Suggestions**: Uses Google Gemini API to generate creative, brandable domain names
- **Multiple Models**: Support for Gemini 2.0 Flash, 2.5 Flash, and 2.5 Pro
- **TLD Priority**: Automatically prioritizes TLDs based on WHMCS configuration order (first TLD gets ~40% of suggestions)
- **IDN Support**: Full support for internationalized domain names (accented characters like á, é, ö, ü)
- **Smart IDN Handling**: ASCII searches return only ASCII domains; IDN searches return IDN suggestions
- **Rate Limiting**: Built-in rate limiting to stay within API quotas and prevent unexpected charges
- **Customizable Prompt**: Fully editable AI prompt with variable substitution
- **Comprehensive Logging**: All API calls logged to WHMCS Module Log with detailed error messages

## Requirements

- WHMCS 7.0 or later
- PHP 7.2 or later
- PHP intl extension (for Punycode conversion, usually pre-installed)
- Google Gemini API key ([Get one here](https://aistudio.google.com/apikey))

## Installation

1. Download or clone this repository
2. Copy the `modules/registrars/aidomainfinder` folder to your WHMCS installation:
   ```
   /path/to/whmcs/modules/registrars/aidomainfinder/
   ```
3. Go to **Configuration (⚙) > System Settings > Domain Registrars**
4. Find "AI Domain Finder (Gemini)" and click **Activate**
5. Enter your Gemini API key and configure settings
6. Go to **Configuration (⚙) > System Settings > Domain Pricing** and set "AI Domain Finder" as the **Domain Lookup Provider**

## Configuration

### Module Settings

| Setting | Description | Default |
|---------|-------------|---------|
| **Gemini API Key** | Your Google Gemini API key | - |
| **Gemini Model** | AI model to use | Gemini 2.0 Flash |
| **Daily Limit** | Maximum API calls per day | 1500 |
| **Per-Minute Limit** | Maximum API calls per minute | 15 |
| **AI Prompt** | Customizable prompt template | See below |

### Domain Lookup Settings

Go to **Configuration (⚙) > System Settings > Domain Pricing > Domain Lookup Configuration**:

| Setting | Description | Default |
|---------|-------------|---------|
| **Suggestion Count** | Number of suggestions to generate | 30 |
| **Creativity** | Controls AI creativity level | Creative |

### Creativity Levels

| Level | Description |
|-------|-------------|
| **Conservative** | More predictable, consistent suggestions |
| **Balanced** | Good mix of reliability and variety |
| **Creative** | Standard creativity level (default) |
| **Very Creative** | More varied and unique suggestions |
| **Experimental** | Maximum variety, may be less relevant |

## Rate Limiting

The module includes built-in rate limiting using WHMCS TransientData to prevent exceeding API quotas.

### Gemini Free Tier Limits (as of 2024)

| Model | Requests/Minute | Requests/Day |
|-------|-----------------|--------------|
| Gemini 2.0 Flash | 15 | 1,500 |
| Gemini 2.5 Flash | 10 | ~250 |
| Gemini 2.5 Pro | 5 | ~25-50 |

For current limits, see [Gemini API Pricing](https://ai.google.dev/pricing).

## Customizing the Prompt

The AI prompt can be customized in the module settings. Available variables:

| Variable | Description | Example |
|----------|-------------|---------|
| `{searchTerm}` | User's search query | `mycompany` |
| `{suggestionCount}` | Number of suggestions to generate | `30` |
| `{tldList}` | Comma-separated list of available TLDs | `.hu, .com, .net` |
| `{tldPriority}` | Auto-generated TLD priority instruction | See TLD Priority section |
| `{idnInstruction}` | Auto-generated IDN policy based on search type | See IDN Support section |

### Default Prompt

```
You are a domain name suggestion expert. Based on "{searchTerm}", generate {suggestionCount} creative domain name suggestions.

Available TLDs: {tldList}
{tldPriority}
{idnInstruction}
Requirements:
- Suggest memorable, brandable domain names
- Consider the language and market of the user
- Mix exact matches with creative alternatives
- Include keyword variations and synonyms
- Return ONLY domain names (e.g., "example.com"), one per line
- Do not include explanations, numbering, or any other text
```

## TLD Priority

The module automatically prioritizes TLDs based on their order in the WHMCS Domain Pricing configuration.

### How It Works

1. The first TLD in the list receives approximately 40% of suggestions
2. The second TLD receives approximately 25% of suggestions
3. Remaining TLDs share the rest of the suggestions

### Example

If your TLD order is: `.hu, .com, .net, .org, .biz, .info`

The AI will generate approximately:
- 40% `.hu` domains
- 25% `.com` domains
- 35% distributed among `.net`, `.org`, `.biz`, `.info`

### Configuration

To change TLD priority, reorder TLDs in **Configuration (⚙) > System Settings > Domain Pricing**.

## File Structure

```
modules/registrars/aidomainfinder/
├── aidomainfinder.php    # Main module file
└── lib/
    └── GeminiApi.php     # Gemini API handler class
```

## How It Works

1. User searches for a domain in the WHMCS client area
2. Module calls Gemini API with the configured prompt
3. AI generates creative domain suggestions
4. Module parses response and returns `SearchResult` objects
5. WHMCS displays suggestions and checks availability using its built-in mechanisms

Note: This module only provides domain suggestions. Availability checking is handled by WHMCS using its configured lookup provider (e.g., BasicWhois or your registrar).

## IDN Support (Internationalized Domain Names)

The module fully supports IDN domains with accented characters (á, é, í, ó, ö, ő, ú, ü, ű, etc.).

### Smart IDN Detection

The module automatically adjusts AI behavior based on the search term:

| Search Type | Example | AI Behavior |
|-------------|---------|-------------|
| **Contains accents** | `kávézó` | Suggests IDN domains with accented characters |
| **ASCII only** | `kavezo` | Suggests ASCII-only domains (IDN filtered out) |

### How It Works

1. **Detection**: Module checks if search term contains non-ASCII characters
2. **Prompt Injection**: The `{idnInstruction}` variable is automatically set based on detection
3. **Validation**: Both ASCII and Unicode domain names are validated
4. **WHOIS Lookup**: IDN domains are converted to Punycode (e.g., `kávé.hu` → `xn--kv-fka.hu`) for WHOIS queries

### Transliteration

The module includes a transliteration function that properly converts accented characters to ASCII:

| Input | Output |
|-------|--------|
| `kávézó` | `kavezo` |
| `über` | `uber` |
| `château` | `chateau` |

Supports Hungarian, German, French, Spanish, Italian, Portuguese, Polish, Czech, Slovak, and Nordic characters.

### IDN Instructions

When search contains accents:
```
IDN Policy: The search term contains accented/international characters.
You SHOULD use accented characters (like á, é, í, ó, ö, ő, ú, ü, ű) in domain suggestions.
```

When search is ASCII-only:
```
IDN Policy: Suggest ONLY ASCII domain names (a-z, 0-9, hyphens).
Do NOT use any accented or international characters.
```

Note: Even if the AI returns IDN suggestions for ASCII searches, they are filtered out by the module.

## Troubleshooting

### No suggestions appearing

1. Check that the API key is valid
2. Verify rate limits haven't been exceeded (check Module Log)
3. Ensure the module is set as Domain Lookup Provider

### Rate limit errors

- Check **Configuration (⚙) > System Logs > Module Log** for rate limit messages
- Reduce the suggestion count or increase rate limits
- Wait for the rate limit window to reset (1 minute or 24 hours)

### Module Log

All errors are logged to WHMCS Module Log:
- **Configuration (⚙) > System Logs > Module Log**
- Filter by module name: `aidomainfinder`

## License

MIT License - See [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

For issues and feature requests, please use the [GitHub Issues](https://github.com/jwalcz/whmcs-ai-domain-finder/issues) page.
