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
        'connect_timeout' => (int) env('AI_PROVIDER_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('AI_PROVIDER_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal crawler (on-demand captures)
    |--------------------------------------------------------------------------
    |
    | The dedicated crawler downloads a cited PDF and runs it through OCR when
    | it has no text layer. Leave the url empty to disable: scanned PDFs are
    | then reported as unreadable rather than read.
    |
    */

    'legal_crawler' => [
        'url' => env('LEGAL_CRAWLER_URL'),
        'secret' => env('LEGAL_CRAWLER_SECRET'),
        'timeout' => (int) env('LEGAL_CRAWLER_TIMEOUT', 15),
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
    | Web search budget
    |--------------------------------------------------------------------------
    |
    | How many web searches one answer may run, by plan: a plan carrying
    | `deep_research` gets `max_searches`, every other plan with web search
    | gets `base_max_searches`. Each search is separately billed and waited
    | on, so this is a plan entitlement sent to ai-provider with every turn.
    | Whether search is switched on for the deployment, and which model runs
    | it, is ai-provider's setting.
    |
    */

    'web_search' => [
        'max_searches' => (int) env('WEB_SEARCH_MAX_SEARCHES', 4),
        'base_max_searches' => (int) env('WEB_SEARCH_BASE_MAX_SEARCHES', 2),
        'read_timeout' => (int) env('WEB_SEARCH_READ_TIMEOUT', 10),
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

];
