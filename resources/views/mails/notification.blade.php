@extends('mails.layout', ['title' => $title ?? ($heading ?? 'Notification'), 'preheader' => $preheader ?? ($intro ?? '')])

@php
    /**
     * Data-driven notification email. Every section is optional.
     * Vars:
     *   $emoji        e.g. '💬'  (leading icon for the heading)
     *   $heading      big title
     *   $greetingName recipient first name
     *   $intro        lead paragraph (plain text)
     *   $quote        optional quoted body (message/comment text)
     *   $quoteAuthor  optional name shown above the quote
     *   $details      optional array of ['label'=>, 'value'=>] or ['label'=>, 'badge'=>['text'=>,'kind'=>'status|priority']]
     *   $items        optional array of ['title'=>, 'meta'=>, 'badge'=>['text'=>,'kind'=>], 'url'=>]  (for digests)
     *   $cta          optional ['text'=>, 'url'=>]
     *   $signoff      optional closing line
     *   $accent       hex without '#'
     */
    $accent = $accent ?? '4f46e5';

    $badgeStyle = function ($text, $kind = 'status') {
        $s = strtolower(trim((string) $text));
        $map = [
            'status' => [
                'completed' => ['#dcfce7', '#166534'], 'done' => ['#dcfce7', '#166534'],
                'in progress' => ['#dbeafe', '#1e40af'],
                'backlog' => ['#f3e8ff', '#6b21a8'],
                'on hold' => ['#ffedd5', '#9a3412'],
                'awaiting info' => ['#fef9c3', '#854d0e'],
                'in-house review' => ['#cffafe', '#155e75'],
                'client review' => ['#e0e7ff', '#3730a3'],
            ],
            'priority' => [
                'urgent' => ['#ffedd5', '#9a3412'],
                'high' => ['#fee2e2', '#991b1b'],
                'normal' => ['#dbeafe', '#1e40af'], 'medium' => ['#dbeafe', '#1e40af'],
                'low' => ['#dcfce7', '#166534'],
            ],
        ];
        [$bg, $fg] = $map[$kind][$s] ?? ['#f1f5f9', '#334155'];
        return "background-color:{$bg}; color:{$fg};";
    };
@endphp

@section('content')
    @if(!empty($heading))
        <h1 class="h1" style="margin:0 0 16px 0; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:30px; font-weight:bold; color:#0f172a;">
            @if(!empty($emoji))<span style="font-size:24px;">{{ $emoji }}</span>&nbsp;@endif{{ $heading }}
        </h1>
    @endif

    @if(!empty($greetingName))
        <p style="margin:0 0 14px 0; font-size:15px; line-height:23px; color:#334155;">Hi {{ $greetingName }},</p>
    @endif

    @if(!empty($intro))
        <p style="margin:0 0 20px 0; font-size:15px; line-height:23px; color:#334155;">{!! $intro !!}</p>
    @endif

    {{-- Quoted message / comment --}}
    @if(!empty($quote))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px 0;">
            <tr>
                <td style="background-color:#f8fafc; border-left:4px solid #{{ $accent }}; border-radius:8px; padding:16px 18px;">
                    @if(!empty($quoteAuthor))
                        <div style="font-family:Arial,Helvetica,sans-serif; font-size:13px; font-weight:bold; color:#0f172a; margin-bottom:6px;">{{ $quoteAuthor }}</div>
                    @endif
                    <div style="font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:23px; color:#475569;">{!! nl2br(e($quote)) !!}</div>
                </td>
            </tr>
        </table>
    @endif

    {{-- Detail rows --}}
    @if(!empty($details) && is_array($details))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0; background-color:#f8fafc; border:1px solid #eef2f7; border-radius:10px;">
            @foreach($details as $i => $row)
                <tr>
                    <td style="padding:12px 18px; {{ $i > 0 ? 'border-top:1px solid #eef2f7;' : '' }} font-family:Arial,Helvetica,sans-serif; font-size:13px; color:#64748b; width:38%; vertical-align:middle;">{{ $row['label'] ?? '' }}</td>
                    <td style="padding:12px 18px; {{ $i > 0 ? 'border-top:1px solid #eef2f7;' : '' }} font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#0f172a; font-weight:bold; vertical-align:middle;" align="right">
                        @if(!empty($row['badge']))
                            <span style="display:inline-block; padding:4px 12px; border-radius:999px; font-size:12px; font-weight:bold; {{ $badgeStyle($row['badge']['text'] ?? '', $row['badge']['kind'] ?? 'status') }}">{{ $row['badge']['text'] ?? '' }}</span>
                        @else
                            {{ $row['value'] ?? '' }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Item list (digests, e.g. tasks due soon) --}}
    @if(!empty($items) && is_array($items))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
            @foreach($items as $item)
                <tr>
                    <td style="padding:0 0 12px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff; border:1px solid #e5e7eb; border-radius:10px;">
                            <tr>
                                <td style="padding:14px 16px; vertical-align:middle;">
                                    <div style="font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#0f172a; margin-bottom:3px;">
                                        @if(!empty($item['url']))<a href="{{ $item['url'] }}" style="color:#0f172a; text-decoration:none;">{{ $item['title'] ?? 'Untitled' }}</a>@else{{ $item['title'] ?? 'Untitled' }}@endif
                                    </div>
                                    @if(!empty($item['meta']))
                                        <div style="font-family:Arial,Helvetica,sans-serif; font-size:13px; color:#64748b;">{{ $item['meta'] }}</div>
                                    @endif
                                </td>
                                @if(!empty($item['badge']))
                                    <td align="right" style="padding:14px 16px; vertical-align:middle; white-space:nowrap;">
                                        <span style="display:inline-block; padding:4px 12px; border-radius:999px; font-size:12px; font-weight:bold; {{ $badgeStyle($item['badge']['text'] ?? '', $item['badge']['kind'] ?? 'status') }}">{{ $item['badge']['text'] ?? '' }}</span>
                                    </td>
                                @endif
                            </tr>
                        </table>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- CTA button (bulletproof) --}}
    @if(!empty($cta) && !empty($cta['url']))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 8px 0;">
            <tr>
                <td align="center" bgcolor="#{{ $accent }}" class="btn" style="border-radius:8px; background-color:#{{ $accent }};">
                    <a href="{{ $cta['url'] }}" target="_blank" class="btn-a" style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">{{ $cta['text'] ?? 'View' }}</a>
                </td>
            </tr>
        </table>
    @endif

    @if(!empty($signoff))
        <p style="margin:22px 0 0 0; font-size:14px; line-height:22px; color:#64748b;">{!! $signoff !!}</p>
    @endif
@endsection
