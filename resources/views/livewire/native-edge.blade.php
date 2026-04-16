{{--
    Manifest-driven screen renderer.

    Each block in $screen['block_tree'] carries:
        - type       e.g. "mustuse-apps-pub/hero"
        - attributes associative array, block-specific
        - children   or innerBlocks, recursive block tree for containers

    We strip the "mustuse-apps-pub/" prefix and dispatch to a Blade
    component of the same slug (hero → <x-hero>). Unknown block types
    are silently skipped so a pub-side addition doesn't break older
    shells — missing components just render nothing.
--}}
<div>
    <native:top-bar :title="$title ?: 'App'" />

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
