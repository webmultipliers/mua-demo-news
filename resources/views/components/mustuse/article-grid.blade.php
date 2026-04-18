@props(['block' => [], 'children' => [], 'gates' => []])

@php
    // BlockAttributeNormalizer::expandArticleList bakes `items` at build time.
    $items         = is_array($block['items'] ?? null) ? $block['items'] : [];
    $showThumbnail = $block['showThumbnail'] ?? true;
    $showExcerpt   = $block['showExcerpt']   ?? false;
    $columns       = $block['columns']       ?? 'auto';
@endphp

@if (!empty($items))
    <div class="mua-article-grid" data-columns="{{ $columns }}"
         @if($columns !== 'auto') style="grid-template-columns: repeat({{ (int) $columns }}, 1fr);" @endif>
        @foreach ($items as $article)
            @continue(! is_array($article))
            <article class="mua-article-grid__item">
                <a href="{{ $article['url'] ?? '#' }}" wire:navigate>
                    @if ($showThumbnail && is_array($article['thumbnail'] ?? null) && !empty($article['thumbnail']['url']))
                        <img class="mua-article-grid__thumb"
                             src="{{ $article['thumbnail']['url'] }}"
                             alt="{{ $article['thumbnail']['alt'] ?? '' }}"
                             loading="lazy" />
                    @endif
                    <h4 class="mua-article-grid__title">{{ $article['title'] ?? '' }}</h4>
                    @if ($showExcerpt && !empty($article['excerpt']))
                        <p class="mua-article-grid__excerpt">{{ $article['excerpt'] }}</p>
                    @endif
                </a>
            </article>
        @endforeach
    </div>
@endif
