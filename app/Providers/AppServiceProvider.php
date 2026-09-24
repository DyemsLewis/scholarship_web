<?php

namespace App\Providers;

use App\Models\PortalNotification;
use App\Observers\PortalNotificationObserver;
use App\Support\EmailAddressPolicy;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        PortalNotification::observe(PortalNotificationObserver::class);

        Event::listen(MessageSending::class, function (MessageSending $event): bool {
            if (app()->runningUnitTests() || ! config('mail.block_reserved_domains')) {
                return true;
            }

            $recipients = [
                ...$event->message->getTo(),
                ...$event->message->getCc(),
                ...$event->message->getBcc(),
            ];

            return collect($recipients)->every(
                fn ($recipient): bool => EmailAddressPolicy::canReceiveExternalMail($recipient->getAddress())
            );
        });

        if ($hotFile = env('VITE_HOT_FILE')) {
            Vite::useHotFile(base_path($hotFile));
        }
    }
}
