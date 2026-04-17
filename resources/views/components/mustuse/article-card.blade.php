@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $article = $block['article'] ?? null;
@endphp

@if (is_array($article))
    <article class="mua-article-card">
        @if (is_array($article['thumbnail'] ?? null) && !empty($article['thumbnail']['url']))
            <a class="mua-article-card__image-link" href="{{ $article['url'] ?? '#' }}" wire:navigate>
                <img class="mua-article-card__image"
                     src="{{ $article['thumbnail']['url'] }}"
                     alt="{{ $article['thumbnail']['alt'] ?? '' }}"
                     loading="lazy" />
            </a>
        @endif

        <div class="mua-article-card__body">
            @if (!empty($article['categories']))
                <div class="mua-article-card__categories">
                    @foreach ($article['categories'] as $cat)
                        <a class="mua-pill" href="/category/{{ $cat['slug'] }}" wire:navigate>{{ $cat['name'] }}</a>
                    @endforeach
                </div>
            @endif

            <h2 class="mua-article-card__title">
                <a href="{{ $article['url'] ?? '#' }}" wire:navigate>{{ $article['title'] ?? '' }}</a>
            </h2>

            @if (!empty($article['excerpt']))
                <p class="mua-article-card__excerpt">{{ $article['excerpt'] }}</p>
            @endif

            <div class="mua-article-card__meta">
                @if (!empty($article['author']['name']))
                    <span class="mua-article-card__author">{{ $article['author']['name'] }}</span>
                @endif
                @if (!empty($article['date_human']))
                    <span class="mua-article-card__date">{{ $article['date_human'] }}</span>
                @endif
            </div>
        </div>
    </article>
@else
    <article class="mua-article-card mua-article-card--empty">
        <p>{{ __('Article unavailable.') }}</p>
    </article>
@endif
