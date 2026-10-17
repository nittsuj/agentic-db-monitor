<svg {{ $attributes->class('h-36 w-full') }}
     viewBox="0 0 {{ $width }} {{ $height }}"
     preserveAspectRatio="none" role="img" aria-label="Metric trend">
    @if ($limit !== null)
        <line x1="0" x2="{{ $width }}" y1="{{ $y($limit) }}" y2="{{ $y($limit) }}"
              class="stroke-red-500" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke" />
    @endif

    @if ($values->isNotEmpty())
        <polyline points="{{ $points() }}" fill="none" class="stroke-current"
                  stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
        @if ($values->count() === 1)
            <circle cx="0" cy="{{ $y($values->first()) }}" r="3" class="fill-current" />
        @endif
    @endif
</svg>
