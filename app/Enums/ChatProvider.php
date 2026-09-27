<?php

namespace App\Enums;

/**
 * The provider that answered a turn, as ai-provider reports it.
 *
 * A record, never a choice: ai-provider selects the provider and model for
 * every turn (ADR-012), and Laravel only stores what it was told answered.
 */
enum ChatProvider: string
{
    case Ollama = 'ollama';
    case Gemini = 'gemini';
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Meta = 'meta';
    case Openrouter = 'openrouter';
}
