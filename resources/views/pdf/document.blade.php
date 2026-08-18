<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>{!! $css !!}</style>
    <style>
        @page { margin: 24mm 18mm 20mm; }
        body { background: #fff !important; color: #111 !important; }
        .pdf-note-page { page-break-before: always; }
        .pdf-note-page:first-child { page-break-before: auto; }
        .pdf-note-page h1 { margin-top: 0; }
    </style>
</head>
<body>
    @foreach ($documents as $document)
        <section class="pdf-note-page">
            <h1>{{ $document['title'] }}</h1>
            {!! $document['html'] !!}
        </section>
    @endforeach
</body>
</html>
