<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReportingSchedule
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reporting-schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle($userId = null)
    {
        try {
            // Skip execution on weekends (Saturday = 6, Sunday = 0)
            $dayOfWeek = Carbon::now('Asia/Singapore')->dayOfWeek;
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                return 0;
            }

            $githubToken = config('services.github.token');
            $githubRepo = config('services.github.repo');
            $discordWebhook = config('services.discord.webhook');
            $userMapping = config('services.discord.user_mapping');
            $githubUsername = '';

            if ($userId) {
                foreach ($userMapping as $githubUsernamez => $discordId) {
                    if ($discordId === $userId) {
                        $githubUsername = $githubUsernamez;
                    }
                }
            }

            $commits = $this->getTodayGitHubCommits($githubToken, $githubRepo);

            if (empty($commits)) {
                // Send a "no commits" report
                return $this->getNoCommitsReport($discordWebhook, $githubRepo);
            }

            return $this->sendCommitsToDiscord($commits, $discordWebhook, $githubUsername, $githubRepo);
        } catch (\Exception $e) {
            Log::error('GitHub reporting failed', ['error' => $e->getMessage()]);
            return 1;
        }

        return 0;
    }

    /**
     * Fetch today's commits from GitHub API (UTC+8 timezone)
     */
    public function getTodayGitHubCommits(string $token, string $repo): array
    {
        // Get today's date in UTC+8 timezone
        $todayStart = Carbon::now('Asia/Singapore')->startOfDay()->utc()->toISOString();
        $todayEnd = Carbon::now('Asia/Singapore')->endOfDay()->utc()->toISOString();

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'Laravel-GitHub-Reporter'
        ])->get("https://api.github.com/repos/{$repo}/commits", [
            'since' => $todayStart,
            'until' => $todayEnd,
            'per_page' => 100
        ]);

        if (!$response->successful()) {
            throw new \Exception("GitHub API error: " . $response->status() . " - " . $response->body());
        }

        $commits = $response->json();

        // Filter out merge commits (commits with "merge" in message, case insensitive)
        $filteredCommits = array_filter($commits, function ($commit) {
            $message = strtolower($commit['commit']['message'] ?? '');
            return !str_contains($message, 'merge');
        });

        // Additional filter to ensure commits are actually from today in UTC+8
        $filteredCommits = array_filter($filteredCommits, function ($commit) {
            $commitDate = Carbon::parse($commit['commit']['author']['date'])->setTimezone('Asia/Singapore');
            $today = Carbon::now('Asia/Singapore');
            return $commitDate->isSameDay($today);
        });

        // Sort by date (newest first)
        usort($filteredCommits, function ($a, $b) {
            return strtotime($b['commit']['author']['date']) - strtotime($a['commit']['author']['date']);
        });

        return $filteredCommits;
    }

    /**
     * Send commits to Discord with separate messages for each user
     */
    private function sendCommitsToDiscord(array $commits, string $webhookUrl, string $userGithubName, string $repo): array
    {
        $today = Carbon::now('Asia/Singapore')->format('Y-m-d');

        // Group commits by author
        $commitsByAuthor = [];

        foreach ($commits as $commit) {
            $authorName = $userGithubName;
            if ($authorName === $commit['commit']['author']['name']) {
                $authorEmail = $commit['commit']['author']['email'] ?? '';

                // Try to find Discord user by GitHub username or email
                // $discordUserId = $this->findDiscordUser($authorName, $authorEmail, $userMapping);

                if (!isset($commitsByAuthor[$authorName])) {
                    $commitsByAuthor[$authorName] = [
                        'commits' => [],
                        'email' => $authorEmail
                    ];
                }

                $commitsByAuthor[$authorName]['commits'][] = $commit;
            }
        }

        // First, send a summary message
        // $this->sendSummaryMessage($commitsByAuthor, $webhookUrl, $repo, $today);

        // Then send individual messages for each author
        foreach ($commitsByAuthor as $authorzName => $authorData) {
            return $this->sendUserCommitMessage($authorzName, $authorData, $webhookUrl, $repo, $today);
        }

        return [];
    }

    /**
     * Send summary message
     */
    private function sendSummaryMessage(array $commitsByAuthor, string $webhookUrl, string $repo, string $today): void
    {
        $totalCommits = array_sum(array_map(function ($author) {
            return count($author['commits']);
        }, $commitsByAuthor));

        $totalAuthors = count($commitsByAuthor);

        $embed = [
            'title' => "📊 Daily Commit Report - {$repo}",
            'description' => "Summary for {$today}",
            'color' => 0x0099ff, // Blue color
            'timestamp' => Carbon::now()->toISOString(),
            'footer' => [
                'text' => 'Generated at ' . Carbon::now('Asia/Singapore')->format('Y-m-d H:i:s') . ' (UTC+8)'
            ],
            'fields' => [
                [
                    'name' => '📈 Summary',
                    'value' => "**Total commits:** {$totalCommits}\n**Contributors:** {$totalAuthors}",
                    'inline' => true
                ]
            ]
        ];

        // Add contributor list
        $contributorsList = [];
        foreach ($commitsByAuthor as $authorName => $authorData) {
            $commitCount = count($authorData['commits']);
            $discordId = $authorData['discord_id'];

            $authorDisplay = $discordId ? "<@{$discordId}>" : $authorName;
            $contributorsList[] = "{$authorDisplay} ({$commitCount} commit" . ($commitCount > 1 ? 's' : '') . ")";
        }

        $embed['fields'][] = [
            'name' => '👥 Contributors',
            'value' => implode("\n", $contributorsList),
            'inline' => false
        ];

        $payload = [
            'content' => "📋 **Daily GitHub Activity Report**",
            'embeds' => [$embed]
        ];

        $response = Http::post($webhookUrl, $payload);

        if (!$response->successful()) {
            throw new \Exception("Discord webhook error: " . $response->status() . " - " . $response->body());
        }
    }

    /**
     * Send individual user commit message
     */
    private function sendUserCommitMessage(string $authorName, array $authorData, string $webhookUrl, string $repo, string $today): array
    {
        $authorCommits = $authorData['commits'];
        $commitCount = count($authorCommits);

        $embed = [
            'title' => "👤 {$authorName}'s Commits",
            'description' => "{$commitCount} commit" . ($commitCount > 1 ? 's' : '') . " on {$today}",
            'color' => 0x00ff00, // Green color
            'timestamp' => Carbon::now()->toISOString(),
            'fields' => []
        ];

        // Add commits list
        $commitsList = '';
        foreach (array_slice($authorCommits, 0, 5) as $commit) {
            $message = $this->truncateMessage($commit['commit']['message'] ?? 'No message');
            $sha = substr($commit['sha'], 0, 7);
            $date = Carbon::parse($commit['commit']['author']['date'])->setTimezone('Asia/Singapore')->format('H:i');

            $commitUrl = $commit['html_url'];
            $commitsList .= "**[{$sha}]({$commitUrl})** - {$date}\n";
            $commitsList .= "└ {$message}\n\n";
        }

        $embed['fields'][] = [
            'name' => '📝 Commits',
            'value' => $commitsList,
            'inline' => false
        ];

        $payload = [
            'embeds' => [$embed]
        ];

        Log::info($payload);
        return $payload;

        if (!$response->successful()) {
            throw new \Exception("Discord webhook error: " . $response->status() . " - " . $response->body());
        }
    }

    /**
     * Send no commits report
     */
    private function getNoCommitsReport(string $repo): array
    {
        $today = Carbon::now('Asia/Singapore')->format('Y-m-d');

        $embed = [
            'title' => "📊 Daily Commit Report - {$repo}",
            'description' => "No commits found for {$today}",
            'color' => 0xffa500, // Orange color
            'footer' => [
                'text' => 'Generated at ' . Carbon::now('Asia/Singapore')->format('Y-m-d H:i:s') . ' (UTC+8)'
            ],
            'fields' => [
                [
                    'name' => '😴 No commits today',
                    'value' => "No commits were made today.\nTime to get coding! 💻",
                    'inline' => false
                ]
            ]
        ];

        $payload = [
            'content' => "📋 **Daily GitHub Activity Report**",
            'embeds' => [$embed]
        ];

        return $payload;
    }

    /**
     * Find Discord user ID based on GitHub author info
     */
    private function findDiscordUser(string $authorName, string $authorEmail, array $userMapping): ?string
    {
        // Direct name match
        if (isset($userMapping[$authorName])) {
            return $userMapping[$authorName];
        }

        // Email match
        if ($authorEmail && isset($userMapping[$authorEmail])) {
            return $userMapping[$authorEmail];
        }

        // Case-insensitive name match
        foreach ($userMapping as $githubUser => $discordId) {
            if (strcasecmp($githubUser, $authorName) === 0) {
                return $discordId;
            }
        }

        return null;
    }

    /**
     * Truncate commit message for display
     */
    private function truncateMessage(string $message, int $length = 100): string
    {
        $firstLine = explode("\n", $message)[0];
        return strlen($firstLine) > $length ? substr($firstLine, 0, $length) . '...' : $firstLine;
    }
}
