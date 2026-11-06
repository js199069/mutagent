<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Llm;

/**
 * جواب مدل را با کلیدی از روی پیام‌ها ذخیره می‌کند. اجرای دوباره‌ی همان مرحله توکنی خرج نمی‌کند،
 * و هر کس کش را داشته باشد، دقیقاً همان نتیجه را می‌گیرد (بنچمارک تکرارپذیر).
 */
final class CachingChatClient implements ChatClient
{
    public int $hits = 0;

    public function __construct(
        private ChatClient $inner,
        private string $dir,
        private string $namespace = '',
    ) {}

    public function complete(array $messages): string
    {
        $key = sha1($this->namespace . "\n" . json_encode($messages, JSON_UNESCAPED_UNICODE));
        $path = rtrim($this->dir, '/\\') . '/' . $key . '.txt';

        if (is_file($path)) {
            $this->hits++;

            return (string) file_get_contents($path);
        }

        $response = $this->inner->complete($messages);
        @mkdir($this->dir, 0777, true);
        file_put_contents($path, $response);

        return $response;
    }
}
