<?php

namespace App\Services\Google;

use Carbon\CarbonImmutable;

/** A Google Business Profile review (v4 Review resource), normalised. */
final readonly class GbpReview
{
    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function __construct(
        public string $name,
        public string $reviewId,
        public ?string $reviewerName,
        public bool $reviewerAnonymous,
        public int $starRating,
        public ?string $comment,
        public CarbonImmutable $createTime,
        public CarbonImmutable $updateTime,
        public ?string $replyComment,
        public ?CarbonImmutable $replyUpdateTime,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $name = (string) ($data['name'] ?? '');
        $reviewId = (string) ($data['reviewId'] ?? basename($name));

        return new self(
            name: $name,
            reviewId: $reviewId,
            reviewerName: $data['reviewer']['displayName'] ?? null,
            reviewerAnonymous: (bool) ($data['reviewer']['isAnonymous'] ?? false),
            starRating: self::STARS[$data['starRating'] ?? ''] ?? 0,
            comment: self::originalText($data['comment'] ?? null),
            createTime: CarbonImmutable::parse($data['createTime'] ?? 'now'),
            updateTime: CarbonImmutable::parse($data['updateTime'] ?? $data['createTime'] ?? 'now'),
            replyComment: $data['reviewReply']['comment'] ?? null,
            replyUpdateTime: isset($data['reviewReply']['updateTime']) ? CarbonImmutable::parse($data['reviewReply']['updateTime']) : null,
        );
    }

    /**
     * Google prefixes auto-translated reviews with "(Translated by Google)" and appends
     * the original after "(Original)". We keep the original text.
     */
    public static function originalText(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        if (preg_match('/\(Original\)\s*(.+)$/su', $comment, $m)) {
            return trim($m[1]);
        }

        return trim(preg_replace('/^\(Translated by Google\)\s*/u', '', $comment));
    }
}
