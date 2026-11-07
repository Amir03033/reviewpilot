@php
    $used = $getState()['used'] ?? 0;
    $allowance = $getState()['allowance'] ?? 0;
    $pct = $allowance > 0 ? min(100, round($used / $allowance * 100)) : ($used > 0 ? 100 : 0);
    $color = $pct >= 100 ? '#dc2626' : ($pct >= 80 ? '#f59e0b' : '#D9A441');
@endphp
<div style="min-width: 180px; padding: 6px 12px;">
    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
        <span>{{ $used }} / {{ $allowance ?: '—' }}</span>
        <span style="opacity:.7">{{ $pct }}%</span>
    </div>
    <div style="height:6px; border-radius:999px; background:rgba(127,127,127,.25); overflow:hidden;">
        <div style="height:100%; width:{{ $pct }}%; background:{{ $color }}; border-radius:999px;"></div>
    </div>
</div>
