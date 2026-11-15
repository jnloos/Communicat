<?php

namespace App\Study\Watch;

/** One message of the monitoring: what it is about (kind), how bad, and the text. */
final readonly class Alert
{
    public function __construct(
        public string $kind,
        public AlertLevel $level,
        public string $title,
        public string $body = '',
    ) {}

    public function text(): string
    {
        return $this->body === '' ? $this->title : "{$this->title}\n{$this->body}";
    }

    public function asReminder(int $minutesActive): self
    {
        return new self($this->kind, $this->level, __('study::watch.reminder', ['since' => self::duration($minutesActive), 'title' => $this->title]), $this->body);
    }

    public static function duration(int $minutes): string
    {
        return $minutes < 120 ? "{$minutes} min" : intdiv($minutes, 60).' h';
    }
}
