<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Llm;

use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** هر سرویس سازگار با OpenAI: Ollama، سرویس‌های واسط، سرور خودتان. */
final class OpenAiCompatibleClient implements ChatClient
{
    private HttpClientInterface $http;

    public function __construct(
        private string $baseUrl,
        private string $model,
        private ?string $apiKey = null,
        private float $temperature = 0.2,
        private int $timeout = 180,
        private int $retries = 2,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    public static function fromEnv(?string $model = null): self
    {
        $model ??= getenv('MUTAGENT_LLM_MODEL') ?: null;
        if ($model === null) {
            throw new RuntimeException('Set MUTAGENT_LLM_MODEL (and MUTAGENT_LLM_URL, MUTAGENT_LLM_KEY) or pass --model.');
        }

        return new self(
            baseUrl: getenv('MUTAGENT_LLM_URL') ?: 'http://localhost:11434/v1',
            model: $model,
            apiKey: getenv('MUTAGENT_LLM_KEY') ?: null,
        );
    }

    public function complete(array $messages): string
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
                    'headers' => $this->apiKey !== null ? ['Authorization' => 'Bearer ' . $this->apiKey] : [],
                    'json' => ['model' => $this->model, 'messages' => $messages, 'temperature' => $this->temperature],
                    'timeout' => $this->timeout,
                ]);
                $data = $response->toArray();

                return (string) ($data['choices'][0]['message']['content']
                    ?? throw new RuntimeException('LLM response has no message content'));
            } catch (TransportExceptionInterface|ServerExceptionInterface $e) {
                // خطای موقت (قطع اتصال، ۵۰۲/۵۰۳): دوباره، با فاصله‌ی ۳ و ۶ ثانیه
                if (++$attempt > $this->retries) {
                    throw new RuntimeException('LLM request failed: ' . $e->getMessage(), previous: $e);
                }
                sleep(3 * $attempt);
            }
        }
    }
}
