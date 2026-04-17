{{--
    Manifest-driven screen renderer.

    Each block in $screen['block_tree'] carries:
        - type       e.g. "mustuse-apps-pub/hero"
        - attributes associative array, block-specific
        - children   or innerBlocks, recursive block tree for containers

    Navigation layers:
        top-bar     — platform-conditional title; hamburger opens side-nav
        side-nav    — drawer listing every screen in the manifest
        bottom-nav  — 2–4 tabs declared by the publisher

    The hamburger icon on top-bar is only rendered when we actually have
    a side-nav to show; otherwise it opens an empty drawer the user can't
    close. iOS convention hides the top-bar title by default.
--}}
<div>
    @php
        $isAndroid      = \Native\Mobile\Facades\System::isAndroid();
        $displayTitle   = $isAndroid ? ($title ?: 'App') : '';
        $hasDrawer      = ! empty($drawerScreens);
    @endphp

    <native:top-bar
        title="{{ $displayTitle }}"
        show-navigation-icon="{{ $hasDrawer ? 'true' : 'false' }}"
    >
    </native:top-bar>

    @if ($hasDrawer)
        <native:side-nav :gestures_enabled="true">
            <native:side-nav-header
                title="{{ $title ?: 'App' }}"
                subtitle="{{ $manifest['branding']['tagline'] ?? '' }}"
                :show-close-button="true"
                pinned
            />
            @foreach ($drawerScreens as $entry)
                @php
                    $entryPath = rtrim((string) ($entry['path'] ?? '/'), '/') ?: '/';
                    $active    = $entryPath === rtrim($path, '/');
                @endphp
                <native:side-nav-item
                    id="{{ $entry['id'] ?? $entryPath }}"
                    icon="{{ $entry['icon'] ?? 'document' }}"
                    label="{{ $entry['title'] ?? $entry['label'] ?? '' }}"
                    url="{{ $entry['path'] ?? '/' }}"
                    :active="$active"
                />
            @endforeach
        </native:side-nav>
    @endif

    @if ($screen)
        <main class="mua-screen" data-screen-id="{{ $screen['id'] ?? '' }}">
            @foreach (($screen['block_tree'] ?? []) as $block)
                @include('livewire.partials.dispatch-block', ['block' => $block])
            @endforeach
        </main>
    @else
        <main class="mua-screen mua-screen--empty">
            <p class="mua-screen__message">{{ $message }}</p>
        </main>
    @endif

    @if (!empty($navTabs))
        <native:bottom-nav label-visibility="labeled">
            @foreach ($navTabs as $tab)
                <native:bottom-nav-item
                    id="{{ $tab['id'] ?? $tab['path'] ?? $loop->index }}"
                    icon="{{ $tab['icon'] ?? 'home' }}"
                    label="{{ $tab['title'] ?? $tab['label'] ?? '' }}"
                    url="{{ $tab['path'] ?? '/' }}"
                    :active="rtrim(($tab['path'] ?? '/'), '/') === rtrim($path, '/')"
                />
            @endforeach
        </native:bottom-nav>
    @endif
</div>
