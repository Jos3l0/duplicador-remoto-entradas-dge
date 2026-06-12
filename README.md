# Duplicador Remoto de Entradas DGE

Plugin para duplicar entradas desde un WordPress origen hacia un WordPress destino mediante REST API autenticada con Application Password.

## Version entregada

1.0.3

## Autor

Por Equipo del Portal DGE Gob. de Mendoza

https://www.mendoza.edu.ar/

## Cambios principales

- Sincroniza imagen destacada.
- Sincroniza medios embebidos en el contenido.
- Reemplaza URLs locales del origen por URLs del destino.
- Reemplaza IDs de imagen en bloques Gutenberg y galerias clasicas `[gallery ids="..."]`.
- Mantiene logs y pruebas de conexion REST.


## Cambios 1.0.4

- Se agrega la columna **Sincronizado** en el listado de entradas del administrador.
- La columna aparece entre **Titulo** y **Categorias**.
- Muestra estado visual de sincronizacion con icono tipo nube, ID remoto y enlace para ver el contenido remoto cuando existe.


## Notas v1.0.6

- Corrige la visualizacion de la columna **Sincronizado** en el listado nativo de entradas del administrador.
- Registra la columna mediante hooks dinamicos y fallback global de la tabla de entradas.
