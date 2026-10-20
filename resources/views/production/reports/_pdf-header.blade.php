<table class="header">
    <tr>
        <td style="width: 35%;">
            @if ($logo)
                <img src="{{ $logo }}" style="max-height: 34px; max-width: 150px; margin-bottom: 4px;" alt="">
                <br>
            @endif
            <span class="company">{{ $company }}</span>
        </td>
        <td class="right">
            <div class="title" style="white-space: nowrap;">{{ $title }}</div>
            <div class="muted" style="margin-top: 2px;">{{ $subtitle }}</div>
        </td>
    </tr>
</table>
<div class="rule"></div>
<div class="footer">
    {{ __('Generated :date by :name', ['date' => $generatedAt->translatedFormat('d F Y H:i'), 'name' => $generatedBy ?? '—']) }} · {{ $company }}
</div>
