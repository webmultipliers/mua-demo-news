<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Shell-side application provider.
 *
 * NativePHP v3 Mobile has no Window/MenuBar/BottomNav facades — the top bar
 * and bottom nav are EDGE Blade components that render at the view layer
 * (see native-edge.blade.php). The manifest is loaded per-request by the
 * NativeEdge Livewire component rather than at boot, so this provider stays
 * minimal; downstream publishers can override it if they want to share the
 * manifest into the service container or register custom event listeners.
 */
class NativeAppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }

    public function register(): void
    {
        //
    }
}
