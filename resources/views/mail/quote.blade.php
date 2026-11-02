<x-mail::message>
{!! nl2br(e($body)) !!}

<x-mail::button :url="$url">
Visualizza e accetta il preventivo
</x-mail::button>
</x-mail::message>
