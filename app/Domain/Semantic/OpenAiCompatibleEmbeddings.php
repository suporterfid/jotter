<?php

namespace App\Domain\Semantic;

use App\Domain\Semantic\Contracts\EmbeddingProvider;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POST {base_url}/embeddings, the OpenAI shape that Ollama, LM Studio, vLLM
 * and most gateways also serve. The API key is sent only to base_url.
 */
final class OpenAiCompatibleEmbeddings implements EmbeddingProvider
{
    public function embed(array $inputs, int $timeoutSeconds): array
    {
        if ($inputs === []) {
            return [];
        }

        // model() also carries the dimensions to tell vector spaces apart; the API takes the bare name.
        $payload = ['model' => (string) config('jotter.semantic.model', 'text-embedding-3-small'), 'input' => array_values($inputs)];
        $dimensions = (int) config('jotter.semantic.dimensions', 0);
        if ($dimensions > 0) {
            $payload['dimensions'] = $dimensions;
        }

        $request = Http::timeout(max(1, $timeoutSeconds))->connectTimeout(min(5, max(1, $timeoutSeconds)))->acceptJson();
        $key = (string) config('jotter.semantic.api_key', '');
        if ($key !== '') {
            $request = $request->withToken($key);
        }

        try {
            $response = $request->post(rtrim((string) config('jotter.semantic.base_url'), '/').'/embeddings', $payload);
        } catch (Throwable $exception) {
            throw new EmbeddingFailed('Embedding provider unreachable: '.class_basename($exception));
        }
        if (! $response->successful()) {
            throw new EmbeddingFailed(sprintf('Embedding provider answered HTTP %d.', $response->status()));
        }

        $data = $response->json('data');
        if (! is_array($data) || count($data) !== count($inputs)) {
            throw new EmbeddingFailed('Embedding provider returned an unexpected payload.');
        }

        $vectors = [];
        foreach ($data as $position => $item) {
            $index = is_array($item) && isset($item['index']) ? (int) $item['index'] : $position;
            $vector = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (! is_array($vector) || $vector === []) {
                throw new EmbeddingFailed('Embedding provider returned an empty vector.');
            }
            $vectors[$index] = array_map('floatval', array_values($vector));
        }
        ksort($vectors);

        return array_values($vectors);
    }

    public function model(): string
    {
        $dimensions = (int) config('jotter.semantic.dimensions', 0);

        return (string) config('jotter.semantic.model', 'text-embedding-3-small').($dimensions > 0 ? '@'.$dimensions : '');
    }
}
