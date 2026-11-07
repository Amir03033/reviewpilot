<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;

/**
 * Google Business Profile API surface used by ReviewPilot.
 * See docs/blueprint/07-api-integration-spec.md.
 */
interface GbpClient
{
    /** @return array<int, array<string, mixed>> Raw account resources (name, accountName, type, role). */
    public function listAccounts(GoogleConnection $connection): array;

    /** @return array<int, array<string, mixed>> Raw location resources for an account. */
    public function listLocations(GoogleConnection $connection, string $accountName): array;

    /**
     * @return array{reviews: array<int, GbpReview>, nextPageToken: ?string, averageRating: ?float, totalReviewCount: ?int}
     */
    public function listReviews(GoogleConnection $connection, string $v4Parent, ?string $pageToken = null, int $pageSize = 50): array;

    public function getReview(GoogleConnection $connection, string $reviewName): GbpReview;

    /** @return array<string, mixed> The ReviewReply resource. */
    public function updateReply(GoogleConnection $connection, string $reviewName, string $comment): array;

    public function deleteReply(GoogleConnection $connection, string $reviewName): void;

    public function updateNotificationSetting(GoogleConnection $connection, string $accountName, string $pubsubTopic): void;
}
