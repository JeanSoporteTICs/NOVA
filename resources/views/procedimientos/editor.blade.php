<!doctype html>
<html lang="es" class="onlyoffice-editor-page">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $fileName }} · Procedimientos</title>
    @include('nova.partials.favicon')
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime(public_path('assets/nova-ui.css')) ?: '1' }}" rel="stylesheet">
    <script src="{{ rtrim($onlyOfficeUrl, '/') }}/web-apps/apps/api/documents/api.js"></script>
</head>
<body class="nova-page onlyoffice-editor-page">
    <nav class="onlyoffice-editor-nav" aria-label="Navegación del editor">
        <a class="onlyoffice-editor-back" href="{{ route('procedimientos.index') }}" aria-label="Volver a Procedimientos" title="Volver a Procedimientos">
            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                <path d="M15 18l-6-6 6-6M9 12h10" />
            </svg>
        </a>
    </nav>
    <div id="onlyoffice-editor" class="onlyoffice-editor-host"></div>
    <script>
        (() => {
            const config = @json($editorConfig, JSON_UNESCAPED_SLASHES);
            config.width = '100%';
            config.height = '100%';
            config.events = { onError: event => console.error('OnlyOffice', event?.data || event) };
            window.procedimientosEditor = new DocsAPI.DocEditor('onlyoffice-editor', config);
        })();
    </script>
</body>
</html>
