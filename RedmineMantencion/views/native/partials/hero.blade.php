<section class="card card-hero sb-page-hero nova-system-hero mb-3">
    <div class="hero-content">
        <div class="hero-icon" aria-hidden="true"><i class="bi {{ $icon ?? 'bi-broadcast-pin' }}"></i></div>
        <div class="hero-copy">
            <h1 class="rm-page-title">{{ $title ?? '' }}</h1>
            @if (!empty($subtitle))
                <div class="hero-subtitle">{{ $subtitle }}</div>
            @endif
        </div>
        @if (!empty($badges))
            <div class="hero-extras">
                @foreach (($badges ?? []) as $badge)
                    <span class="badge bg-white bg-opacity-25 text-white border border-white">
                        @if (!empty($badge['icon']))<i class="bi {{ $badge['icon'] }}"></i>@endif
                        {{ $badge['label'] ?? '' }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</section>
