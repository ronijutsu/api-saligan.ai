<?php

namespace App\Support;

/**
 * Recovers the caveats out of a reply that wrote them as prose instead of
 * filing them through flag_advisories.
 *
 * Models skip tool calls — create_todo has carried a text fallback for exactly
 * this reason since before advisories existed, and a research turn is the case
 * where a skip is most likely, since the model is already writing the section
 * the persona describes. Without this, the answer's most important part would
 * silently never reach the panel.
 */
final class AdvisoryParser
{
    /**
     * Headings that open a caveats section. "Next steps" is deliberately absent:
     * those are tasks and belong to create_todo.
     */
    private const HEADING_PATTERN = '/^\s*(?:#{1,6}\s*)?(?:\d+[.)]\s*)?\**\s*(caveats?(?:\s*(?:,|and)\s*next\s+steps?)?|limitations?|important\s+considerations?|things?\s+to\s+watch\s+(?:out\s+)?for|risks?\s+and\s+caveats?|what\s+to\s+watch\s+out\s+for)[\s:*]*$/i';

    /**
     * Headings that close it — anything that starts a different section.
     */
    private const CLOSING_PATTERN = '/^\s*(?:#{1,6}\s*)?(?:\d+[.)]\s*)?\**\s*(sources?|next\s+steps?|references?|disclaimer|conclusion|summary|direct\s+answer|legal\s+basis|application)\b/i';

    /**
     * The caveats written into a reply, as items AdvisoryRecorder can store.
     *
     * Bulleted and numbered lines are authoritative when present: free prose
     * around them is intro text, not caveats. When the section carries no
     * bullets at all — the shape models that skip flag_advisories most often
     * write — each blank-line-separated paragraph is one caveat, with wrapped
     * lines joined so a sentence split across lines stays a single item.
     *
     * @return array<int, array<string, string>>
     */
    public static function fromReply(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        $sectionLines = [];
        $inSection = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inSection) {
                    $sectionLines[] = '';
                }

                continue;
            }

            if (preg_match(self::HEADING_PATTERN, $trimmed) === 1) {
                $inSection = true;

                continue;
            }

            if (! $inSection) {
                continue;
            }

            // A drafted document's own body must never be mined for caveats:
            // the section, if there is one, sits outside the markers.
            if (preg_match(self::CLOSING_PATTERN, $trimmed) === 1
                || str_contains($trimmed, '[[DOCUMENT_START]]')
                || str_contains($trimmed, '[[TODO_START]]')) {
                break;
            }

            $sectionLines[] = $line;
        }

        if ($sectionLines === []) {
            return [];
        }

        $bullets = [];

        foreach ($sectionLines as $line) {
            $title = self::titleFrom(trim($line));

            if ($title !== null) {
                $bullets[] = ['kind' => 'caveat', 'title' => $title, 'severity' => 'medium'];
            }
        }

        if ($bullets !== []) {
            return $bullets;
        }

        $items = [];
        $paragraph = '';

        $flush = function () use (&$paragraph, &$items): void {
            $title = self::titleFromParagraph($paragraph);
            $paragraph = '';

            if ($title !== null) {
                $items[] = ['kind' => 'caveat', 'title' => $title, 'severity' => 'medium'];
            }
        };

        foreach ($sectionLines as $line) {
            if (trim($line) === '') {
                $flush();

                continue;
            }

            $paragraph .= ($paragraph === '' ? '' : ' ').trim($line);
        }

        $flush();

        return array_slice($items, 0, 12);
    }

    /**
     * Whether a reply wrote a caveats section at all — the condition for the
     * fallback running.
     */
    public static function hasSection(string $text): bool
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match(self::HEADING_PATTERN, trim($line)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * One caveat from one line, or null when the line is not a caveat.
     *
     * Only bulleted and numbered lines qualify. Free prose inside a section
     * that also carries bullets is intro text left alone: a wrapped sentence
     * would otherwise arrive as two truncated half-caveats, and half a caveat
     * shown as a real one is worse than none.
     */
    private static function titleFrom(string $line): ?string
    {
        if (preg_match('/^(?:[-*•]|\d+[.)])\s+(.{12,})$/u', $line, $matches) !== 1) {
            return null;
        }

        return self::cleanTitle($matches[1]);
    }

    /**
     * One caveat from one joined paragraph, used only when the section has no
     * bullets at all. The higher length floor keeps single short fragments
     * ("See above.") out of the panel; the prompt steers models toward
     * bullets, so this path is the safety net, not the contract.
     */
    private static function titleFromParagraph(string $paragraph): ?string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $paragraph));

        $text = (string) preg_replace('/^(?:[-*•]|\d+[.)])\s+/u', '', $text);

        return self::cleanTitle($text, 30);
    }

    /**
     * @param  string  $raw  The candidate title text.
     */
    private static function cleanTitle(string $raw, int $minLength = 12): ?string
    {
        // Strip markdown emphasis and a leading "Label:" lead-in, keeping the
        // substance that follows it.
        $title = trim((string) preg_replace('/[*_`]/', '', $raw));
        $title = trim((string) preg_replace('/^[A-Z][A-Za-z\s]{0,24}:\s*/', '', $title));

        if (mb_strlen($title) < $minLength || ! str_contains($title, ' ')) {
            return null;
        }

        return mb_strimwidth($title, 0, 255, '…');
    }
}
