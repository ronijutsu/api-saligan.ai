<?php

namespace App\Support;

/**
 * Fences untrusted content (uploaded documents, case descriptions, matter
 * memory, the user's profile) before it goes to ai-provider, so the model
 * reads it as quoted facts rather than instructions. The prompt's own
 * injection and privacy rules live in ai-provider.
 */
final class PromptGuard
{
    /**
     * Boundary markers that frame untrusted content (uploaded documents,
     * retrieved legal text, case descriptions, template conventions) so the
     * model treats it as quoted facts rather than instructions.
     */
    public const DATA_START = '[[UNTRUSTED DATA START]]';

    public const DATA_END = '[[UNTRUSTED DATA END]]';

    /**
     * Wrap untrusted content so the model treats it as quoted facts rather
     * than instructions. Empty content is returned unchanged.
     */
    public static function wrap(string $content): string
    {
        $content = self::neutralizeMarkers(trim($content));

        if ($content === '') {
            return $content;
        }

        return self::DATA_START."\n".$content."\n".self::DATA_END;
    }

    /**
     * Defuse control markers embedded in untrusted content. Without this a
     * document or template containing "[[UNTRUSTED DATA END]]" would close the
     * fence early and have the rest of its text read as system instructions;
     * the document, todo, and memory markers are neutralized for the same
     * reason, so quoted content can never forge a parsed block.
     */
    public static function neutralizeMarkers(string $content): string
    {
        return (string) preg_replace(
            '/\[\[\s*(UNTRUSTED\s+DATA\s+(?:START|END)|DOCUMENT_(?:START|END)|TODO_(?:START|END)|MEMORY_WRITE_(?:START|END)|\/?\s*NEED_INFO(?:_END)?)\s*\]\]/i',
            '(marker removed)',
            $content,
        );
    }
}
