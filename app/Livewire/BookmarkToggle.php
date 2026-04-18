<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Persistence;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Toggle button that bookmarks the current post. Lives inside a detail
 * screen and reads `context.post.id` from the parent. Persistence is
 * local-first (SecureStorage) with an async pub sync via
 * Persistence::addBookmark() / removeBookmark().
 *
 * Fires `bookmarks-changed` after every mutation so sibling blocks
 * (e.g. a bookmark counter on the same screen) can re-render.
 */
class BookmarkToggle extends Component
{
    public int $postId = 0;
    public string $postType = 'post';
    public bool $bookmarked = false;
    public string $addLabel = 'Bookmark';
    public string $removeLabel = 'Bookmarked';

    public function mount(int $postId = 0, string $postType = 'post', string $addLabel = 'Bookmark', string $removeLabel = 'Bookmarked'): void
    {
        $this->postId      = $postId;
        $this->postType    = $postType;
        $this->addLabel    = $addLabel;
        $this->removeLabel = $removeLabel;
        $this->bookmarked  = $postId > 0 && app(Persistence::class)->isBookmarked($postId);
    }

    #[On('bookmarks-changed')]
    public function refreshState(): void
    {
        if ($this->postId > 0) {
            $this->bookmarked = app(Persistence::class)->isBookmarked($this->postId);
        }
    }

    public function toggle(): void
    {
        if ($this->postId <= 0) {
            return;
        }
        $persistence = app(Persistence::class);
        if ($this->bookmarked) {
            $persistence->removeBookmark($this->postId);
            $this->bookmarked = false;
        } else {
            $persistence->addBookmark($this->postId, $this->postType ?: 'post');
            $this->bookmarked = true;
        }
        $this->dispatch('bookmarks-changed');
    }

    public function render()
    {
        return view('livewire.bookmark-toggle');
    }
}
