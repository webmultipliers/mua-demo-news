@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $article = $block['article'] ?? [];
    if (! is_array($article)) {
        $article = [];
    }
    $title   = $article['title']     ?? ($block['title']   ?? '');
    $excerpt = $article['excerpt']   ?? ($block['excerpt'] ?? '');
    $url     = $article['url']       ?? ($block['url']     ?? '');
    $image   = $article['thumbnail'] ?? ($block['image']   ?? null);
    $cta     = $block['cta_label']   ?? 'Read more';
@endphp

<article class="mua-article-card">
    @if (is_array($image) && !empty($image['url']))
        <img class="mua-article-card__image" src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}" />
    @endif

    <div class="mua-article-card__body">
        @if ($title !== '')
            <h2 class="mua-article-card__title">{{ $title }}</h2>
        @endif

        @if ($excerpt !== '')
            <p class="mua-article-card__excerpt">{{ $excerpt }}</p>
        @endif

        @if ($url !== '')
            <a class="mua-article-card__cta" href="{{ $url }}">{{ $cta }}</a>
        @endif
    </div>
</article>
