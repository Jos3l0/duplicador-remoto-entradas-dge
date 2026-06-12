# Duplicador Remoto de Entradas DGE

Plugin para duplicar entradas desde un WordPress origen hacia un WordPress destino mediante REST API autenticada con Application Password.

## Version entregada

1.0.9

## Estado

**Funcionando correctamente.** Todas las funcionalidades de sincronizacion estan operativas.

## Autor

Por Equipo del Portal DGE Gob. de Mendoza

https://www.mendoza.edu.ar/

## Funcionalidades principales

- Sincroniza imagen destacada.
- Sincroniza medios embebidos en el contenido (imagenes, PDFs, documentos, etc.).
- Reemplaza URLs locales del origen por URLs del destino.
- Reemplaza IDs de imagen en bloques Gutenberg y galerias clasicas `[gallery ids="..."]`.
- **Sincronizacion de Elementor**: migra `_elementor_data` postmeta y reemplaza URLs de medios en el JSON.
- **Sincronizacion de PDFs en bloques Gutenberg**: detecta `pdfemb/pdf-embedder-viewer`, `wp:file`, y `<a href>` a archivos locales.
- Meta box en editor con estado de sincronizacion y boton de sync AJAX.
- Columna "Sincronizado" en listado de entradas con iconos de estado (nube azul, gris, naranja).
- Sincronizacion masiva por categoria con barra de progreso.
- Logs en directorio no publico (`wp-content/ew-rpd-logs/`) con rotacion automatica.
- Advertencia de seguridad para conexiones sin HTTPS.
- Mantiene logs y pruebas de conexion REST.

## Notas de uso

### Dominios adicionales para sincronizacion de medios

Si el sitio usa subdominios adicionales para archivos (por ejemplo, `recursos.mendoza.edu.ar`), agregarlos con el filtro `ew_rpd_local_domains`:

```php
add_filter( 'ew_rpd_local_domains', function( $domains ) {
    $domains[] = 'https://recursos.mendoza.edu.ar';
    return $domains;
} );
```

## Cambios recientes

### v1.0.9
- Correccion de columna "Sincronizado" registrada desde el bootstrap del plugin.
- Migracion de medios en contenido Gutenberg por URL (no solo attachments).
- Soporte para dominios locales adicionales via filtro `ew_rpd_local_domains`.

### v1.0.8
- Soporte para sincronizacion de Elementor (`_elementor_data` postmeta).
- Migracion de cualquier archivo por URL (PDFs, imagenes, documentos).
- Correccion de bug donde `send_loop_meta` sobrescribia `_elementor_data`.

### v1.0.7
- Soporte para sincronizacion de Elementor.
- Mejoras en columna administrativa.

### v1.0.5
- Meta box de sincronizacion en editor.
- Sincronizacion AJAX desde row actions.
- Sincronizacion masiva por categoria.
- Logs en directorio no publico.
- Rotacion de logs.

### v1.0.4
- Agregada columna administrativa "Sincronizado" en el listado de entradas.

### v1.0.3
- Sincronizacion de imagenes internas del contenido y galerias.
- Reemplazo de URLs del dominio origen por URLs de medios subidos al destino.

### v1.0.2
- Correccion de validacion de URL destino.

### v1.0.1
- Correccion de error fatal de activacion.

### v1.0.0
- Plugin modular completo.
- Configuracion en admin.
- Sincronizacion automatica.

