# Changelog

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
