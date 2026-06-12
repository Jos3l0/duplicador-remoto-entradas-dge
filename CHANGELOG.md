# Changelog

## 1.0.9 - 2026-06-12

### Correcciones de columna administrativa
- Agregado registro forzado de columna `Sincronizado` desde `wp-remote-post-duplicator.php` usando hooks directos (`manage_post_posts_columns`, `manage_posts_columns`, etc.) para garantizar que la columna se registre antes de que WordPress construya la tabla de entradas.
- Agregado CSS inline en `admin_head-edit.php` para los iconos de estado de sincronizacion.

### Migracion de medios en contenido Gutenberg (no-Elementor)
- Agregado `migrate_content_media_urls()` en `EW_RPD_Sync` para migrar archivos por URL directa en el contenido de entradas (no solo attachments).
- Agregado `collect_content_media_urls()` que extrae URLs de: bloques Gutenberg con atributo `url` (e.g. `pdfemb`), tags `<a href>` a archivos locales, tags `<img src>`, y cualquier URL en `/wp-content/uploads/` o `/wp-includes/` con extension de archivo.
- Usa `sync_file_by_url()` para subir cualquier archivo local (PDFs, imagenes, documentos, etc.) aunque no esten registrados como WordPress attachments.

### Soporte para dominios adicionales locales
- `is_local_url()` en `EW_RPD_Media` ahora acepta dominios adicionales via el filtro `ew_rpd_local_domains`.
- Esto permite que URLs de subdominios como `recursos.mendoza.edu.ar` se traten como locales y se migren correctamente.

## 1.0.8 - 2026-06-12

### Migracion de medios desde Elementor y todos los tipos de archivo
- Agregado `sync_file_by_url()` en `EW_RPD_Media` para migrar cualquier archivo por URL local (PDFs, imagenes, documentos, etc.), incluso si no estan registrados como attachments de WordPress.
- Agregado `prepare_elementor_data_for_remote()` en `EW_RPD_Sync` que procesa `_elementor_data` postmeta, migra todos los medios referenciados y reemplaza URLs locales por URLs remotas.
- Los datos de Elementor migran con su estructura intacta; solo se reemplazan las URLs de archivos.
- Archivos locales no registrados se leen del disco y se suben directamente via REST API.
- URLs de terceros (dominios externos) se preservan sin cambios.
- Agregado `is_local_url()` y `url_to_local_path()` para resolucion de archivos.

### Correccion de bug en build_payload
- Corregido bug donde `send_loop_meta` sobrescribia `_elementor_data` en el payload.
- Ahora `_elementor_data` se agrega despues del loop meta.

## 1.0.5 - 2026-06-08

### Nueva funcionalidad
- **Sincronización masiva por categoría**: sincroniza todas las entradas publicadas de una categoría completa.
- Barra de progreso en tiempo real con conteo de OK/errores.
- Resultados por entrada visibles durante el proceso.
- Procesamiento por lotes de 5 en 5 para evitar timeouts.

### Correcciones
- Iconos de columna: nube azul = sincronizado, nube gris = pendiente, sin tilde verde ni ID.
- Row action "Sincronizar remoto" ahora usa AJAX sin redirigir.
- Zip de distribución corregido: carpeta contenedora dentro del zip para evitar duplicados al instalar.

## 1.0.4 - 2026-06-08

### Seguridad
- Logs movidos de `wp-content/uploads/` a `wp-content/ew-rpd-logs/` para reducir exposición pública.
- Advertencia visual en ajustes si la URL destino no usa HTTPS.
- Advertencia en logs (una por ciclo) si la conexión no es HTTPS.
- Rotación automática de logs al superar 5 MB.
- Sanitización reforzada en todos los nuevos endpoints.

### UX / Interfaz
- **Meta box "Sincronización remota"** en el editor de entradas: muestra estado, ID remoto, enlace "Ver remoto", última sincronización y botón para sincronizar vía AJAX.
- **Columna "Sincronizado"** en el listado de entradas (entre Título y Categorías) con iconos de estado:
  - ☁️✅ Verde: sincronizado
  - ☁️ Azul: pendiente
  - ⚠️ Naranja: error
  - ➖ Gris: no aplica
- Tooltip con ID remoto y fecha de última sincronización en la columna.
- Enlace "Ver remoto" desde la columna.
- Botón "Eliminar logs" en la página de ajustes con confirmación visual.

### Funcionalidad
- Tracking de errores por entrada (`_ew_rpd_last_error`).
- Sincronización desde meta box vía AJAX (sin recargar la página).
- Limpieza del directorio de logs en desinstalación.

## 1.0.3

- Nombre del plugin traducido a espanol: Duplicador Remoto de Entradas DGE.
- Autor actualizado: Por Equipo del Portal DGE Gob. de Mendoza.
- Author URI actualizado: https://www.mendoza.edu.ar/.
- Agregada sincronizacion de imagenes internas del contenido y galerias.
- Reemplazo de URLs del dominio origen por URLs de medios subidos al destino.
- Reemplazo de IDs en bloques Gutenberg `id`, `ids`, `wp-image-*`, `data-id` y shortcode `[gallery ids="..."]`.

# Changelog

## 1.0.2 - 2026-05-28

- Corrige la validacion de URL destino para aceptar dominios publicos validos como https://des.mendoza.edu.ar/.
- Sustituye `wp_http_validate_url()` por una validacion controlada de esquema, host y puerto para evitar falsos negativos en ciertos entornos.

## 1.0.1 - 2026-05-28

- Corrige error fatal de activacion por orden de carga de clases.
- Carga `EW_RPD_Settings` antes de usar `EW_RPD_Settings::get_defaults()` en el hook de activacion.
- Mantiene la version v4-complete sin cambios funcionales adicionales.

## 1.0.0 - v4-complete

- Plugin modular completo.
- Configuracion en admin.
- Sincronizacion automatica en `save_post` y `transition_post_status`.
- Sincronizacion manual desde ajustes y fila de entradas.
- REST API con Application Passwords.
- Creacion/actualizacion remota segun ID remoto guardado.
- Sincronizacion de imagen destacada.
- Sincronizacion de categorias y etiquetas por slug.
- Creacion opcional de terminos remotos.
- Hash de contenido para evitar llamadas repetidas.
- Bloqueo temporal para evitar sincronizacion doble.
- Logs locales.
- Reintento sin meta tecnica si el destino no la acepta.
