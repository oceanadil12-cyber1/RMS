<?php
/**
 * Claude (Anthropic API) for the chat assistant; loaded by chat.php when
 * config 'chat_provider' is 'claude'.
 *
 * Needs PHP 8.1+ and the official Anthropic PHP SDK. Install it once, in this
 * api/ folder:   composer require anthropic-ai/sdk
 * (or run that on your computer and upload the api/vendor/ folder it creates).
 */

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

/** Returns [reply, usage]. Throws RuntimeException with a message for the site owner. */
function ask_claude(string $system, array $messages): array
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('The Anthropic SDK is missing: run "composer require anthropic-ai/sdk" in the api folder.');
    require_once $autoload;

    $c = config();
    if ($c['anthropic_api_key'] === '') throw new RuntimeException("Add 'anthropic_api_key' to api/config.php.");

    try {
        $client = new Client(apiKey: $c['anthropic_api_key']);
        $message = $client->messages->create(
            model: $c['claude_model'],
            maxTokens: 4096,
            // The long facts block is cached, so follow-up messages cost ~10% for it.
            system: [
                ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            messages: $messages,
        );
    } catch (AuthenticationException $e) {
        throw new RuntimeException("Claude rejected the API key in config.php ('anthropic_api_key').", 0, $e);
    } catch (RateLimitException $e) {
        throw new RuntimeException('Claude is rate-limiting this account; try again in a minute.', 0, $e);
    } catch (APIStatusException $e) {
        $type = $e->type?->value;
        if ($type === 'billing_error') throw new RuntimeException('The Anthropic account is out of credit. Add credit at console.anthropic.com.', 0, $e);
        throw new RuntimeException('Claude API error' . ($type ? " ($type)" : '') . ': ' . $e->getMessage(), 0, $e);
    } catch (APIConnectionException $e) {
        throw new RuntimeException('Could not reach the Claude API: ' . $e->getMessage(), 0, $e);
    }

    $usage = [
        'input_tokens'  => $message->usage->inputTokens ?? 0,
        'cache_read'    => $message->usage->cacheReadInputTokens ?? 0,
        'cache_write'   => $message->usage->cacheCreationInputTokens ?? 0,
        'output_tokens' => $message->usage->outputTokens ?? 0,
    ];
    if ($message->stopReason === 'refusal') {
        return ["Sorry, I can't help with that one. For anything about the bar, ask away or call us at (954) 565-2526.", $usage];
    }
    $reply = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') $reply .= $block->text;
    }
    return [trim($reply), $usage];
}
