<svg {{ $attributes->class('h-full w-full') }}
     viewBox="0 0 {{ $width }} {{ $height }}"
     preserveAspectRatio="none" role="img" aria-label="Grafik metrik">
    @if ($limit !== null)
        <line x1="0" x2="{{ $width }}" y1="{{ $y($limit) }}" y2="{{ $y($limit) }}"
              stroke="var(--adbm-danger)" stroke-width="1" stroke-dasharray="4 4"
              opacity="0.75" vector-effect="non-scaling-stroke" />
    @endif

    @if ($values->isNotEmpty())
        <polyline points="{{ $points() }}" fill="none" stroke="currentColor"
                  stroke-width="2" stroke-linejoin="round" stroke-linecap="round"
                  vector-effect="non-scaling-stroke" />
        @if ($values->count() === 1)
            <circle cx="0" cy="{{ $y($values->first()) }}" r="3" fill="currentColor" />
        @endif
    @endif
</svg>
