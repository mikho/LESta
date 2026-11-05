<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('legal.company_name') ?? config('app.name') }}. {{ __('All rights reserved.') }}

{{ __('You receive this message because of activity on your account. We send no marketing email, so there is nothing to unsubscribe from.') }}
<a href="{{ route('legal.privacy') }}">{{ __('Privacy policy') }}</a>@if (config('legal.privacy_email')) · {{ config('legal.privacy_email') }}@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
