<?php

declare(strict_types=1);

namespace App\Infra;

/**
 * Structured log payload. Built by error/exception/syslog handlers
 * and serialised to JSON before being pushed to Loki.
 *
 * @param array<int, array{file?:string, line?:int, function?:string, class?:string, type?:string}> $trace
 * @param array{class:string, message:string}|null $previous
 */
final readonly class Message
{
    public function __construct(
        public string $type,
        public string $level,
        public string $message,
        public string $file = 'unknown',
        public int    $line = 0,
        public array  $trace = [],
        public int    $code = 0,
        public ?array $previous = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'message' => $this->message,
            'type'    => $this->type,
            'level'   => $this->level,
            'file'    => $this->file,
            'line'    => $this->line,
            'trace'   => $this->trace,
        ];

        if ($this->type === 'exception') {
            $data['code'] = $this->code;

            if ($this->previous !== null) {
                $data['previous'] = $this->previous;
            }
        }

        return $data;
    }
}
