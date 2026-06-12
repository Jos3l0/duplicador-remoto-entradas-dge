# Changelog

## 1.0.7 - 2026-06-12

- Agregada migracion de medios desde Elementor: procesa `_elementor_data` postmeta y migra PDFs, imagenes y cualquier archivo referenciado en el JSON.
- Nuevo metodo `sync_file_by_url()` en `EW_RPD_Media` para migrar cualquier archivo por su URL local.
- URLs de terceros (dominios externos) se preservan sin cambios en el contenido.
- Archivos locales no registrados como attachments se leen del disco y se suben directamente.

## 1.0.6

- Correccion definitiva de la columna administrativa **Sincronizado**.
- La columna se registra desde el bootstrap principal del plugin para que exista antes de que WordPress construya la tabla de entradas.
- Se agregan hooks directos para `manage_post_posts_columns`, `manage_posts_columns`, `manage_post_posts_custom_column` y `manage_posts_custom_column`.

# Changelog

## 1.0.6
- Corregido el registro de la columna administrativa `Sincronizado` para que aparezca en la lista nativa de entradas.
- Agregado fallback con `manage_posts_columns` y `manage_posts_custom_column`.
- Agregado registro adicional en `admin_init` para evitar que el hook quede fuera de tiempo en pantallas de administración.

## 1.0.4

- Agregada columna administrativa `Sincronizado` en el listado de entradas y tipos de contenido configurados.
- La columna se inserta entre `Titulo` y `Categorias`.
- Agregados iconos visuales para estados: sincronizado, no sincronizado, error y sincronizacion parcial.
- Agregados metadatos de estado: `_ew_rpd_last_sync_status` y `_ew_rpd_last_sync_error`.
- La columna muestra ID remoto, enlace `Ver remoto` y tooltip con ultima sincronizacion/error cuando corresponde.
- Actualizado valor de version del plugin a 1.0.4.

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
