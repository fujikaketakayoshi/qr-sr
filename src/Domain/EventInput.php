<?php

declare(strict_types=1);

namespace QrRally\Domain;

final readonly class EventInput
{
    public function __construct(
        public string $name,
        public string $description,
        public string $noticeText,
        public string $startsAt,
        public string $endsAt,
        public bool $isPaused,
        public string $pauseMessage,
        public int $requiredStampCount,
        public string $completionMessage,
    ) {
    }
}
