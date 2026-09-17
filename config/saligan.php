<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding configuration
    |--------------------------------------------------------------------------
    |
    | The provider and model used to embed text. The dimensions must match the
    | vector columns in the database (document_chunks.embedding and
    | legal_chunks.embedding), which are currently halfvec(768). Gemini
    | embedding models accept an output dimensionality, so EMBEDDING_DIMENSIONS
    | (and the halfvec columns) can stay at 768.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Batch API transport
    |--------------------------------------------------------------------------
    |
    | How long a call to a provider's batch API may take. This is the HTTP
    | timeout on submitting, polling, and reading a batch — not how long the
    | batch itself may run, which is the provider's business and measured in
    | hours. Shared by every batched feature (document classification, legal
    | digests) because it describes the transport, not the work.
    |
    */

    'batch_timeout' => (int) env('AI_BATCH_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Legal timezone
    |--------------------------------------------------------------------------
    |
    | The timezone the drafted documents are dated in. The application stores
    | timestamps in UTC, but a Philippine instrument is dated by the calendar
    | day in the Philippines: between midnight and 08:00 in Manila, UTC is
    | still on the previous day, so a letter dated from the raw server clock
    | carries yesterday's date — and any period counted "from the date of this
    | letter" is off by one day with it.
    |
    */

    'timezone' => env('SALIGAN_TIMEZONE', 'Asia/Manila'),

    'embedding' => [
        'provider' => env('AI_EMBED_PROVIDER', 'gemini'),
        'model' => env('AI_EMBED_MODEL', 'gemini-embedding-2'),
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 768),
        'timeout' => (int) env('EMBEDDING_TIMEOUT', 600),
        'batch_size' => (int) env('EMBEDDING_BATCH_SIZE', 16),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat configuration
    |--------------------------------------------------------------------------
    |
    | Provider names reference providers defined in config/ai.php.
    |
    */

    'chat' => [
        // Python is the default: every AI call belongs in the ai-provider
        // service, and Laravel's in-process engine is the opt-in fallback
        // (`AI_CHAT_ENGINE=laravel`). Defaulting the other way meant a
        // deployment that never set the variable quietly ran the old engine.
        'engine' => env('AI_CHAT_ENGINE', 'python'),
        'provider' => env('AI_CHAT_PROVIDER', 'anthropic'),
        'ollama_model' => env('OLLAMA_CHAT_MODEL', 'qwen3.6:latest'),
        'ollama_model_alt' => env('OLLAMA_CHAT_MODEL_ALT', 'qwen3.5:latest'),

        /*
         * Context window requested from Ollama, in tokens.
         *
         * Ollama defaults num_ctx to 4096 and silently truncates anything
         * longer, keeping only the TAIL of the prompt. A drafting turn sends
         * roughly 23k tokens — persona and drafting rules, the uploaded
         * template body and its placeholders, matter memory, then retrieved
         * context — so at the default the model read about 2k tokens and every
         * instruction that makes template drafting work was discarded before
         * it ever saw them. It answered from the leftover tail and never
         * called fill_template_fields, so no document was produced.
         *
         * Raise this and the whole prompt survives. The cost is KV-cache
         * memory on the Ollama host and prompt-eval time, so it is tunable:
         * lower it if the host runs out of VRAM, but never below the size of a
         * drafting prompt or the truncation returns silently.
         */
        'ollama_num_ctx' => (int) env('OLLAMA_NUM_CTX', 32768),

        /*
         * Per-request timeout for a chat step, in seconds.
         *
         * laravel/ai falls back to 60s when the agent names no timeout, and
         * Guzzle applies that as an IDLE timeout on the response stream. A
         * local model reading a ~23k-token drafting prompt emits nothing at
         * all while it works — measured at ~163s on the dev box — so the read
         * expired long before the first token and surfaced as Guzzle's
         * misleading "Connection refused for URI", with no reply persisted.
         *
         * Hosted providers answer in a few seconds and never approach this;
         * it exists for slow local inference.
         */
        'timeout' => (int) env('AI_CHAT_TIMEOUT', 300),
        'gemini_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash'),
        'openai_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o'),
        'anthropic_model' => env('ANTHROPIC_CHAT_MODEL', 'claude-sonnet-5'),
        'meta_model' => env('META_CHAT_MODEL', 'muse-spark-1.1'),

        /*
         * The Anthropic model served to plans without the `frontier_model`
         * feature — the free trial and Standard. Haiku 4.5 costs half of
         * Sonnet 5 per message at our measured token sizes (₱1.75 against
         * ₱3.49; see EarningsModel), and that halving is what pays for
         * Standard's message allowance and for giving trials away at all.
         *
         * Both models answer from the same retrieved sources, so this changes
         * how much deliberation a message buys, never what it can reach. Set
         * it to the same value as `anthropic_model` (or leave it empty) to
         * serve everyone the frontier model — but note that Standard's
         * allowance is priced on the assumption that it is not.
         *
         * Note that Haiku 4.5 rejects `output_config.effort` outright, so the
         * effort setting below is omitted for it; see LegalChatAgent.
         */
        'anthropic_base_model' => env('ANTHROPIC_BASE_CHAT_MODEL', 'claude-haiku-4-5'),

        /*
         * How hard the model works before answering: low | medium | high |
         * xhigh | max.
         *
         * Claude Sonnet 5 defaults to `high`, which was never set here and so
         * was never chosen — every answer was paying for the most deliberate
         * setting. Retrieval has already done the source-finding by the time
         * the model runs, so `medium` returns the first token noticeably
         * sooner while holding answer quality. Raise it if citation accuracy
         * suffers; drop to `low` for a faster, chattier feel.
         *
         * Only applies to models that accept it. Haiku 4.5 does not, and a
         * request that sends it anyway is rejected with a 400.
         */
        'effort' => env('ANTHROPIC_CHAT_EFFORT', 'medium'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Python AI provider
    |--------------------------------------------------------------------------
    |
    | Laravel is the public boundary. It calls this private service for AI
    | work and authenticates both directions with one deployment secret.
    |
    */

    'ai_provider' => [
        'url' => env('AI_PROVIDER_URL', 'http://127.0.0.1:8080'),
        'internal_secret' => env('AI_INTERNAL_SECRET'),
        // Same default, same reason: OCR, classification, rewrites, embeddings,
        // letters and digests all run in ai-provider unless explicitly told
        // otherwise with AI_BATCH_ENGINE=laravel.
        'batch_engine' => env('AI_BATCH_ENGINE', 'python'),
        'connect_timeout' => (int) env('AI_PROVIDER_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('AI_PROVIDER_TIMEOUT', 300),
    ],

    /*
     | Case digests are asynchronous so chat and document writes never wait on
     | a second model call. A database-backed connection is the deployment
     | default even when the request queue uses a different connection.
     */
    'case_digest' => [
        'connection' => env('CASE_DIGEST_QUEUE_CONNECTION', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context caching
    |--------------------------------------------------------------------------
    |
    | Gates both providers' prompt caching for the static system prompt.
    |
    | Gemini: the static system prompt (persona + standing instructions) is
    | cached as a CachedContent resource so subsequent chat turns bill those
    | tokens at the reduced cached-input rate. The cached prefix is referenced
    | by name via the generateContent "cachedContent" field; dynamic per-turn
    | instructions (export, case, template, retrieved context) are appended
    | after it.
    |
    | Anthropic: the static system prompt is sent as its own system block with
    | a "cache_control" breakpoint, using the same ttl_seconds as Gemini —
    | an hour when it is 3600 or more, otherwise Anthropic's five-minute
    | default. The block is identical on every request and carries no per-user
    | text, so one cache entry serves every tenant and is read at 0.1x the
    | input rate on every subsequent turn.
    |
    */

    'context_caching' => [
        'enabled' => (bool) env('GEMINI_CONTEXT_CACHING', true),
        'ttl_seconds' => (int) env('GEMINI_CONTEXT_CACHE_TTL', 3600),
        'refresh_seconds' => (int) env('GEMINI_CONTEXT_CACHE_REFRESH', 3000),
        // How long to wait when (re)creating the CachedContent on the request
        // path before giving up. Creation happens synchronously on a cache
        // miss, so a hung call must fail fast — the caller then proceeds
        // without cached-input pricing instead of blocking the stream start.
        'create_timeout' => (int) env('GEMINI_CONTEXT_CACHE_CREATE_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web search
    |--------------------------------------------------------------------------
    |
    | Web search is offered to the chat model on every turn: it is the primary
    | source of law when retrieval comes back empty, and a way to verify or
    | check for amendments when it does not.
    |
    | When `enabled` is on, the search does not run on the answering provider.
    | The model calls the `web_search` tool, and the search itself is delegated
    | to a small Gemini Flash agent with Google Search grounding, which reports
    | the sources back for the chat model to write from. Provider-native web
    | search is billed per query at a rate set by the answering provider rather
    | than by the size of the model, so delegating it moves the largest
    | non-token cost on a searching turn onto the cheapest model that can do
    | the searching well — the answer is still written by the chat model, from
    | the same sources.
    |
    | Turn it off to go back to each provider's native web search (Gemini's
    | Google Search, OpenAI's and Anthropic's web_search tools). Note that
    | Ollama has no native web search, so a local-model turn only has web
    | search at all while this is enabled.
    |
    | `max_searches` caps the searches one answer may run: each is separately
    | billed and separately waited on, so a model that decides to search its
    | way through a question is stopped rather than left to run.
    |
    */

    'web_search' => [
        'enabled' => (bool) env('WEB_SEARCH_DELEGATED', true),
        'provider' => env('WEB_SEARCH_PROVIDER', 'gemini'),
        // Defaults to whatever Flash the chat is configured to fall back to,
        // so the model only has to be bumped in one place.
        'model' => env('WEB_SEARCH_MODEL', env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash')),
        'max_results' => (int) env('WEB_SEARCH_MAX_RESULTS', 6),
        'max_searches' => (int) env('WEB_SEARCH_MAX_SEARCHES', 4),
        // What a plan without `deep_research` may run in one answer. Each
        // search is separately billed and separately waited on, so this is the
        // other half of the same lever as the retrieval caps above.
        'base_max_searches' => (int) env('WEB_SEARCH_BASE_MAX_SEARCHES', 2),
        'timeout' => (int) env('WEB_SEARCH_TIMEOUT', 60),

        /*
         * Fetch each search result once to find out what page it actually is.
         *
         * Google Search grounding reports its sources as redirects through
         * vertexaisearch.cloud.google.com titled with a bare domain
         * ("lawphil.net"), or as a bare URL with no title. A card built from
         * that names the publisher rather than the decision, and the model —
         * which sees the same titles — has nothing to tell one result from
         * another, so it attaches the case it is discussing to whichever
         * source it guesses. Resolving each one gives the card the page's own
         * title, gives the model something to check its attribution against,
         * and stores the real URL, which is also what lets a cited authority
         * be captured into the knowledge base.
         *
         * The fetches run in parallel inside the search, so the cost is one
         * page load added to a turn that already searched.
         */
        'resolve_sources' => (bool) env('WEB_SEARCH_RESOLVE_SOURCES', true),
        'resolve_timeout' => (int) env('WEB_SEARCH_RESOLVE_TIMEOUT', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trials
    |--------------------------------------------------------------------------
    |
    | A code-granted trial ends on whichever runs out first: the days on the
    | code, or the plan's message allowance counted across the organization.
    | The thresholds below decide when the single warning email goes out.
    |
    */

    'trials' => [
        'automatic_days' => (int) env('AUTOMATIC_TRIAL_DAYS', 14),
        'warn_days_remaining' => (int) env('TRIAL_WARN_DAYS', 3),
        'warn_messages_remaining' => (int) env('TRIAL_WARN_MESSAGES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deadline reminders
    |--------------------------------------------------------------------------
    |
    | A nightly sweep emails the owner of each case or task whose due date has
    | arrived or is about to. `lead_days` is how far ahead of the deadline a
    | reminder starts going out; set it to 0 to disable reminders entirely.
    |
    */

    'reminders' => [
        'lead_days' => (int) env('DEADLINE_REMINDER_LEAD_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document ingestion
    |--------------------------------------------------------------------------
    */

    'documents' => [
        'max_size_mb' => (int) env('DOCUMENT_MAX_SIZE_MB', 25),
        'chunk_size' => (int) env('DOCUMENT_CHUNK_SIZE', 500),
        'chunk_overlap' => (int) env('DOCUMENT_CHUNK_OVERLAP', 50),
        'queue' => env('DOCUMENT_PROCESSING_QUEUE', 'document-processing'),
        'image_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tiff', 'heic'],

        /*
        |--------------------------------------------------------------------------
        | At-rest encryption
        |--------------------------------------------------------------------------
        |
        | When enabled, uploaded documents are encrypted with a per-file key
        | before they are written to disk and decrypted on the fly when served
        | or processed. Existing plaintext files remain readable until deleted;
        | they are detected by the absence of the encryption header.
        |
        */

        'encrypt_at_rest' => (bool) env('DOCUMENT_ENCRYPT_AT_REST', true),

        /*
        |--------------------------------------------------------------------------
        | Refuse the unauthenticated legacy format
        |--------------------------------------------------------------------------
        |
        | Documents written before the integrity tag existed use format v1,
        | which encrypts but does not authenticate: an attacker with write
        | access to the disk can flip bits of the plaintext undetectably.
        | Those files stay readable so a deployment can migrate without
        | downtime. Run `saligan:reencrypt-documents` to rewrite them as v2,
        | then turn this on so the old format is rejected outright.
        |
        */

        'require_authenticated_encryption' => (bool) env('DOCUMENT_REQUIRE_AUTHENTICATED_ENCRYPTION', false),

        /*
        |--------------------------------------------------------------------------
        | Ingestion capacity limits
        |--------------------------------------------------------------------------
        |
        | Every chunk becomes a halfvec(768) row and an HNSW entry — measured at
        | roughly 3.6 kB per row — so an unbounded document is unbounded storage.
        | A 25 MB text-heavy PDF extracts to ~26M characters, which chunks into
        | ~58,000 rows (~210 MB of vectors and index) and ~3,600 embedding
        | requests. These caps bound one document's ingestion; the per-user
        | quota below bounds the account.
        |
        */

        'max_extracted_characters' => (int) env('DOCUMENT_MAX_EXTRACTED_CHARACTERS', 2_500_000),
        'max_chunks_per_document' => (int) env('DOCUMENT_MAX_CHUNKS', 5000),

        /*
        |--------------------------------------------------------------------------
        | Persistent index quota
        |--------------------------------------------------------------------------
        |
        | The paid tiers deliberately leave `documents_uploaded` uncapped and let
        | the monthly AI budget be the only spend gate — but that budget resets
        | every month while vectors persist forever, so spend is capped and
        | storage is not. This is the capacity gate: the maximum number of
        | indexed chunks a single account (or, for a team, the whole
        | organization) may hold at once. ~50,000 rows is ~180 MB of vectors.
        |
        */

        'max_indexed_chunks_per_user' => (int) env('DOCUMENT_MAX_INDEXED_CHUNKS_PER_USER', 50_000),

        /*
        |--------------------------------------------------------------------------
        | Ingestion concurrency and write batching
        |--------------------------------------------------------------------------
        |
        | `max_concurrent_ingests_per_user` stops one account from occupying the
        | whole document-processing worker pool. Embeddings are generated and
        | written in segments so a job that is killed mid-document keeps the
        | segments it already finished and resumes instead of re-embedding — and
        | re-paying for — the entire file. `insert_batch_size` keeps each
        | multi-row INSERT under Postgres' parameter limit (7 columns, so 500
        | rows is ~3,500 parameters against the 65,535 ceiling).
        |
        */

        'max_concurrent_ingests_per_user' => (int) env('DOCUMENT_MAX_CONCURRENT_INGESTS', 2),
        'embed_segment_chunks' => (int) env('DOCUMENT_EMBED_SEGMENT_CHUNKS', 512),
        'insert_batch_size' => (int) env('DOCUMENT_INSERT_BATCH_SIZE', 500),

        /*
        |--------------------------------------------------------------------------
        | OCR page ceiling
        |--------------------------------------------------------------------------
        |
        | Vision OCR bills and runs per page, so an unbounded scan is an
        | unbounded model bill from a single upload. Scans above this many pages
        | are refused with a message asking for the relevant sections instead.
        |
        */

        'max_ocr_pages' => (int) env('DOCUMENT_MAX_OCR_PAGES', 50),

        /*
        |--------------------------------------------------------------------------
        | Image OCR
        |--------------------------------------------------------------------------
        |
        | The provider and model used to transcribe text out of uploaded images
        | (scans, photos, screenshots). Provider names reference providers
        | defined in config/ai.php. Falls back to Ollama when the configured
        | provider has no API key.
        |
        */

        'ocr' => [
            'provider' => env('DOCUMENT_OCR_PROVIDER', 'gemini'),
            'model' => env('DOCUMENT_OCR_MODEL', env('GEMINI_CHAT_MODEL', 'gemini-3.7-flash')),
        ],

        /*
        |--------------------------------------------------------------------------
        | Case-file classification
        |--------------------------------------------------------------------------
        |
        | After a document is ingested, a model reads the opening of it and
        | files it under the case-file categories it belongs to. Classification
        | is a suggestion, never a verdict: anything the model is not at least
        | `min_confidence` sure of is left off, so the document surfaces in the
        | Unfiled queue for a person to decide instead of being filed wrongly.
        |
        | A category a person chose is never overwritten. `model` is optional —
        | left empty, each provider falls back to its own cheap default, since
        | this is a short classification call and not a drafting one.
        |
        */

        'classification' => [
            'enabled' => (bool) env('DOCUMENT_CLASSIFICATION_ENABLED', true),
            'provider' => env('DOCUMENT_CLASSIFICATION_PROVIDER', env('AI_CHAT_PROVIDER', 'anthropic')),
            'model' => env('DOCUMENT_CLASSIFICATION_MODEL'),
            'min_confidence' => (float) env('DOCUMENT_CLASSIFICATION_MIN_CONFIDENCE', 0.6),
            'max_categories' => (int) env('DOCUMENT_CLASSIFICATION_MAX_CATEGORIES', 3),
            'excerpt_characters' => (int) env('DOCUMENT_CLASSIFICATION_EXCERPT_CHARS', 6000),
            'timeout' => (int) env('DOCUMENT_CLASSIFICATION_TIMEOUT', 90),

            /*
             | Batched classification
             |
             | Switched on, a freshly ingested document is queued instead of
             | classified on the spot, and a scheduled sweep files it through
             | the provider's batch API at half the token cost. The trade is
             | latency: a document is usually filed within the hour, and both
             | APIs allow up to a day, so it lands in the Unfiled queue first
             | and sorts itself later. Off by default — when a document is
             | filed is a product decision, not a deployment one.
             |
             | Anthropic and Gemini both offer this; pointing `provider` above
             | at anything else keeps classification inline no matter what this
             | flag says. Gemini Flash is the cheaper of the two by a wide
             | margin, and classification is a short read-and-label call rather
             | than a drafting one, so it is the sensible default for the work.
             |
             | `max_requests` stays well inside Gemini's 20MB inline-batch
             | ceiling: 500 requests at the 6000-character excerpt above is
             | roughly 3MB.
             */

            'batch' => [
                'enabled' => (bool) env('DOCUMENT_CLASSIFICATION_BATCH', false),
                'max_requests' => (int) env('DOCUMENT_CLASSIFICATION_BATCH_MAX_REQUESTS', 500),
                'max_tokens' => (int) env('DOCUMENT_CLASSIFICATION_BATCH_MAX_TOKENS', 1024),
                'timeout' => (int) env('DOCUMENT_CLASSIFICATION_BATCH_TIMEOUT', 60),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal source crawler
    |--------------------------------------------------------------------------
    */

    'crawler' => [
        'enabled' => (bool) env('LEGAL_CRAWLER_ENABLED', true),
        'schedule' => env('LEGAL_CRAWLER_SCHEDULE', '0 2 * * *'),
        'user_agent' => env('LEGAL_CRAWLER_USER_AGENT', 'SaliganAIBot/1.0 (+https://saligan.ai/bot)'),
        'delay_ms' => (int) env('LEGAL_CRAWLER_DELAY_MS', 3000),
        'queue' => env('LEGAL_CRAWLER_QUEUE', 'legal-crawler'),
        'max_depth' => (int) env('LEGAL_CRAWLER_MAX_DEPTH', 2),
        'max_links_per_page' => (int) env('LEGAL_CRAWLER_MAX_LINKS_PER_PAGE', 25),
        'max_pages_per_run' => (int) env('LEGAL_CRAWLER_MAX_PAGES_PER_RUN', 500),

        /*
         * Refuse to fetch URLs that resolve to loopback, private, or
         * link-local addresses — the SSRF guard that keeps a seed URL or a
         * redirect from reaching the cloud metadata service, Redis, or
         * anything else bound inside the network. Leave this on in production;
         * it is disabled under test so faked HTTP does not need live DNS.
         */
        'block_private_addresses' => (bool) env('LEGAL_CRAWLER_BLOCK_PRIVATE_ADDRESSES', true),

        /*
         * The plain-language digest written for each crawled authority. Set
         * the provider to "none" to skip digesting entirely — the reader falls
         * back to full text, so this only costs the summary at the top.
         */
        'digest' => [
            'provider' => env('LEGAL_DIGEST_PROVIDER', 'gemini'),
            'model' => env('LEGAL_DIGEST_MODEL', env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash')),

            /*
             | Batched digesting
             |
             | Applies to the bulk producers only — the nightly crawl and the
             | `saligan:digest` backfill, which between them digest hundreds of
             | authorities nobody has asked for yet. Those go out as one batch
             | at half the token cost and land within the hour, a day at worst.
             |
             | A digest generated because somebody opened a source stays inline
             | whatever this says: a reader is waiting on that one, and it is
             | already the cheap case — only the authorities actually cited get
             | digested at all.
             |
             | Supported on Anthropic and Gemini; any other digest provider
             | keeps writing inline. `max_requests` is well inside Gemini's
             | 20MB inline-batch ceiling: 200 requests at the 20k-character
             | excerpt below is roughly 4MB.
             */

            'batch' => [
                'enabled' => (bool) env('LEGAL_DIGEST_BATCH', false),
                'max_requests' => (int) env('LEGAL_DIGEST_BATCH_MAX_REQUESTS', 200),
                'max_tokens' => (int) env('LEGAL_DIGEST_BATCH_MAX_TOKENS', 2048),
                'timeout' => (int) env('LEGAL_DIGEST_BATCH_TIMEOUT', 60),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intake form
    |--------------------------------------------------------------------------
    |
    | When the case already carries the narrative facts, the intake form stops
    | asking for them — the model drafts the "who, what, when, where" straight
    | from the case context instead of making the user retype it. The
    | thresholds below decide when a case counts as actually supplying those
    | facts.
    |
    | Both are deliberately about SUBSTANCE, not presence. The check used to be
    | `filled($case->description)`, so a three-word description ("land
    | dispute") or a single uploaded scan of an ID suppressed the entire form —
    | the model then had no channel left for the party names, addresses, and
    | amounts a case description never contains, and either invented them or
    | wrote bracketed placeholders that the export strips out.
    |
    | `min_description_characters` is the length at which a description reads
    | as a narrative rather than a label. `min_document_chunks` is how much
    | extracted text an uploaded document must yield to count as a source of
    | facts; at the default 500-character chunk size, two chunks is roughly a
    | page, which a photo of an ID or a receipt never reaches.
    |
    | Note that clearing these thresholds only drops the NARRATIVE fields from
    | the form (see ChatService::dropCaseCoveredFields). The form itself is
    | suppressed only when nothing whatsoever is left to ask.
    |
    */

    'intake' => [
        'min_description_characters' => (int) env('INTAKE_MIN_DESCRIPTION_CHARS', 60),
        'min_document_chunks' => (int) env('INTAKE_MIN_DOCUMENT_CHUNKS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    /*
     | The `max_*` caps are what a plan carrying `deep_research` retrieves; the
     | `base_*` caps are what every other plan does. Retrieved context is the
     | bulk of a message's input tokens (13,340 of 37,774 at the caps, per
     | EarningsModel), so this is both the largest lever on answer quality and
     | the largest lever on what an answer costs — which is what makes it worth
     | selling rather than setting once for everyone.
     |
     | The base caps are deliberately not a token gesture: four authorities and
     | two document passages answer most questions completely. Deep research
     | pays for the long tail where they do not.
     */
    'retrieval' => [
        'min_similarity' => (float) env('RETRIEVAL_MIN_SIMILARITY', 0.30),
        'max_legal_chunks' => (int) env('RETRIEVAL_MAX_LEGAL_CHUNKS', 6),
        'max_standard_chunks' => (int) env('RETRIEVAL_MAX_STANDARD_CHUNKS', 6),
        'max_document_chunks' => (int) env('RETRIEVAL_MAX_DOCUMENT_CHUNKS', 4),
        'base_max_legal_chunks' => (int) env('RETRIEVAL_BASE_MAX_LEGAL_CHUNKS', 4),
        'base_max_standard_chunks' => (int) env('RETRIEVAL_BASE_MAX_STANDARD_CHUNKS', 4),
        'base_max_document_chunks' => (int) env('RETRIEVAL_BASE_MAX_DOCUMENT_CHUNKS', 2),
    ],

];
