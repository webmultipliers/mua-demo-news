@props(['block' => [], 'children' => [], 'gates' => []])

@php
    $title = $block['title'] ?? '';
    $items = $block['items'] ?? [];
    if (! is_array($items)) {
        $items = [];
    }
@endphp

<section class="mua-article-list">
    @if ($title !== '')
        <h2 class="mua-article-list__title">{{ $title }}</h2>
    @endif

    @if (empty($items))
        <p class="mua-article-list__empty">No articles available.</p>
    @else
        <ul class="mua-article-list__items">
            @foreach ($items as $item)
                @php
                    $itemUrl     = is_array($item) ? ($item['url']       ?? '') : '';
                    $itemTitle   = is_array($item) ? ($item['title']     ?? '') : '';
                    $itemDate    = is_array($item) ? ($item['date']      ?? '') : '';
                    $itemExcerpt = is_array($item) ? ($item['excerpt']   ?? '') : '';
                    $thumb       = is_array($item) ? ($item['thumbnail'] ?? null) : null;
                @endphp
                <li class="mua-article-list__item">
                    @if ($itemUrl !== '')
                        <a class="mua-article-list__link" href="{{ $itemUrl }}">
                    @endif
                    @if (is_array($thumb) && !empty($thumb['url']))
                        <img class="mua-article-list__thumb" src="{{ $thumb['url'] }}" alt="" />
                    @endif
                    <div class="mua-article-list__body">
                        <h3 class="mua-article-list__item-title">{{ $itemTitle }}</h3>
                        @if ($itemDate !== '')
                            <time class="mua-article-list__date">{{ $itemDate }}</time>
                        @endif
                        @if ($itemExcerpt !== '')
                            <p class="mua-article-list__excerpt">{{ $itemExcerpt }}</p>
                        @endif
                    </div>
                    @if ($itemUrl !== '')
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
