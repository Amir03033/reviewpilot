<?php

namespace App\Services\Google;

use App\Models\GoogleConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class HttpGbpClient implements GbpClient
{
    public function __construct(private GoogleTokenManager $tokens) {}

    public function listAccounts(GoogleConnection $connection): array
    {
        return $this->paginate($connection, $this->endpoint('accounts').'/accounts', 'accounts', ['pageSize' => 20]);
    }

    public function listLocations(GoogleConnection $connection, string $accountName): array
    {
        return $this->paginate($connection, $this->endpoint('business_information')."/{$accountName}/locations", 'locations', [
            'pageSize' => 100,
            'readMask' => 'name,title,storefrontAddress,metadata,regularHours,websiteUri,categories,phoneNumbers,openInfo',
        ]);
    }

    public function listReviews(GoogleConnection $connection, string $v4Parent, ?string $pageToken = null, int $pageSize = 50): array
    {
        $json = $this->request($connection, 'get', $this->endpoint('reviews')."/{$v4Parent}/reviews", array_filter([
            'pageSize' => min($pageSize, 50),
            'pageToken' => $pageToken,
            'orderBy' => 'updateTime desc',
        ]));

        return [
            'reviews' => array_map(fn (array $r) => GbpReview::fromApi($r), $json['reviews'] ?? []),
            'nextPageToken' => $json['nextPageToken'] ?? null,
            'averageRating' => isset($json['averageRating']) ? (float) $json['averageRating'] : null,
            'totalReviewCount' => isset($json['totalReviewCount']) ? (int) $json['totalReviewCount'] : null,
        ];
    }

    public function getReview(GoogleConnection $connection, string $reviewName): GbpReview
    {
        return GbpReview::fromApi($this->request($connection, 'get', $this->endpoint('reviews')."/{$reviewName}"));
    }

    public function updateReply(GoogleConnection $connection, string $reviewName, string $comment): array
    {
        return $this->request($connection, 'put', $this->endpoint('reviews')."/{$reviewName}/reply", ['comment' => $comment]);
    }

    public function deleteReply(GoogleConnection $connection, string $reviewName): void
    {
        $this->request($connection, 'delete', $this->endpoint('reviews')."/{$reviewName}/reply");
    }

    public function updateNotificationSetting(GoogleConnection $connection, string $accountName, string $pubsubTopic): void
    {
        $name = "{$accountName}/notificationSetting";

        $this->request($connection, 'patch', $this->endpoint('notifications')."/{$name}?updateMask=pubsubTopic,notificationTypes", [
            'name' => $name,
            'pubsubTopic' => $pubsubTopic,
            'notificationTypes' => ['NEW_REVIEW', 'UPDATED_REVIEW'],
        ]);
    }

    private function endpoint(string $key): string
    {
        return rtrim((string) config("services.google.endpoints.{$key}"), '/');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paginate(GoogleConnection $connection, string $url, string $key, array $query): array
    {
        $items = [];
        $pageToken = null;

        do {
            $json = $this->request($connection, 'get', $url, array_filter([...$query, 'pageToken' => $pageToken]));
            array_push($items, ...($json[$key] ?? []));
            $pageToken = $json['nextPageToken'] ?? null;
        } while ($pageToken);

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(GoogleConnection $connection, string $method, string $url, array $data = [], bool $retried = false): array
    {
        $this->throttle();

        try {
            $pending = Http::withToken($this->tokens->accessToken($connection))->acceptJson()->timeout(30);
            /** @var Response $response */
            $response = $method === 'get' ? $pending->get($url, $data) : $pending->{$method}($url, $data);
        } catch (ConnectionException $e) {
            throw new GoogleApiException('Network error talking to Google: '.$e->getMessage());
        }

        if ($response->status() === 401 && ! $retried) {
            $this->tokens->forceRefresh($connection);

            return $this->request($connection, $method, $url, $data, true);
        }

        if ($response->failed()) {
            throw new GoogleApiException(
                (string) ($response->json('error.message') ?? "Google API error {$response->status()}"),
                $response->status(),
                $response->json(),
            );
        }

        return $response->json() ?? [];
    }

    /** Project-wide quota guard (Google default is 300 QPM per project; we stay below it). */
    private function throttle(): void
    {
        $limit = (int) config('reviewpilot.sync.gbp_requests_per_minute', 240);

        if (! RateLimiter::attempt('gbp-api-project', $limit, fn () => true, 60)) {
            throw new GoogleRateLimited(RateLimiter::availableIn('gbp-api-project'));
        }
    }
}
