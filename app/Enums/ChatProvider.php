<?php

namespace App\Enums;

enum ChatProvider: string
{
    case Ollama = 'ollama';
    case Gemini = 'gemini';
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Meta = 'meta';
    case Openrouter = 'openrouter';

    /**
     * The provider to use by default for new conversations, taken from the
     * configured AI_CHAT_PROVIDER and falling back to Ollama.
     */
    public static function fromConfig(): self
    {
        return self::tryFrom((string) config('saligan.chat.provider')) ?? self::Ollama;
    }
}
