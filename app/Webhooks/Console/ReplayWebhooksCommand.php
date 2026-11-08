<?php

declare(strict_types=1);

namespace App\Webhooks\Console;

use App\Webhooks\Enums\WebhookEventStatusEnum;
use App\Webhooks\Jobs\ProcessWebhookEventJob;
use App\Webhooks\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ReplayWebhooksCommand extends Command
{
    protected $signature = 'webhooks:replay {event? : UUID of a dead webhook event} {--dead : Replay every dead event}';

    protected $description = 'Put dead webhook events back on the queue';

    public function handle(): int
    {
        $uuid = $this->argument('event');
        $query = WebhookEvent::query()->where('status', WebhookEventStatusEnum::Dead->value);

        if (is_string($uuid)) {
            if (! Str::isUuid($uuid)) {
                $this->error('That is not a valid event uuid.');

                return self::FAILURE;
            }

            $query->where('uuid', $uuid);
        } elseif (! $this->option('dead')) {
            $this->error('Pass an event uuid or --dead.');

            return self::FAILURE;
        }

        $events = $query->get();

        foreach ($events as $event) {
            $event->update([
                'status' => WebhookEventStatusEnum::Received,
                'attempts' => 0,
                'note' => null,
                'processed_at' => null,
            ]);

            ProcessWebhookEventJob::dispatch($event->id);
        }

        $this->info("Re-queued {$events->count()} event(s).");

        return $events->isEmpty() ? self::FAILURE : self::SUCCESS;
    }
}
