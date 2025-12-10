<?php
namespace App\Console\Commands;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class ReportingSchedule extends Command
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

    private $gptToken;
    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Skip execution on weekends (Saturday = 6, Sunday = 0)
            $dayOfWeek = Carbon::now('Asia/Singapore')->dayOfWeek;
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                $this->info('Skipping execution - Weekend detected (Saturday/Sunday)');
                return 0;
            }
            $githubToken = config('services.github.token');
            $githubRepo = config('services.github.repo');
            $discordWebhook = config('services.discord.webhook');
            $userMapping = config('services.discord.user_mapping');
            $this->gptToken = config('services.openai.token');
            // Parse user mapping if it's a JSON string
            if (is_string($userMapping)) {
                $userMapping = json_decode($userMapping, true);
            }
            if (!$githubToken || !$githubRepo || !$discordWebhook) {
                $this->error('Missing required configuration. Please provide GitHub token, repo, and Discord webhook.');
                return 1;
            }

            if (!$this->gptToken) {
                $this->warn('OpenAI token not configured. AI summaries will be disabled.');
            }
            if (!$userMapping || !is_array($userMapping)) {
                $this->error('User mapping is required. Please provide GitHub username to Discord ID mapping.');
                return 1;
            }
            $this->info("Fetching today's commits from all branches in {$githubRepo}...");
            $commits = $this->getTodayGitHubCommitsFromAllBranches($githubToken, $githubRepo);
            if (empty($commits)) {
                $this->info('No commits found for today.');
                // Send a "no commits" report
                $this->sendNoCommitsReport($discordWebhook, $githubRepo);
                return 0;
            }
            $this->info("Found " . count($commits) . " commits today. Sending to Discord...");
            $this->sendCommitsToDiscord($commits, $discordWebhook, $userMapping, $githubRepo);
            $this->info('Report sent successfully!');
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            Log::error('GitHub reporting failed', ['error' => $e->getMessage()]);
            return 1;
        }
        return 0;
    }
    /**
     * Fetch today's commits from all branches in GitHub repository
     */
    private function getTodayGitHubCommitsFromAllBranches(string $token, string $repo): array
    {
        $todayStart = Carbon::now('Asia/Singapore')->startOfDay()->utc()->toISOString();
        $todayEnd = Carbon::now('Asia/Singapore')->endOfDay()->utc()->toISOString();

        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'Laravel-GitHub-Reporter'
        ];

        // First, get all branches
        $this->info('Fetching all branches...');
        $branches = $this->getAllBranches($token, $repo, $headers);
        $this->info('Found ' . count($branches) . ' branches');

        $commitsBysha = []; // Track commits by SHA with their branches

        // Fetch commits from each branch
        foreach ($branches as $branch) {
            $branchName = $branch['name'];
            $this->info("Fetching commits from branch: {$branchName}");

            try {
                $response = Http::withHeaders($headers)->get(
                    "https://api.github.com/repos/{$repo}/commits",
                    [
                        'sha' => $branchName,
                        'since' => $todayStart,
                        'until' => $todayEnd,
                        'per_page' => 100
                    ]
                );

                if ($response->successful()) {
                    $branchCommits = $response->json();

                    foreach ($branchCommits as $commit) {
                        $sha = $commit['sha'];

                        // If commit already exists, add this branch to its branch list
                        if (isset($commitsBysha[$sha])) {
                            $commitsBysha[$sha]['branches'][] = $branchName;
                        } else {
                            // First time seeing this commit
                            $commit['branches'] = [$branchName];
                            $commitsBysha[$sha] = $commit;
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->warn("Failed to fetch commits from branch {$branchName}: " . $e->getMessage());
            }
        }

        $allCommits = array_values($commitsBysha);

        // Filter out merge commits
        $filteredCommits = array_filter($allCommits, function ($commit) {
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
     * Send individual user commit message (UPDATED to handle multiple branches)
     */
    private function sendUserCommitMessage(string $authorName, array $authorData, string $webhookUrl, string $repo, string $today): void
    {
        $authorCommits = $authorData['commits'];
        $discordId = $authorData['discord_id'];
        $aiSummary = $authorData['ai_summary'] ?? null;
        $commitCount = count($authorCommits);

        // Get unique branches across all commits
        $allBranches = [];
        foreach ($authorCommits as $commit) {
            $branches = $commit['branches'] ?? ['unknown'];
            $allBranches = array_merge($allBranches, $branches);
        }
        $allBranches = array_unique($allBranches);

        $authorHeader = $discordId ? "<@{$discordId}>" : $authorName;
        $embed = [
            'title' => "👤 {$authorName}'s Commits",
            'description' => "{$commitCount} commit" . ($commitCount > 1 ? 's' : '') . " on {$today}\n📌 Branches: " . implode(', ', $allBranches),
            'color' => 0x00ff00,
            'timestamp' => Carbon::now()->toISOString(),
            'fields' => []
        ];

        // Add AI summary if available
        if ($aiSummary) {
            $embed['fields'][] = [
                'name' => '🤖 AI Summary',
                'value' => $aiSummary,
                'inline' => false
            ];
        }

        // Add commits list
        $commitsList = '';
        foreach (array_slice($authorCommits, 0, 10) as $commit) {
            $message = $this->truncateMessage($commit['commit']['message'] ?? 'No message');
            $sha = substr($commit['sha'], 0, 7);
            $date = Carbon::parse($commit['commit']['author']['date'])->setTimezone('Asia/Singapore')->format('H:i');
            $commitUrl = $commit['html_url'];

            // Show all branches this commit is on
            $branches = $commit['branches'] ?? ['unknown'];
            $branchDisplay = implode(', ', $branches);

            $commitsList .= "**[{$sha}]({$commitUrl})** `{$branchDisplay}` - {$date}\n";
            $commitsList .= "└ {$message}\n\n";
        }

        if ($commitCount > 10) {
            $commitsList .= "*... and " . ($commitCount - 10) . " more commits*\n";
        }

        $embed['fields'][] = [
            'name' => '📝 Commits',
            'value' => $commitsList,
            'inline' => false
        ];

        // Add warning if user is not mapped
        if (!$discordId) {
            $embed['fields'][] = [
                'name' => '⚠️ Note',
                'value' => "This contributor doesn't have Discord mapping configured.",
                'inline' => false
            ];
            $embed['color'] = 0xffa500;
        }

        $payload = [
            'content' => $authorHeader . "'s commits:",
            'embeds' => [$embed]
        ];

        $response = Http::post($webhookUrl, $payload);

        if (!$response->successful()) {
            Log::warning("Discord webhook error for user {$authorName}: " . $response->status());
        }
    }

    /**
     * Get all branches from the repository
     */
    private function getAllBranches(string $token, string $repo, array $headers): array
    {
        $allBranches = [];
        $page = 1;
        $perPage = 100;

        do {
            $response = Http::withHeaders($headers)->get(
                "https://api.github.com/repos/{$repo}/branches",
                [
                    'per_page' => $perPage,
                    'page' => $page
                ]
            );

            if (!$response->successful()) {
                throw new \Exception("GitHub API error while fetching branches: " . $response->status() . " - " . $response->body());
            }

            $branches = $response->json();
            $allBranches = array_merge($allBranches, $branches);

            $page++;
        } while (count($branches) === $perPage);

        return $allBranches;
    }

    /**
     * Generate AI summary of commits using GPT
     */
    private function generateAISummary(array $commits, string $authorName): ?string
    {
        if (!$this->gptToken) {
            return null;
        }

        try {
            // Prepare commit data for summarization
            $commitData = array_map(function($commit) {
                return [
                    'message' => $commit['commit']['message'] ?? 'No message',
                    'branch' => $commit['branch'] ?? 'unknown',
                    'time' => Carbon::parse($commit['commit']['author']['date'])->setTimezone('Asia/Singapore')->format('H:i')
                ];
            }, $commits);

            $prompt = "Summarize what {$authorName} worked on today based on these commits. Be concise (max 2-3 sentences) and focus on the main tasks/features. Commits: " . json_encode($commitData);

            $response = Http::timeout(15)->withHeaders([
                'Authorization' => "Bearer {$this->gptToken}",
                'Content-Type' => 'application/json'
            ])->post("https://api.openai.com/v1/chat/completions", [
                "messages" => [
                    [
                        "role" => "system",
                        "content" => "You are a helpful assistant that summarizes developer work based on git commits. Be concise and professional. Focus on what was accomplished, not technical details."
                    ],
                    [
                        "role" => "user",
                        "content" => $prompt
                    ]
                ],
                "model" => "gpt-4o-mini",
                "max_tokens" => 150,
                "temperature" => 0.5
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['choices'][0]['message']['content'] ?? null;
            }

            Log::warning('GPT API request failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;

        } catch (\Exception $e) {
            Log::error('Failed to generate AI summary', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send commits to Discord with separate messages for each user
     */
    private function sendCommitsToDiscord(array $commits, string $webhookUrl, array $userMapping, string $repo): void
    {
        $today = Carbon::now('Asia/Singapore')->format('Y-m-d');
        // Group commits by author
        $commitsByAuthor = [];
        foreach ($commits as $commit) {
            $authorName = $commit['commit']['author']['name'] ?? 'Unknown';
            $authorEmail = $commit['commit']['author']['email'] ?? '';
            // Try to find Discord user by GitHub username or email
            $discordUserId = $this->findDiscordUser($authorName, $authorEmail, $userMapping);
            if (!isset($commitsByAuthor[$authorName])) {
                $commitsByAuthor[$authorName] = [
                    'commits' => [],
                    'discord_id' => $discordUserId,
                    'email' => $authorEmail
                ];
            }
            $commitsByAuthor[$authorName]['commits'][] = $commit;
        }

        // Generate AI summaries for each author
        $this->info('Generating AI summaries...');
        // Generate AI summaries and send messages concurrently

        // Send summary message
        $this->sendSummaryMessage($commitsByAuthor, $webhookUrl, $repo, $today);

        foreach ($commitsByAuthor as $authorName => $authorData) {
            // Generate AI summary
            $summary = $this->generateAISummary($authorData['commits'], $authorName);
            $authorData['ai_summary'] = $summary;
            if ($summary) {
                $this->info("✓ Generated summary for {$authorName}");
            }

            // Send Discord messages sequentially
            $this->sendUserCommitMessage($authorName, $authorData, $webhookUrl, $repo, $today);
        }
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
            'description' => "Summary for {$today} (All Branches)",
            'color' => 0x0099ff,
            'timestamp' => Carbon::now()->toISOString(),
            'footer' => [
                'text' => 'Generated at ' . Carbon::now('Asia/Singapore')->format('Y-m-d H:i:s') . ' (UTC+8) • AI-Powered'
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
     * Send no commits report
     */
    private function sendNoCommitsReport(string $webhookUrl, string $repo): void
    {
        $today = Carbon::now('Asia/Singapore')->format('Y-m-d');
        $embed = [
            'title' => "📊 Daily Commit Report - {$repo}",
            'description' => "No commits found for {$today}",
            'color' => 0xffa500,
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
        $response = Http::post($webhookUrl, $payload);
        if (!$response->successful()) {
            throw new \Exception("Discord webhook error: " . $response->status() . " - " . $response->body());
        }
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
