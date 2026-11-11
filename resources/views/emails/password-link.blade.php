<x-mail::message>
# {{ $heading }}

{{ $intro }}

<x-mail::button :url="$url">
{{ $actionLabel }}
</x-mail::button>

{{ $footnote }}

If the button does not work, copy this address into your browser:
{{ $url }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
