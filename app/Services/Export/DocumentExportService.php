<?php

namespace App\Services\Export;

use App\Support\DraftingIntent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\NumberFormat;

class DocumentExportService
{
    /**
     * Generate a Word document from markdown content.
     *
     * @param  array<int, array{title: string, content: string}>  $annexes
     */
    public function toWord(string $markdown, string $title, array $annexes = []): string
    {
        $markdown = $this->extractDocument($markdown);
        $markdown = $this->appendAnnexes($markdown, $annexes);

        // PhpWord writes text nodes raw by default, so an ampersand (or <, >)
        // in the drafted body would reach word/document.xml unescaped and make
        // the whole document invalid XML. Escaping keeps every text run
        // well-formed no matter what the model put in the letter.
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;

        $phpWord->addNumberingStyle('bulletedList', [
            'type' => 'hybridMultilevel',
            'levels' => [
                ['format' => NumberFormat::BULLET, 'text' => '•', 'alignment' => 'left', 'tabPos' => 720, 'left' => 720, 'hanging' => 360],
            ],
        ]);
        $phpWord->addNumberingStyle('numberedList', [
            'type' => 'multilevel',
            'levels' => [
                ['format' => NumberFormat::DECIMAL, 'text' => '%1.', 'alignment' => 'left', 'tabPos' => 720, 'left' => 720, 'hanging' => 360],
            ],
        ]);

        $section = $phpWord->addSection();
        $style = [
            'name' => 'Calibri',
            'size' => 11,
        ];

        $lines = preg_split('/\R/', $markdown);

        foreach ($lines as $line) {
            if (preg_match('/^x-{4,}x$/', trim($line))) {
                $section->addHtml('<hr>');
            } elseif (str_starts_with($line, '### ')) {
                $section->addTitle(substr($line, 4), 3);
            } elseif (str_starts_with($line, '## ')) {
                $section->addTitle(substr($line, 3), 2);
            } elseif (str_starts_with($line, '# ')) {
                $section->addTitle(substr($line, 2), 1);
            } elseif (preg_match('/^>\s?(.*)$/', $line, $quoteMatches)) {
                // Blockquote: render as indented italic text
                $text = $this->parseInlineFormatting($quoteMatches[1]);
                $phpWord->addFontStyle('blockquoteStyle', ['name' => 'Calibri', 'size' => 11, 'italic' => true, 'color' => '666666']);
                $section->addText($text, ['name' => 'Calibri', 'size' => 11, 'italic' => true, 'color' => '666666'], ['indentation' => ['left' => 720]]);
            } elseif ($this->isBulletLine($line)) {
                $text = $this->parseInlineFormatting($this->bulletText($line));
                $section->addListItem($text, 0, $style, 'bulletedList');
            } elseif (preg_match('/^(\d+)[.)] (.+)$/', $line, $matches)) {
                $text = $this->parseInlineFormatting($matches[2]);
                $section->addListItem($text, 0, $style, 'numberedList');
            } elseif (trim($line) === '') {
                $section->addTextBreak();
            } else {
                $text = $this->parseInlineFormatting($line);
                $section->addText($text, $style);
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'saligan_word_');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Generate a PDF from markdown content.
     *
     * @param  array<int, array{title: string, content: string}>  $annexes
     */
    public function toPdf(string $markdown, string $title, array $annexes = []): string
    {
        $pdf = Pdf::loadHTML($this->toPdfHtml($markdown, $title, $annexes));
        $pdf->setPaper('a4');

        $tempFile = tempnam(sys_get_temp_dir(), 'saligan_pdf_');
        $pdf->save($tempFile);

        return $tempFile;
    }

    /**
     * The full HTML document sent to the PDF renderer.
     *
     * @param  array<int, array{title: string, content: string}>  $annexes
     */
    public function toPdfHtml(string $markdown, string $title, array $annexes = []): string
    {
        $markdown = $this->extractDocument($markdown);
        $markdown = $this->appendAnnexes($markdown, $annexes);

        $html = $this->markdownToHtml($markdown);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>{$this->escapeHtml($title)}</title>
<style>
    body { font-family: sans-serif; font-size: 11pt; line-height: 1.6; color: #1a1a1a; }
    h1 { font-size: 20pt; margin-top: 24pt; margin-bottom: 8pt; }
    h2 { font-size: 16pt; margin-top: 18pt; margin-bottom: 6pt; }
    h3 { font-size: 13pt; margin-top: 14pt; margin-bottom: 4pt; }
    p { margin-bottom: 8pt; }
    ul, ol { margin-left: 20pt; margin-bottom: 8pt; }
    li { margin-bottom: 4pt; }
    hr { border: none; border-top: 1px solid #999999; margin: 8pt 0; }
    code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; font-size: 10pt; }
    strong { font-weight: bold; }
    em { font-style: italic; }
</style>
</head>
<body>
{$html}
</body>
</html>
HTML;
    }

    /**
     * Append the cited evidence documents as annexes after the extracted
     * document body. Each annex is headed by its original filename and carries
     * the full extracted text of the document, so the exported Word/PDF
     * contains the evidence the letter relied on rather than only a reference
     * to it. Annexes are joined to the letter by a horizontal rule and are not
     * part of the marked document itself.
     *
     * @param  array<int, array{title: string, content: string}>  $annexes
     */
    protected function appendAnnexes(string $body, array $annexes): string
    {
        if ($annexes === []) {
            return $body;
        }

        $blocks = [];

        foreach ($annexes as $index => $annex) {
            $title = trim((string) ($annex['title'] ?? ''));
            $content = trim((string) ($annex['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $letter = chr(65 + ($index % 26));
            $label = $letter.(intdiv($index, 26) > 0 ? (string) (intdiv($index, 26) + 1) : '');

            $blocks[] = "### ANNEX {$label} — ".($title === '' ? "Annex {$label}" : $title)
                ."\n\n".$content;
        }

        if ($blocks === []) {
            return $body;
        }

        return trim($body)
            ."\n\nx--------x\n\n"
            .implode("\n\nx--------x\n\n", $blocks);
    }

    /**
     * A short title for an exported document, taken from the first non-empty
     * line of the extracted letter body so exported files are named after the
     * document itself — never the thread or case name.
     */
    public function deriveTitle(string $content, string $fallback = 'Saligan AI Response'): string
    {
        foreach (preg_split('/\R/', $this->extractDocument($content)) ?: [] as $line) {
            $line = trim($line, " \t#*-");

            if ($line !== '') {
                return Str::limit($line, 80);
            }
        }

        return $fallback;
    }

    /**
     * Extract only the marked letter from a drafted reply. The document is
     * bounded by its defined start and end markers; chat-only content before
     * [[DOCUMENT_START]] and after [[DOCUMENT_END]] is dropped, and the
     * chat-only "Next Steps" checklist ([[TODO_START]] … [[TODO_END]]) is
     * removed from the body so exported files contain only the letter itself.
     * When no markers are present, the full content is used so legacy
     * messages still export in full. Drafts missing the closing marker
     * (common: the model reliably opens with [[DOCUMENT_START]] but
     * occasionally omits the end marker) are exported from the opening marker
     * onward, cut off at the start of the todo checklist when one follows, so
     * no chat-only content leaks into the exported file.
     */
    public function extractDocument(string $content): string
    {
        // Strip code-fence wrappers the model sometimes wraps entire responses in
        $content = $this->stripCodeFencesAndInternalReasoning($content);

        if (preg_match('/\[\[DOCUMENT_START\]\]\s*(.*?)\s*\[\[DOCUMENT_END\]\]/s', $content, $matches) === 1) {
            return $this->cleanBody((string) $matches[1]);
        }

        if (str_contains($content, '[[DOCUMENT_START]]')) {
            $body = substr($content, strpos($content, '[[DOCUMENT_START]]') + strlen('[[DOCUMENT_START]]'));

            // The letter's end is the start of the todo checklist when one is
            // present: the checklist closes the draft and is chat-only, so it
            // (and everything after it) never belongs in the exported file.
            // The marker may be wrapped in bold or use single brackets, so
            // locate it tolerantly.
            if (preg_match('/\[{1,2}TODO_START\]{1,2}/i', $body, $todoMatch, PREG_OFFSET_CAPTURE) === 1) {
                $body = substr($body, 0, (int) $todoMatch[0][1]);
            }

            return $this->cleanBody((string) $body);
        }

        return $this->cleanBody($content);
    }

    /**
     * Strip code-fence wrappers the model sometimes wraps entire responses in
     * (e.g. ```python ... ```) as well as internal reasoning the model leaks
     * into the visible output.
     */
    protected function stripCodeFencesAndInternalReasoning(string $content): string
    {
        $cleaned = $content;

        // Strip ```python ... ``` or ``` ... ``` wrappers around the entire response
        $cleaned = preg_replace('/^```(?:python|json|text|plaintext)?\s*\n/i', '', $cleaned);
        $cleaned = preg_replace('/\n```\s*$/i', '', $cleaned);

        // Strip background/internal reasoning lines the model sometimes leaks
        $cleaned = preg_replace('/^No tool calls required.*$/im', '', $cleaned);
        $cleaned = preg_replace('/^Note:\s*Since.*$/im', '', $cleaned);
        $cleaned = preg_replace('/^I have utilized.*$/im', '', $cleaned);
        $cleaned = preg_replace('/^Note:.*$/im', '', $cleaned);

        return trim($cleaned);
    }

    /**
     * Remove the hidden todo markers, the chat-only "Next Steps" checklist the
     * model wrote after the letter, and any meta commentary around it so
     * exported files only contain the letter itself. Also drops the leading
     * preamble the model sometimes opens the draft with, normalizes date
     * placeholders to today's date, and removes dangling bracketed
     * placeholders for facts the user never provided.
     */
    protected function cleanBody(string $body): string
    {
        // The legal disclaimer belongs outside the document markers but the
        // model occasionally leaks it into the exported body. Strip it so
        // exported files never contain the disclaimer.
        $cleaned = $this->stripDisclaimer($body);

        // The next-steps checklist is chat-only and must never be exported,
        // whether it was wrapped in [[TODO_START]]/[[TODO_END]] (handled
        // below) or written bare after a "Next Steps" heading.
        $cleaned = $this->stripNextStepsSection($cleaned);

        // Drop the marked checklist block along with any heading (e.g.
        // "Next Steps:") that introduces it. The markers may be bold-wrapped
        // or single-bracketed, so they are matched tolerantly.
        $cleaned = preg_replace(
            '/\s*(?:(?:#{1,6}\s+)?(?:\*\*)?(?:next steps?|steps? to take|recommended steps?|action items?|checklist|immediate steps?|next actions?|steps? to follow|what to do(?: next)?)\s*(?:\*\*)?\s*:?\s*\R+\s*)?[ \t*_\-–—~]*\[{1,2}TODO_START\]{1,2}[ \t*_\-–—~]*.*?[ \t*_\-–—~]*\[{1,2}TODO_END\]{1,2}[ \t*_\-–—~]*/is',
            '',
            $cleaned,
        );

        // Remove any step-heading line left dangling by the truncation above
        // (e.g. a draft cut at [[TODO_START]] still carries its "Next Steps:"
        // heading).
        $cleaned = preg_replace(
            '/^\s*(?:#{1,6}\s+)?(?:\*\*)?(?:next steps?|steps? to take|recommended steps?|action items?|checklist|immediate steps?|next actions?|steps? to follow|what to do(?: next)?)\s*(?:\*\*)?\s*:?\s*$/mi',
            '',
            (string) $cleaned,
        );

        $cleaned = preg_replace('/^[ \t*_\-–—~]*\[{1,2}(TODO|DOCUMENT)_(START|END)\]{1,2}[ \t*_\-–—~]*$/m', '', (string) $cleaned);
        $cleaned = preg_replace('/^\s*Next Steps Checklist Created Below Using create_todo Tool:\s*$/im', '', (string) $cleaned);

        // Strip the ATTACHMENTS section: the heading, any horizontal rule
        // before it, and the body text that follows until the next heading
        // or end of document.
        $cleaned = preg_replace(
            '/\s*-{3,}\s*\*{0,2}\s*ATTACHMENTS?\s*\*{0,2}\s*:?\s*.*?(?=\n\s*(?:#{1,6}\s|\*{2}\s*\[NOTE|$))/is',
            '',
            (string) $cleaned,
        );

        $cleaned = $this->stripPreamble((string) $cleaned);
        $cleaned = $this->normalizeDatePlaceholders((string) $cleaned);
        $cleaned = $this->stripBracketPlaceholderLines((string) $cleaned);
        $cleaned = $this->stripCitationTokens((string) $cleaned);
        $cleaned = $this->stripTrailingMarkdownArtifacts((string) $cleaned);
        $cleaned = self::normalizeCurrency((string) $cleaned);
        $cleaned = preg_replace('/\n{3,}/', "\n\n", (string) $cleaned);

        return trim(DraftingIntent::stripExportLinks((string) $cleaned));
    }

    /**
     * Replace the peso sign with the "PHP" label. The prompts already ask for
     * "PHP", but older drafts, user-uploaded templates, and quoted source
     * documents still carry "₱" — and the PDF renderer's built-in font has no
     * glyph for U+20B1, so it reaches the downloaded file as a literal "?".
     * Spacing is normalized so "₱3,000,000.00" becomes "PHP 3,000,000.00"
     * rather than "PHP3,000,000.00".
     */
    public static function normalizeCurrency(string $text): string
    {
        return (string) preg_replace('/\x{20B1}\s*/u', 'PHP ', $text);
    }

    /**
     * Remove the chat-only "Next Steps" checklist section even when the model
     * wrote it without the hidden TODO markers: the heading and the checklist
     * items that follow it are dropped, so no checklist content leaks into the
     * exported file.
     */
    protected function stripNextStepsSection(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?? [];

        $out = [];
        $inSection = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (self::isNextStepsHeading($trimmed)) {
                $inSection = true;

                continue;
            }

            if (! $inSection) {
                $out[] = $line;

                continue;
            }

            if ($trimmed === '') {
                continue;
            }

            // A line that is not a checklist item (e.g. a signature block or a
            // new section) closes the checklist section.
            if (! $this->looksLikeNextStepItem($trimmed)) {
                $inSection = false;
                $out[] = $line;

                continue;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Remove the legal disclaimer that the model sometimes includes inside the
     * document markers. The disclaimer belongs outside the markers but the model
     * occasionally leaks it into the exported body.
     */
    protected function stripDisclaimer(string $body): string
    {
        // Strip the disclaimer with various formatting: plain text, bold, or
        // wrapped in asterisks. The disclaimer text is a fixed string in the
        // chat prompt, so we match it literally.
        $patterns = [
            '/\s*Disclaimer:\s*I\'m a legal research and drafting-support assistant,\s*not a licensed Philippine attorney\.\s*This analysis should be reviewed by your lawyer before use in negotiation or litigation\.?\s*/i',
            '/\s*\*+\s*Disclaimer:\s*I\'m a legal research and drafting-support assistant,\s*not a licensed Philippine attorney\.\s*This analysis should be reviewed by your lawyer before use in negotiation or litigation\.?\s*\*+\s*/i',
        ];

        $cleaned = $body;
        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }

        return trim($cleaned);
    }

    /**
     * Whether the line reads like a next-steps / checklist heading, either on
     * its own line or as an inline label ("Next Steps: …").
     */
    protected static function isNextStepsHeading(string $line): bool
    {
        if (preg_match('/^(?:#{1,6}\s+)?\*{0,2}(?:next steps?|steps? to take|recommended steps?|action items?|checklist|immediate steps?|next actions?|steps? to follow|what to do(?: next)?)\*{0,2}\s*:?\s*$/i', $line) === 1) {
            return true;
        }

        return preg_match('/^(?:#{1,6}\s+)?\*{0,2}(?:next steps?|checklist|action items?|what to do(?: next)?)\s*:\s*.+/i', $line) === 1;
    }

    /**
     * Whether the line looks like a checklist item: bulleted, numbered,
     * bold-led, a "Label: detail" line, or a sentence-length action.
     */
    protected function looksLikeNextStepItem(string $line): bool
    {
        if (preg_match('/^\d+[.)]\s+(?!\*)(.+)$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^[-*+]\s+(?!\*)(.+)$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^\*\*(.+?)\*\*:?\s*(.*)$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z0-9][^\n]{0,90}?:.+/', $line) === 1 && ! str_ends_with($line, ':')) {
            return true;
        }

        return mb_strlen($line) > 12 && str_contains($line, ' ');
    }

    /**
     * Drop the meta-introduction paragraph the model sometimes writes before
     * the letter (e.g. "Based on the documents provided …, here is a formal
     * Demand Letter …"). Only the leading paragraph is considered, so letter
     * content is never touched.
     */
    protected function stripPreamble(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?? [];
        $count = count($lines);
        $start = 0;

        while ($start < $count && trim($lines[$start]) === '') {
            $start++;
        }

        if ($start >= $count || ! $this->isPreambleStart(trim($lines[$start]))) {
            return $body;
        }

        $end = $start;

        while ($end < $count && trim($lines[$end]) !== '') {
            $end++;
        }

        return implode("\n", array_slice($lines, $end));
    }

    /**
     * Whether a line opens a meta-introduction to the letter rather than the
     * letter itself.
     */
    protected function isPreambleStart(string $line): bool
    {
        $needle = mb_strtolower($line);

        $phrases = [
            'based on the documents provided',
            'based on your specific requirements',
            'based on your request',
            'based on the provided',
            'based on the above',
            'based on the information you provided',
            'here is your',
            'here is the',
            'here is a',
            'here is an',
            'below is',
            'as requested',
            'per your request',
            'attached herewith',
            'please find',
            'the following is',
            'we have prepared',
            'i have prepared',
            'i have drafted',
            'we have drafted',
            'in response to your request',
            'this letter serves',
            'this document serves',
            'draft a letter',
            'drafting a letter',
            'draft letter',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($needle, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace date placeholders and example dates in the letter's date line
     * with today's date, so exported files always carry the actual current
     * date instead of a token like "[Date]" or "(or current date)".
     */
    protected function normalizeDatePlaceholders(string $body): string
    {
        $today = now()->format('F j, Y');

        $lines = preg_split('/\R/', $body) ?? [];
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[(?:date|today\'s date|current date)\]$/i', $trimmed) === 1) {
                $out[] = $today;

                continue;
            }

            if (preg_match('/^(date|dated)\s*:\s*(.+)$/i', $line, $matches) === 1
                && $this->isDatePlaceholder(trim($matches[2]))) {
                $out[] = $matches[1].': '.$today;

                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Whether a date value is a placeholder or an example to be replaced,
     * e.g. "[Date]", "[Current Date]", or "October 24, 2024 (or current
     * date)".
     */
    protected function isDatePlaceholder(string $value): bool
    {
        if (preg_match('/^\[[^\]]+\]$/', $value) === 1) {
            return true;
        }

        return preg_match('/\(?\s*(?:or\s+)?(?:today\'s|today|current|insert|enter|use|the)\s+date\s*\)?/i', $value) === 1;
    }

    /**
     * Drop lines that are only bracketed placeholders for facts the user never
     * provided (e.g. "[Contact Number]", "[Email Address]") and dangling
     * "Label: [Placeholder]" lines. Citation tags such as [Web 1], [SRC K3F9],
     * and [DOC X1Y2] are untouched because they carry a digit.
     */
    protected function stripBracketPlaceholderLines(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?? [];
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[[A-Z][A-Za-z \']+\]\s*(?:\[\s*[A-Z][A-Za-z \']*\])*$/', $trimmed) === 1) {
                continue;
            }

            if (preg_match('/^[^:\n]{1,80}:\s*\[[A-Z][A-Za-z \']+\]\s*$/', $trimmed) === 1) {
                continue;
            }

            // Strip counsel placeholder lines: "Roll of Attorneys No. [___]",
            // "PTR No. [___], issued [date/place]", etc.
            if (preg_match('/^\s*(?:Roll of Attorneys|PTR|IBP|MCLE)\s+No\.\s*\[[_]+\]\s*/i', $trimmed) === 1) {
                continue;
            }

            // Strip standalone bracketed placeholders like "[Address / contact details]"
            if (preg_match('/^\s*\[(?:[_]+|[A-Za-z \/]+)\]\s*$/', $trimmed) === 1) {
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Remove inline citation tokens from the exported body: [SRC K3F9],
     * [DOC 197M], [Web 2], and the legacy [Source N] / [User Doc N] markers.
     * They are chat-layer references — the app renders them as badges and
     * source cards — and have no place inside a downloaded Word or PDF letter,
     * where they would print as raw codes the reader cannot resolve.
     *
     * Whitespace is handled by context so the surrounding sentence stays
     * intact: a token sandwiched between two words keeps one space ("a
     * [DOC X] b" → "a b"), while a token against punctuation or a boundary
     * takes its gap with it ("a [DOC X]." → "a.", "[DOC X]b" → "b").
     */
    protected function stripCitationTokens(string $body): string
    {
        return (string) preg_replace_callback(
            '/([ \t]?)\[(SRC|DOC|Web|Source|User\s+Doc)\s+[A-Z0-9]+\]([ \t]*)/i',
            fn (array $match): string => $match[1] !== '' && $match[3] !== '' ? ' ' : '',
            $body,
        );
    }

    /**
     * Strip trailing markdown artifacts like standalone asterisks, underscores,
     * or other formatting remnants that the model sometimes leaves at the end
     * of the document.
     */
    protected function stripTrailingMarkdownArtifacts(string $body): string
    {
        // Remove trailing standalone asterisks, underscores, or other markdown artifacts
        // Matches lines that are only made up of *, _, ~, or combinations
        $cleaned = preg_replace('/\n\s*[*_~]{1,3}\s*$/', '', $body);

        // Remove trailing whitespace
        return rtrim($cleaned);
    }

    /**
     * Whether a line is a markdown bullet: "- item", "* item", "+ item", or an
     * asterisk attached to its text ("*(words)") when the line is not italic
     * emphasis or bold.
     */
    protected function isBulletLine(string $line): bool
    {
        if (preg_match('/^[-*+]\s/', $line) === 1) {
            return true;
        }

        return preg_match('/^\*(?!\*)/', $line) === 1 && substr_count($line, '*') === 1;
    }

    /**
     * Strip the bullet marker from a bulleted line, returning its text.
     */
    protected function bulletText(string $line): string
    {
        if (preg_match('/^[-*+]\s+(.*)$/', $line, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^\*(?!\*)(.*)$/', $line, $matches) === 1) {
            return $matches[1];
        }

        return $line;
    }

    /**
     * Convert markdown to basic HTML.
     */
    protected function markdownToHtml(string $markdown): string
    {
        $html = $this->escapeHtml($markdown);

        $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);

        // Convert list markers to <li> runs first, so leading "*" bullets are
        // never mistaken for italic emphasis by the inline pass below.
        $lines = preg_split('/\R/', $html) ?? [];
        $out = [];
        $list = null;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            // Horizontal divider: x---x pattern → <hr>
            if (preg_match('/^x-{4,}x$/', trim($trimmed))) {
                if ($list !== null) {
                    $out[] = "</{$list}>";
                    $list = null;
                }
                $out[] = '<hr>';

                continue;
            }

            // Blockquote: "> text" → <blockquote>text</blockquote>
            if (preg_match('/^>\s?(.*)$/', $trimmed, $quoteMatches)) {
                if ($list !== null) {
                    $out[] = "</{$list}>";
                    $list = null;
                }
                $out[] = '<blockquote>'.$quoteMatches[1].'</blockquote>';

                continue;
            }

            if ($this->isBulletLine($trimmed)) {
                if ($list !== 'ul') {
                    if ($list !== null) {
                        $out[] = "</{$list}>";
                    }
                    $out[] = '<ul>';
                    $list = 'ul';
                }

                $out[] = '<li>'.$this->bulletText($trimmed).'</li>';

                continue;
            }

            if (preg_match('/^(\d+)[.)]\s+(.*)$/', $trimmed, $matches)) {
                if ($list !== 'ol') {
                    if ($list !== null) {
                        $out[] = "</{$list}>";
                    }
                    $out[] = '<ol>';
                    $list = 'ol';
                }

                $out[] = '<li>'.$matches[2].'</li>';

                continue;
            }

            if ($list !== null) {
                $out[] = "</{$list}>";
                $list = null;
            }

            $out[] = $trimmed;
        }

        if ($list !== null) {
            $out[] = "</{$list}>";
        }

        $html = implode("\n", $out);

        $html = preg_replace('/(<(?:ul|ol|li)>)[\r\n]+/', '$1', $html);
        $html = preg_replace('/[\r\n]+(<\/(?:ul|ol|li)>)/', '$1', $html);

        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
        $html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);
        // Markdown links: [text](url) → <a href="url">text</a>
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
        // Blockquote styling: add left border and indent
        $html = preg_replace('/<blockquote>(.*?)<\/blockquote>/s', '<blockquote style="border-left: 3px solid #ccc; padding-left: 10px; margin: 8pt 0; color: #666;">$1</blockquote>', $html);

        $html = preg_replace('/\n{2,}/', '</p><p>', $html);
        $html = '<p>'.$html.'</p>';
        $html = str_replace("\n", '<br>', $html);

        return $html;
    }

    /**
     * Parse inline markdown formatting (bold, italic, code, links).
     */
    protected function parseInlineFormatting(string $text): string
    {
        $text = str_replace('**', '', $text);
        $text = str_replace('*', '', $text);
        $text = str_replace('`', '', $text);
        // Strip markdown link syntax [text](url) → text (url)
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $text);

        return $text;
    }

    /**
     * Escape HTML entities.
     */
    protected function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
