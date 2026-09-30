@php
    // Render the original XBoard shell using its current manifest and data.
    // Calling the engine directly avoids recursively invoking the admin composer.
    $nqOriginal = app('view.engine.resolver')->resolve('blade')->get(resource_path('views/admin.blade.php'), get_defined_vars());
    echo str_replace('</body>', '<script type="module" src="/plugins/node_quality/admin-bridge.js?v=0.4.0"></script></body>', $nqOriginal);
@endphp
