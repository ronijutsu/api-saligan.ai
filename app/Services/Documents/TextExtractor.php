<?php

namespace App\Services\Documents;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Reads an uploaded file, either as plain text or as Markdown.
 *
 * The two are not interchangeable, which is why both exist. A template is
 * parsed for `[Placeholder]` tokens and filled in place, so it must see the
 * characters the document actually contains — Markdown escaping alone would
 * hide every placeholder from it. A source opened from a citation is the
 * opposite case: it is shown to a reader, and a decision's structure is how a
 * lawyer navigates it, so extraction keeps the headings, lists, and tables
 * instead of flattening them into one undifferentiated wall of characters.
 */
class TextExtractor
{
    /**
     * The number of pages in the file, for work that is billed per page.
     *
     * Vision OCR is the caller: it runs once per page, so the page count is
     * what the ingestion ledger charges and what the OCR ceiling is checked
     * against. A PDF states its page count in its own catalogue; anything else
     * (an image, a DOCX, a text file) counts as a single unit.
     */
    public function pageCount(string $fullPath, string $mimeType): int
    {
        if ($mimeType !== 'application/pdf') {
            return 1;
        }

        try {
            return max(1, count((new PdfParser)->parseFile($fullPath)->getPages()));
        } catch (\Throwable) {
            // An unreadable PDF is about to fail extraction with a proper
            // message; a page count must not be the thing that throws first.
            return 1;
        }
    }

    /**
     * Extract plain text from a local file based on its MIME type.
     */
    public function extract(string $fullPath, string $mimeType): string
    {
        return match (true) {
            $mimeType === 'application/pdf' => $this->plainPdf($fullPath),
            $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->plainDocx($fullPath),
            default => $this->plainFile($fullPath),
        };
    }

    /**
     * Extract the same file as Markdown, for anything that will be read rather
     * than parsed — the citation reader, and the chunks and digest behind it.
     */
    public function extractMarkdown(string $fullPath, string $mimeType): string
    {
        return match (true) {
            $mimeType === 'application/pdf' => $this->extractPdf($fullPath),
            $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocx($fullPath),
            default => $this->extractPlainText($fullPath),
        };
    }

    /**
     * Plain text from a PDF: the parser's own output, untouched.
     */
    protected function plainPdf(string $path): string
    {
        return (new PdfParser)->parseFile($path)->getText();
    }

    /**
     * Plain text from a DOCX: every run's text, one per line.
     */
    protected function plainDocx(string $path): string
    {
        $document = IOFactory::load($path, 'Word2007');

        $parts = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->collectPlainText($element, $parts);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Recursively collect text from a PhpWord element tree, without markup.
     *
     * @param  array<int, string>  $parts
     */
    protected function collectPlainText(object $element, array &$parts): void
    {
        if ($element instanceof TextRun) {
            $text = trim($element->getText());

            if ($text !== '') {
                $parts[] = $text;
            }

            return;
        }

        if ($element instanceof AbstractContainer) {
            foreach ($element->getElements() as $child) {
                $this->collectPlainText($child, $parts);
            }

            return;
        }

        if (method_exists($element, 'getText')) {
            $text = trim((string) $element->getText());

            if ($text !== '') {
                $parts[] = $text;
            }
        }
    }

    /** Read a file as-is. */
    protected function plainFile(string $path): string
    {
        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * Extract text from a PDF using the local parser.
     *
     * A PDF carries no structure worth trusting — the parser returns a stream
     * of positioned text — so the structure here is inferred, and only where
     * the evidence is strong (see `inferStructure`).
     */
    protected function extractPdf(string $path): string
    {
        $parser = new PdfParser;

        return $this->inferStructure($parser->parseFile($path)->getText());
    }

    /**
     * Extract a DOCX as Markdown using PhpWord.
     *
     * Unlike a PDF, a DOCX states its own structure: headings are headings,
     * list items are list items, and a bold run is marked as one. All of that
     * is carried across rather than flattened.
     */
    protected function extractDocx(string $path): string
    {
        $document = IOFactory::load($path, 'Word2007');

        $blocks = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->collectElement($element, $blocks);
            }
        }

        return $this->joinBlocks($blocks);
    }

    /**
     * Recursively turn a PhpWord element tree into Markdown blocks.
     *
     * @param  array<int, string>  $blocks
     */
    protected function collectElement(object $element, array &$blocks): void
    {
        if ($element instanceof Title) {
            $depth = min(max((int) $element->getDepth(), 1), 6);
            $text = trim($this->inlineText($element->getText()));

            if ($text !== '') {
                $blocks[] = str_repeat('#', $depth).' '.$text;
            }

            return;
        }

        if ($element instanceof ListItem) {
            $text = trim($this->inlineText($element->getTextObject()));

            if ($text !== '') {
                $blocks[] = $this->listMarker($element->getDepth()).$text;
            }

            return;
        }

        if ($element instanceof ListItemRun) {
            $text = $this->runText($element);

            if ($text !== '') {
                $blocks[] = $this->listMarker($element->getDepth()).$text;
            }

            return;
        }

        if ($element instanceof Table) {
            $table = $this->tableMarkdown($element);

            if ($table !== '') {
                $blocks[] = $table;
            }

            return;
        }

        if ($element instanceof TextRun) {
            $text = $this->runText($element);

            if ($text === '') {
                return;
            }

            // Word writes a heading as a paragraph carrying a Heading style
            // rather than as a Title element, so the style name is the only
            // thing that distinguishes one.
            $heading = $this->headingLevel($element);

            $blocks[] = $heading === null ? $text : str_repeat('#', $heading).' '.$text;

            return;
        }

        if ($element instanceof AbstractContainer) {
            foreach ($element->getElements() as $child) {
                $this->collectElement($child, $blocks);
            }

            return;
        }

        if (method_exists($element, 'getText')) {
            $text = trim($this->inlineText($element->getText()));

            if ($text !== '') {
                $blocks[] = $text;
            }
        }
    }

    /**
     * One element's text as a clean inline string.
     *
     * Accepts what PhpWord actually hands back, which is a string from some
     * elements and a Text object from others (a ListItem's text, for one).
     * Markdown's own control characters are escaped so a document that happens
     * to contain "*" or "_" does not render as emphasis it never had.
     *
     * Whitespace is collapsed but deliberately not trimmed: the space between
     * two runs of one sentence is stored at the edge of a run, so trimming
     * here welds "charged with " and "estafa" into "charged withestafa".
     * Callers that want a whole block trim it themselves.
     */
    protected function inlineText(mixed $value): string
    {
        if (is_object($value)) {
            $value = method_exists($value, 'getText') ? $value->getText() : '';
        }

        if (! is_string($value)) {
            return '';
        }

        $text = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return preg_replace('/([*_`\[\]])/u', '\\\\$1', $text) ?? $text;
    }

    /**
     * A text run as Markdown, with each child's bold and italic carried over.
     */
    protected function runText(object $run): string
    {
        if (! method_exists($run, 'getElements')) {
            return trim($this->inlineText(method_exists($run, 'getText') ? $run->getText() : ''));
        }

        $parts = [];

        foreach ($run->getElements() as $child) {
            if ($child instanceof Text) {
                $parts[] = $this->emphasize($this->inlineText($child->getText()), $child);

                continue;
            }

            if ($child instanceof TextRun) {
                $parts[] = $this->runText($child);

                continue;
            }

            if (method_exists($child, 'getText')) {
                $parts[] = $this->inlineText($child->getText());
            }
        }

        return trim(preg_replace('/ {2,}/', ' ', implode('', $parts)) ?? '');
    }

    /**
     * Wrap a run's text in Markdown emphasis when its font style says so.
     * Whitespace is kept outside the marks — `** bold **` is not emphasis.
     */
    protected function emphasize(string $text, Text $element): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $style = $element->getFontStyle();

        if (! is_object($style) || ! method_exists($style, 'isBold')) {
            return $text;
        }

        $marks = '';

        if ($style->isBold() === true) {
            $marks .= '**';
        }

        if (method_exists($style, 'isItalic') && $style->isItalic() === true) {
            $marks .= '*';
        }

        if ($marks === '') {
            return $text;
        }

        preg_match('/^(\s*)(.*?)(\s*)$/s', $text, $matches);

        return $matches[1].$marks.$matches[2].strrev($marks).$matches[3];
    }

    /**
     * The heading level a paragraph's style implies, or null when it is body
     * text. Word names these "Heading1".."Heading6"; some producers write
     * "heading 1".
     */
    protected function headingLevel(object $element): ?int
    {
        if (! method_exists($element, 'getParagraphStyle')) {
            return null;
        }

        $style = $element->getParagraphStyle();
        $name = is_object($style) && method_exists($style, 'getStyleName')
            ? (string) $style->getStyleName()
            : (is_string($style) ? $style : '');

        if (preg_match('/^heading\s*([1-6])$/i', trim($name), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /** The bullet for a list item at the given nesting depth. */
    protected function listMarker(int $depth): string
    {
        return str_repeat('  ', max($depth, 0)).'- ';
    }

    /**
     * A PhpWord table as a Markdown table. The first row is treated as the
     * header, which is what a document's tables almost always mean by it.
     */
    protected function tableMarkdown(Table $table): string
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $blocks = [];

                foreach ($cell->getElements() as $element) {
                    $this->collectElement($element, $blocks);
                }

                // A cell is one Markdown line: a pipe or a newline inside it
                // would break the row apart.
                $cells[] = str_replace(['|', "\n"], ['\\|', ' '], trim(implode(' ', $blocks)));
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $width = max(array_map('count', $rows));
        $lines = [];

        foreach ($rows as $index => $cells) {
            $cells = array_pad($cells, $width, '');
            $lines[] = '| '.implode(' | ', $cells).' |';

            if ($index === 0) {
                $lines[] = '| '.implode(' | ', array_fill(0, $width, '---')).' |';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Read a plain-text file (TXT / MD / etc.).
     *
     * A file that is already Markdown is left exactly as it is; anything else
     * gets the same structural inference a PDF gets.
     */
    protected function extractPlainText(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return '';
        }

        return $this->looksLikeMarkdown($contents) ? $contents : $this->inferStructure($contents);
    }

    /** Whether the text already carries Markdown structure of its own. */
    protected function looksLikeMarkdown(string $text): bool
    {
        return preg_match('/^(#{1,6} |[-*] |\d+\. |> |```)/m', $text) === 1;
    }

    /**
     * Infer Markdown structure from unstructured text.
     *
     * Deliberately conservative: only a short, standalone line that is either
     * fully upper-case or a recognised legal division ("ARTICLE V", "SEC. 12",
     * "RESOLUTION") becomes a heading, and enumerated paragraphs become list
     * items. Everything else is left as a paragraph, because a wrong heading
     * is worse than a missing one — it invents structure the authority does
     * not have.
     */
    protected function inferStructure(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);

        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);

            if ($trimmed === '') {
                $out[] = '';

                continue;
            }

            $out[] = $this->isHeadingLine($trimmed)
                ? '## '.$trimmed
                : ($this->isEnumeratedLine($trimmed) ? '- '.$trimmed : $trimmed);
        }

        return $this->joinBlocks($out, "\n");
    }

    /** Whether a standalone line reads as a section heading. */
    protected function isHeadingLine(string $line): bool
    {
        $length = mb_strlen($line);

        if ($length === 0 || $length > 90 || str_ends_with($line, '.')) {
            return false;
        }

        if (preg_match('/^(ARTICLE|ARTICLES|SECTION|SEC\.|RULE|TITLE|CHAPTER|BOOK|PART|ANNEX|APPENDIX|SCHEDULE)\b/i', $line) === 1) {
            return true;
        }

        if (preg_match('/^(DECISION|RESOLUTION|ORDER|SYLLABUS|FACTS|ISSUES?|RULING|HELD|DISPOSITIVE PORTION|SO ORDERED)$/i', $line) === 1) {
            return true;
        }

        // Fully upper-case and multi-word: a caption or a section title, never
        // an ordinary sentence.
        return mb_strtoupper($line) === $line
            && preg_match('/\p{Lu}/u', $line) === 1
            && str_word_count($line) >= 2;
    }

    /** Whether a line is an enumerated paragraph, e.g. "(a) ..." or "1) ...". */
    protected function isEnumeratedLine(string $line): bool
    {
        return preg_match('/^(\(\s*[a-z0-9]{1,4}\s*\)|[a-z0-9]{1,3}[\)\.])\s+\S/i', $line) === 1;
    }

    /**
     * Join blocks into a document, collapsing runs of blank lines so the
     * result is valid, readable Markdown.
     *
     * @param  array<int, string>  $blocks
     */
    protected function joinBlocks(array $blocks, string $glue = "\n\n"): string
    {
        $blocks = array_map('rtrim', $blocks);
        $joined = '';

        foreach ($blocks as $index => $block) {
            if ($index === 0) {
                $joined = $block;

                continue;
            }

            // Two adjacent list items are one list. Separating them with a
            // blank line makes CommonMark render a loose list — every item
            // wrapped in its own paragraph — which reads as a stack of
            // disconnected lines rather than an enumeration.
            $tight = $this->isListLine($block) && $this->isListLine($blocks[$index - 1]);

            $joined .= ($tight ? "\n" : $glue).$block;
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", $joined) ?? $joined);
    }

    /** Whether a block is a single Markdown list item. */
    protected function isListLine(string $block): bool
    {
        return preg_match('/^\s*- \S/', $block) === 1 && ! str_contains($block, "\n");
    }
}
