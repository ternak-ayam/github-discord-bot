<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use Illuminate\Support\Facades\Http;
use App\Models\UserCheckin;
use Carbon\Carbon;

class DiscordBotListener extends Command
{
    protected $signature = 'discord:listen';
    protected $description = 'Start Discord bot to listen for messages';

    private $botToken;
    private $gatewayUrl;
    private $sessionId;
    private $lastSequence;
    private $heartbeatInterval;
    private $loop;

    public function handle()
    {
        $this->botToken = config('services.discord.bot_token');

        if (!$this->botToken) {
            $this->error('Discord bot token not configured!');
            return 1;
        }

        $this->info('Starting Discord bot...');

        try {
            // Create event loop
            $this->loop = \React\EventLoop\Factory::create();

            // Get gateway URL
            $this->getGateway();

            // Connect to Discord Gateway
            $this->connectToGateway();

            // Start the event loop
            $this->loop->run();
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }

    private function getGateway()
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}"
        ])->get('https://discord.com/api/v10/gateway/bot');

        if (!$response->successful()) {
            throw new \Exception('Failed to get gateway URL');
        }

        $data = $response->json();
        $this->gatewayUrl = $data['url'] . '?v=10&encoding=json';
        $this->info('Gateway URL: ' . $this->gatewayUrl);
    }

    private function connectToGateway()
    {
        $connector = new Connector($this->loop);

        $connector($this->gatewayUrl)
            ->then(function (WebSocket $conn) {
                $this->info('Connected to Discord Gateway!');

                // Handle incoming messages
                $conn->on('message', function ($msg) use ($conn) {
                    $this->handleGatewayMessage(json_decode($msg->getPayload(), true), $conn);
                });

                $conn->on('close', function ($code = null, $reason = null) {
                    $this->error("Connection closed ({$code} - {$reason})");
                    // Optionally implement reconnection logic here
                });

                $conn->on('error', function (\Exception $e) {
                    $this->error('WebSocket error: ' . $e->getMessage());
                });
            }, function (\Exception $e) {
                $this->error('Could not connect: ' . $e->getMessage());
            });
    }

    private function handleGatewayMessage($data, $conn)
    {
        $op = $data['op'];
        $this->lastSequence = $data['s'] ?? $this->lastSequence;

        switch ($op) {
            case 10: // Hello
                $this->heartbeatInterval = $data['d']['heartbeat_interval'];
                $this->info('Received Hello. Heartbeat interval: ' . $this->heartbeatInterval . 'ms');
                $this->identify($conn);
                $this->startHeartbeat($conn);
                break;

            case 0: // Dispatch
                $this->handleEvent($data);
                break;

            case 11: // Heartbeat ACK
                $this->info('Heartbeat acknowledged');
                break;

            case 1: // Heartbeat request
                $this->sendHeartbeat($conn);
                break;

            case 7: // Reconnect
                $this->info('Received reconnect request');
                // Implement reconnection logic if needed
                break;

            case 9: // Invalid Session
                $this->error('Invalid session');
                break;
        }
    }

    private function identify($conn)
    {
        $identify = [
            'op' => 2,
            'd' => [
                'token' => $this->botToken,
                'intents' => 513, // GUILDS + GUILD_MESSAGES
                'properties' => [
                    'os' => 'linux',
                    'browser' => 'laravel-bot',
                    'device' => 'laravel-bot'
                ]
            ]
        ];

        $conn->send(json_encode($identify));
        $this->info('Sent identify payload');
    }

    private function startHeartbeat($conn)
    {
        // Send initial heartbeat after a random delay (as recommended by Discord)
        $initialDelay = mt_rand(0, $this->heartbeatInterval) / 1000;

        $this->loop->addTimer($initialDelay, function () use ($conn) {
            $this->sendHeartbeat($conn);
        });

        // Set up periodic heartbeat
        $this->loop->addPeriodicTimer($this->heartbeatInterval / 1000, function () use ($conn) {
            $this->sendHeartbeat($conn);
        });
    }

    private function sendHeartbeat($conn)
    {
        $heartbeat = [
            'op' => 1,
            'd' => $this->lastSequence
        ];
        $conn->send(json_encode($heartbeat));
        $this->info('Sent heartbeat');
    }

    private function handleEvent($data)
    {
        $eventType = $data['t'];
        $eventData = $data['d'];

        switch ($eventType) {
            case 'READY':
                $this->sessionId = $eventData['session_id'];
                $this->info('Bot ready! Session ID: ' . $this->sessionId);
                $this->info('Connected to ' . count($eventData['guilds']) . ' guilds');
                break;

            case 'MESSAGE_CREATE':
                $this->handleMessage($eventData);
                break;

            case 'INTERACTION_CREATE':
                $this->handleInteraction($eventData);
                break;

            case 'GUILD_CREATE':
                $this->info('Guild available: ' . $eventData['name']);
                break;
        }
    }

    private function handleInteraction($interactionData)
    {
        $interactionId = $interactionData['id'];
        $interactionToken = $interactionData['token'];
        $userId = $interactionData['member']['user']['id'];
        $name = $interactionData['member']['user']['name'] ?? $interactionData['member']['user']['global_name'] ?? $interactionData['member']['user']['username'];

        // Check if this is a slash command
        if ($interactionData['type'] === 2) { // APPLICATION_COMMAND
            $commandName = $interactionData['data']['name'];

            $this->info("Slash command from {$name}: /{$commandName}");

            $response = $this->handleSlashCommand($commandName, $userId, $name);

            if ($response) {
                $this->sendInteractionResponse($interactionId, $interactionToken, $response);
            }
        }
    }

    private function handleSlashCommand($commandName, $userId, $username)
    {
        switch ($commandName) {
            case 'checkin':
                return $this->handleCheckin($userId, $username);

            case 'checkout':
                return $this->handleCheckout($userId, $username);

            case 'status':
                return $this->handleStatus($userId, $username);

            case 'ping':
                return "🏓 Pong! Bot is working perfectly!";

            default:
                return "❌ Unknown command: /{$commandName}";
        }
    }

    private function sendInteractionResponse($interactionId, $interactionToken, $payload)
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}",
            'Content-Type' => 'application/json'
        ])->post("https://discord.com/api/v10/interactions/{$interactionId}/{$interactionToken}/callback", [
            'type' => 4, // CHANNEL_MESSAGE_WITH_SOURCE
            'data' => $payload
        ]);

        if ($response->successful()) {
            $this->info('Interaction response sent successfully');
        } else {
            $this->error('Failed to send interaction response: ' . $response->body());
        }
    }

    private function handleMessage($messageData)
    {
        // Ignore bot messages
        if ($messageData['author']['bot'] ?? false) {
            return;
        }
        $this->info(json_decode($messageData));
        $content = trim($messageData['content']);
        $userId = $messageData['author']['id'];
        $username = $messageData['author']['username'];
        $channelId = $messageData['channel_id'];

        $this->info("Message from {$username}: {$content}");

        // Handle commands
        if (str_starts_with($content, '!')) {
            $response = $this->handleCommand($content, $userId, $username);

            if ($response) {
                $this->sendMessage($channelId, $response);
            }
        }
    }

    private function handleCommand($content, $userId, $username)
    {
        $command = strtolower(trim($content));

        switch ($command) {
            case '!checkin':
                return $this->handleCheckin($userId, $username);

            case '!checkout':
                return $this->handleCheckout($userId, $username);

            case '!status':
                return $this->handleStatus($userId, $username);

            case '!ping':
                return $this->ping($userId, $username);

            default:
                return null; // Don't respond to unknown commands
        }
    }

    private function handleCheckin($userId, $username)
    {
        // Check if user already checked in today
        $existingCheckin = UserCheckin::where('discord_user_id', $userId)
            ->whereDate('checkin_at', Carbon::today())
            ->whereNull('checkout_at')
            ->first();

        if ($existingCheckin) {
            $checkinTime = $existingCheckin->checkin_at->format('H:i');
            $content = "⚠️ {$username}, you're already checked in today at {$checkinTime}!";
        } else {
            UserCheckin::create([
                'discord_user_id' => $userId,
                'username' => $username,
                'checkin_at' => Carbon::now(),
            ]);

            $currentTime = Carbon::now()->timezone('Asia/Singapore')->format('H:i');
            $content = "✅ {$username} checked in successfully at {$currentTime}! Have a productive day! 🚀";
        }

        return $content;
    }

    private function handleCheckout($userId, $username)
    {
        // Find today's check-in record
        $checkin = UserCheckin::where('discord_user_id', $userId)
            ->whereDate('checkin_at', Carbon::today())
            ->whereNull('checkout_at')
            ->first();

        // Generate work report (replace with your method)
        $workReport = $this->generateWorkReport($userId, $checkin);

        if (!$checkin) {
            $content = "❌ {$username}, you haven't checked in today or already checked out!";

            if (! count($workReport)) {
                $content .= "\n\n You have not commit anything yet.🥀";
            }

            return array_merge([
                'content' => $content
            ], $workReport);
        }

        // Update checkout time
        $checkin->update([
            'checkout_at' => Carbon::now()
        ]);

        $checkoutTime = Carbon::now()->timezone('Asia/Singapore')->format('H:i');
        $workedHours = $checkin->checkin_at->diffInHours(Carbon::now());
        $workedMinutes = $checkin->checkin_at->diffInMinutes(Carbon::now()) % 60;

        if ($workedHours < 1) {
            $workedHours = 0;
        }

        $content = "✅ {$username} checked out at {$checkoutTime}!\n" .
            "⏱️ Total time worked: {$workedHours}hours {$workedMinutes}minutes\n\n" .
            "Great work today! 🎉";

        return array_merge([
            'content' => $content
        ], $workReport);
    }

    private function ping()
    {
        return "Pong";
    }

    private function handleStatus($userId, $username)
    {
        $todayCheckin = UserCheckin::where('discord_user_id', $userId)
            ->whereDate('checkin_at', Carbon::today())
            ->first();

        if (!$todayCheckin) {
            $content = "📊 {$username}, you haven't checked in today yet. Use `checkin` to start your day!";
        }

        if ($todayCheckin->checkout_at) {
            $workedTime = $todayCheckin->formatted_worked_time;
            $content = "📊 **{$username}'s Status:** Already checked out\n" .
                "⏱️ Total time worked: {$workedTime}";
        } else {
            $currentTime = Carbon::now();
            $workedHours = $todayCheckin->checkin_at->diffInHours($currentTime);
            $workedMinutes = $todayCheckin->checkin_at->diffInMinutes($currentTime) % 60;

            $content = "📊 **{$username}'s Status:** Currently checked in\n" .
                "🕐 Checked in at: " . $todayCheckin->checkin_at->format('H:i') . "\n" .
                "⏱️ Time elapsed: {$workedHours}h {$workedMinutes}m";
        }

        return [
            "content" => $content
        ];
    }

    private function generateWorkReport($userId, $checkin = null)
    {
        $payload = (new ReportingSchedule())->handle($userId);

        return $payload;
    }

    private function sendMessage($channelId, $content)
    {
        $response = Http::withHeaders([
            'Authorization' => "Bot {$this->botToken}",
            'Content-Type' => 'application/json'
        ])->post("https://discord.com/api/v10/channels/{$channelId}/messages", [
            'content' => $content
        ]);

        if ($response->successful()) {
            $this->info('Message sent successfully');
        } else {
            $this->error('Failed to send message: ' . $response->body());
        }
    }
}
