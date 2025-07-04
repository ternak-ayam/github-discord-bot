<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterDiscordCommands extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'discord:register-commands';

    /**
     * The console command description.
     */
    protected $description = 'Register Discord slash commands';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $botToken = config('services.discord.bot_token');
        $applicationId = config('services.discord.application_id');

        if (!$botToken || !$applicationId) {
            $this->error('Discord bot token or application ID not configured!');
            $this->info('Please set DISCORD_BOT_TOKEN and DISCORD_APPLICATION_ID in your .env file');
            return 1;
        }

        $commands = [
            [
                'name' => 'checkin',
                'description' => 'Check in to start your work day',
                'type' => 1
            ],
            [
                'name' => 'checkout',
                'description' => 'Check out and generate work report',
                'type' => 1
            ],
            [
                'name' => 'status',
                'description' => 'Check your current work status',
                'type' => 1
            ],
            [
                'name' => 'whoami',
                'description' => 'Check who are you',
                'type' => 1
            ],
            [
                'name' => 'ask',
                'description' => 'Ask GPT',
                'type' => 1, // CHAT_INPUT - regular slash command
                'options' => [
                    [
                        'name' => 'mesage',
                        'description' => 'Optional message about what you\'re asking to GPT',
                        'type' => 3, // STRING
                        'required' => true
                    ]
                ]
            ],
        ];

        $this->info('Registering Discord slash commands...');

        $url = "https://discord.com/api/v10/applications/{$applicationId}/commands";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json'
            ])->put($url, $commands);

            if ($response->successful()) {
                $this->info('✅ Discord commands registered successfully!');
                $this->info('Available commands:');
                foreach ($commands as $command) {
                    $this->line("  /{$command['name']} - {$command['description']}");
                }
                return 0;
            } else {
                $this->error('❌ Failed to register commands');
                $this->error('Response: ' . $response->body());
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }
}
