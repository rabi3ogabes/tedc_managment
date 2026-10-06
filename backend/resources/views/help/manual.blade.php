<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<style>
    body { font-family: dejavusans; font-size: 11pt; color: #1b2433; direction: {{ $dir }}; text-align: {{ $dir === 'rtl' ? 'right' : 'left' }}; }
    h1 { font-size: 26pt; color: #123b5d; margin: 0 0 6pt; }
    h2 { font-size: 16pt; color: #123b5d; border-bottom: 1.5pt solid #c9a24b; padding-bottom: 3pt; margin-top: 18pt; }
    h3 { font-size: 12.5pt; color: #123b5d; }
    .cover { text-align: center; padding-top: 120pt; }
    .meta td { padding: 3pt 8pt; border: 0.5pt solid #c8d0db; font-size: 10pt; }
    .toc a { color: #123b5d; text-decoration: none; }
    .toc li { margin-bottom: 4pt; }
    .shot { text-align: center; margin: 8pt 0; }
    .shot img { max-width: 100%; border: 0.5pt solid #c8d0db; }
    .cap { font-size: 9pt; color: #5b6573; }
    blockquote { border-{{ $dir === 'rtl' ? 'right' : 'left' }}: 3pt solid #c9a24b; margin: 6pt 0; padding: 2pt 8pt; color: #3a4658; }
</style>
</head>
<body>
<div class="cover">
    @if($logo)<img src="{{ $logo }}" height="70"><br><br>@endif
    <h1>{{ $lang === 'ar' ? 'دليل المستخدم' : 'User manual' }}</h1>
    <h2 style="border:0">{{ $roleName }}</h2>
    <p>{{ is_array($center) ? ($center[$lang] ?? '') : $center }}</p>
    <table class="meta" align="center"><tr>
        <td>{{ $lang === 'ar' ? 'الإصدار' : 'Release' }}</td><td>{{ $release }}</td>
        <td>{{ $lang === 'ar' ? 'التاريخ' : 'Date' }}</td><td>{{ $date }}</td>
        <td>{{ $lang === 'ar' ? 'عدد المقالات' : 'Articles' }}</td><td>{{ $articles->count() }}</td></tr></table>
</div>
<pagebreak />
<h2>{{ $lang === 'ar' ? 'المحتويات' : 'Contents' }}</h2>
<ol class="toc">
@foreach($articles as $a)
    <li><a href="#a{{ $loop->index }}">{{ $lang === 'ar' ? $a->title_ar : $a->title_en }}</a> <span class="cap">(v{{ $a->version }})</span></li>
@endforeach
</ol>
@foreach($articles as $a)
    <pagebreak />
    <h2 id="a{{ $loop->index }}"><a name="a{{ $loop->index }}"></a>{{ $lang === 'ar' ? $a->title_ar : $a->title_en }}</h2>
    {!! $lang === 'ar' ? $a->body_ar : $a->body_en !!}
    @foreach($a->shots as $s)
        <div class="shot">@if($s['data'])<img src="{{ $s['data'] }}">@endif<div class="cap">{{ $s['caption'] }}</div></div>
    @endforeach
    @if($a->video_url)<p class="cap">{{ $lang === 'ar' ? 'فيديو توضيحي: ' : 'Video: ' }}{{ $a->video_url }}</p>@endif
@endforeach
</body>
</html>
