@props(['block' => [], 'children' => [], 'gates' => []])

@php
    // `items` is baked into the manifest at build time by
    // BlockAttributeNormalizer::expandArticleList() from the postType/count/
    // category attributes, so the shell renders content immediately without
    // a round-trip on cold start. A later `/content` refresh can overwrite
    // this array via Livewire without changing this template.
    $items = is_array($block['items'] ?? null) ? $block['items'] : [];
    $showThumbnail = $block['showThumbnail'] ?? true;
    $showExcerpt   = $block['showExcerpt']   ?? true;
@endphp

<section class="mua-article-list">
    @if (empty($items))
        <p class="mua-article-list__empty">No articles to show yet.</p>
    @else
        <ul class="mua-article-list__items">
            @foreach ($items as $article)
                @continue(! is_array($article))
                <li class="mua-article-list__item">
                    <a class="mua-article-list__link" href="{{ $article['url'] ?? '#' }}" wire:navigate>
                        @if ($showThumbnail && is_array($article['thumbnail'] ?? null) && !empty($article['thumbnail']['url']))
                            <img class="mua-article-list__thumb"
                                 src="{{ $article['thumbnail']['url'] }}"
                                 alt="{{ $article['thumbnail']['alt'] ?? '' }}"
                                 loading="lazy" />
                        @endif
                        <div class="mua-article-list__body">
                            <h3 class="mua-article-list__item-title">{{ $article['title'] ?? '' }}</h3>
                            <div class="mua-article-list__meta">
                                @if (!empty($article['author']['name']))
                                    <span class="mua-article-list__author">{{ $article['author']['name'] }}</span>
                                @endif
                                @if (!empty($article['date_human']))
                                    <time class="mua-article-list__date">{{ $article['date_human'] }}</time>
                                @endif
                            </div>
                            @if ($showExcerpt && !empty($article['excerpt']))
                                <p class="mua-article-list__excerpt">{{ $article['excerpt'] }}</p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
