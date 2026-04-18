<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Persistence;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Mobile\Events\PushNotifications\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;

/**
 * Push-notification enrollment prompt block.
 *
 * Three states:
 *   idle      — user hasn't enrolled; shows prompt + enable button.
 *   pending   — enrollment in flight (OS permission prompt → token callback).
 *   enrolled  — we received a token and synced it to the pub.
 *
 * The block is intentionally quiet — no hard "subscribe now" interstitial.
 * Publishers drop it where they want opt-in to happen, typically on a
 * settings or welcome screen.
 */
class PushEnroll extends Component
{
    public string $state       = 'idle'; // idle | pending | enrolled | declined
    public string $title       = 'Stay in the loop';
    public string $description = 'Enable notifications to get alerts for breaking stories.';
    public string $enableLabel = 'Enable notifications';

    public function mount(string $title = 'Stay in the loop', string $description = 'Enable notifications to get alerts for breaking stories.', string $enableLabel = 'Enable notifications'): void
    {
        $this->title       = $title;
        $this->description = $description;
        $this->enableLabel = $enableLabel;
    }

    public function enroll(): void
    {
        if ($this->state !== 'idle') {
            return;
        }
        $this->state = 'pending';
        // NativePHP bridges into OS push registration; token arrives via
        // the #[OnNative(TokenGenerated::class)] handler below.
        PushNotifications::enroll();
    }

    #[OnNative(TokenGenerated::class)]
    public function onTokenGenerated(string $token, string $platform = 'unknown'): void
    {
        if ($token === '') {
            $this->state = 'declined';
            return;
        }
        app(Persistence::class)->enrollPush($token, $platform);
        $this->state = 'enrolled';
    }

    public function render()
    {
        return view('livewire.push-enroll');
    }
}
