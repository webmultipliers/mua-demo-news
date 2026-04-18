<?php

declare(strict_types=1);

namespace App\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Search input block.
 *
 * Keeps state for the input box between renders and submits to the
 * search-results screen via a plain `wire:navigate` redirect. The search
 * screen itself is whatever screen the pub resolves `/search` to in its
 * DeeplinkResolver (defaults to screen_id=`search-results`).
 *
 * `#[Url]` binds `$query` to the browser URL's `?q=...` so the input
 * seeds correctly on back/forward navigation without a round-trip.
 */
class SearchBox extends Component
{
    public string $placeholder  = 'Search articles…';
    public string $submitLabel  = 'Search';
    public bool   $autoFocus    = false;

    #[Url(as: 'q', keep: false)]
    public string $query = '';

    public function mount(string $placeholder = 'Search articles…', string $submitLabel = 'Search', bool $autoFocus = false, string $seed = ''): void
    {
        $this->placeholder = $placeholder;
        $this->submitLabel = $submitLabel;
        $this->autoFocus   = $autoFocus;
        if ($this->query === '' && $seed !== '') {
            $this->query = $seed;
        }
    }

    /**
     * Submit handler: normalize whitespace and redirect to /search?q=…
     * via Livewire's client-side navigate so the rest of the shell state
     * (layout, bottom-nav, etc.) doesn't flash out.
     */
    public function submit(): mixed
    {
        $q = trim($this->query);
        if ($q === '') {
            return null;
        }
        return $this->redirectRoute('native-edge', ['any' => 'search', 'q' => $q], navigate: true);
    }

    public function render()
    {
        return view('livewire.search-box');
    }
}
