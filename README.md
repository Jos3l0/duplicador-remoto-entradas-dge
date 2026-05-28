# Duplicador Remoto de Entradas DGE

**Versión documentada:** 1.0.3  
**Nombre del plugin:** Duplicador Remoto de Entradas DGE  
**Autor:** Por Equipo del Portal DGE Gob. de Mendoza  
**URL del autor / institución:** https://www.mendoza.edu.ar/  
**Tipo de plugin:** Sincronización WordPress a WordPress mediante REST API  
**Uso principal:** Duplicar entradas publicadas o actualizadas desde un WordPress origen hacia un WordPress destino.

---

## 1. Objetivo del plugin

El plugin permite duplicar entradas desde un WordPress origen hacia un WordPress destino, creando una copia real del contenido en el sitio remoto mediante la REST API de WordPress.

No se limita a mostrar contenido remoto: crea una entrada física en la base de datos del WordPress destino.

El flujo general es:

```text
WordPress origen
    ↓
Entrada publicada o actualizada
    ↓
Plugin Duplicador Remoto de Entradas DGE
    ↓
REST API autenticada
    ↓
WordPress destino
    ↓
Entrada creada o actualizada
```

---

## 2. Qué crea en el WordPress destino

El plugin puede crear o actualizar los siguientes elementos:

```text
post
attachment
category
post_tag
```

### 2.1 Entrada remota

Crea una entrada real en el WordPress destino.

Por defecto, el tipo de contenido configurado es:

```text
post
```

La entrada remota incluye:

- título
- contenido
- extracto
- slug
- estado configurado
- categorías
- etiquetas
- imagen destacada
- imágenes internas del contenido
- galerías
- relación con el ID de la entrada original

### 2.2 Medios / attachments

El plugin puede subir archivos de imagen al WordPress destino como elementos de la biblioteca de medios.

Puede sincronizar:

- imagen destacada
- imágenes insertadas dentro del contenido
- imágenes de bloques Gutenberg
- imágenes de galerías Gutenberg
- imágenes referenciadas mediante clases `wp-image-ID`
- imágenes referenciadas mediante atributos `data-id`
- imágenes usadas en shortcodes clásicos `[gallery ids="..."]`, cuando los IDs puedan resolverse en el origen

### 2.3 Categorías

El plugin revisa las categorías asignadas a la entrada original.

Comportamiento:

```text
Si la categoría existe en destino por slug:
    la reutiliza.

Si no existe y la creación de términos está habilitada:
    la crea en destino.

Si no existe y la creación de términos no está habilitada:
    no la asigna.
```

### 2.4 Etiquetas

El comportamiento con etiquetas es equivalente al de las categorías.

```text
Si la etiqueta existe en destino por slug:
    la reutiliza.

Si no existe y la creación de términos está habilitada:
    la crea en destino.

Si no existe y la creación de términos no está habilitada:
    no la asigna.
```

---

## 3. Qué no crea

El plugin no crea ni modifica:

```text
usuarios
páginas, salvo que se habilite explícitamente otro post type
menús
widgets
opciones globales del sitio
plugins
temas
roles
permisos
configuración del servidor
contenido no configurado
```

---

## 4. Autor de la entrada remota

La entrada creada en el WordPress destino queda asociada al usuario REST usado para la conexión.

Ejemplo:

```text
Usuario REST destino: WebDev
```

En ese caso, la entrada remota quedará creada por el usuario `WebDev`.

El plugin no replica automáticamente el autor original del WordPress origen, porque esto requeriría un mapeo seguro de usuarios entre ambos sitios.

---

## 5. Configuración del plugin

La configuración se realiza en el WordPress origen desde:

```text
Ajustes > Duplicador remoto
```

Campos principales:

```text
URL del WordPress destino
Usuario REST destino
Application Password
Estado destino
Tipos de contenido a sincronizar
Creación de términos remotos
Sincronización de imagen destacada
Sincronización de imágenes internas
Sincronización automática al publicar o actualizar
Logs
```

---

## 6. URL del WordPress destino

Debe configurarse la URL base del WordPress destino.

Ejemplo:

```text
https://des.mendoza.edu.ar/
```

El plugin construye internamente las rutas REST necesarias, por ejemplo:

```text
https://des.mendoza.edu.ar/wp-json/wp/v2/posts
https://des.mendoza.edu.ar/wp-json/wp/v2/media
https://des.mendoza.edu.ar/wp-json/wp/v2/categories
https://des.mendoza.edu.ar/wp-json/wp/v2/tags
https://des.mendoza.edu.ar/wp-json/wp/v2/users/me?context=edit
```

---

## 7. Autenticación REST

El plugin usa autenticación básica contra la REST API de WordPress mediante Application Passwords.

No debe usarse la contraseña normal del usuario administrador.

Debe usarse una contraseña de aplicación generada desde el perfil del usuario en el WordPress destino:

```text
Usuarios > Perfil > Contraseñas de aplicación
```

Ejemplo de nombre recomendado para la clave:

```text
EW Remote Post Duplicator
```

En el plugin se configura:

```text
Usuario REST destino: WebDev
Application Password: contraseña de aplicación generada por WordPress
```

---

## 8. Compatibilidad con Wordfence

Si Wordfence está instalado en el WordPress destino, puede desactivar las Application Passwords.

En ese caso, en el perfil del usuario se verá un mensaje similar a:

```text
Las contraseñas de aplicación han sido desactivadas por Wordfence.
```

Para que el plugin funcione, se debe habilitar nuevamente esta función desde las opciones de Wordfence, sin desactivar completamente Wordfence.

La acción correcta es permitir las Application Passwords de WordPress.

---

## 9. Funcionamiento al crear una entrada

Cuando se publica o actualiza una entrada en el WordPress origen, el plugin realiza el siguiente flujo:

```text
1. Detecta el guardado o publicación de la entrada.
2. Verifica si el tipo de contenido está permitido.
3. Verifica si la sincronización automática está habilitada.
4. Obtiene los datos principales de la entrada.
5. Sincroniza la imagen destacada si está habilitado.
6. Procesa imágenes internas y galerías del contenido.
7. Crea o reutiliza categorías remotas.
8. Crea o reutiliza etiquetas remotas.
9. Crea la entrada remota si todavía no existe.
10. Guarda el ID remoto en el postmeta del origen.
11. Si la entrada ya tenía ID remoto, actualiza la entrada remota.
12. Registra el resultado en los logs.
```

---

## 10. Relación entre entrada origen y entrada destino

Para evitar duplicados, el plugin guarda en el WordPress origen el ID de la entrada creada en el WordPress destino.

Conceptualmente:

```text
Entrada origen ID 100
    ↓
_post_sync_remote_id = 875
    ↓
Entrada destino ID 875
```

Si la entrada origen vuelve a actualizarse, el plugin no crea una segunda entrada en destino. En su lugar, actualiza la entrada remota existente.

---

## 11. Sincronización de imágenes del contenido

La versión 1.0.3 incorpora procesamiento de imágenes internas para evitar que el contenido remoto conserve URLs del dominio origen.

El plugin intenta detectar imágenes en:

```text
<img src="...">
bloques Gutenberg core/image
bloques Gutenberg core/gallery
clases wp-image-ID
atributos data-id
shortcodes [gallery ids="..."]
```

Cuando identifica una imagen local del WordPress origen:

```text
1. Resuelve el attachment original.
2. Descarga o lee el archivo desde el origen.
3. Lo sube al WordPress destino mediante REST API.
4. Obtiene la nueva URL remota.
5. Reemplaza la URL anterior en el contenido.
```

Resultado esperado:

```text
Antes:
https://origen.ejemplo.gob.ar/wp-content/uploads/2026/05/imagen.jpg

Después:
https://destino.ejemplo.gob.ar/wp-content/uploads/2026/05/imagen.jpg
```

---

## 12. Imagen destacada

Si la entrada origen tiene imagen destacada y la opción está habilitada:

```text
1. El plugin obtiene el attachment de la imagen destacada.
2. La sube al WordPress destino.
3. Obtiene el ID remoto del attachment.
4. Asigna ese ID como featured_media de la entrada remota.
```

---

## 13. Categorías y etiquetas por slug

El plugin trabaja preferentemente con `slug`, no solamente con nombre visible.

Esto reduce problemas cuando hay nombres duplicados o diferencias menores de mayúsculas/minúsculas.

Ejemplo:

```text
Categoría origen:
Nombre: Últimas Noticias
Slug: ultimas-noticias

Categoría destino:
Slug: ultimas-noticias
```

Si el slug existe en destino, se reutiliza ese término.

---

## 14. Estado de publicación en destino

El plugin permite definir el estado con que se crea la entrada remota.

Estado recomendado para pruebas:

```text
draft
```

Esto permite revisar el contenido antes de publicarlo en el destino.

Estados posibles según permisos REST y configuración del sitio:

```text
draft
publish
pending
private
```

La recomendación operativa es usar `draft` durante pruebas y `publish` solo cuando el flujo esté validado.

---

## 15. Prueba de conexión REST

El plugin incluye una prueba de conexión desde la pantalla de configuración.

El endpoint utilizado para validar la autenticación es:

```text
/wp-json/wp/v2/users/me?context=edit
```

Resultado correcto esperado:

```text
Connection test ok. {"user":"WebDev"}
```

Este resultado confirma:

```text
1. La URL destino es válida.
2. El WordPress destino responde por REST API.
3. La autenticación REST funciona.
4. El usuario tiene permisos suficientes para contexto edit.
```

---

## 16. Logs

El plugin registra eventos importantes en logs internos.

Ejemplos de eventos:

```text
Plugin activated
Connection test ok
Connection test failed
HTTP request failed
Remote HTTP error
Post synchronized
Post updated remotely
Media uploaded
Term created
```

Los logs ayudan a diagnosticar:

```text
errores SSL
errores de proxy
errores 401 / autenticación
errores de permisos REST
errores al subir medios
errores al crear términos
errores al crear o actualizar entradas
```

---

## 17. Seguridad implementada

El plugin incorpora prácticas de seguridad propias de WordPress:

```text
Nonces en acciones administrativas
Capability checks para acceso a configuración
Sanitización de entradas de configuración
Escape de salidas dinámicas
Validación de URL destino
Uso de Application Password en lugar de contraseña normal
Control de errores en llamadas HTTP
Bloqueo temporal para evitar doble sincronización
Uso de REST API oficial
```

---

## 18. Consideraciones de proxy

En el entorno donde se probó el plugin, el WordPress origen tenía configurado un proxy mediante constantes:

```text
WP_PROXY_HOST
WP_PROXY_PORT
WP_PROXY_BYPASS_HOSTS
```

El proxy causaba error SSL:

```text
cURL error 60: SSL certificate problem: self-signed certificate in certificate chain
```

La solución aplicada fue agregar el dominio destino al bypass del proxy:

```text
des.mendoza.edu.ar
www.des.mendoza.edu.ar
```

Ejemplo conceptual:

```php
define('WP_PROXY_BYPASS_HOSTS', 'localhost, wordpress.org, des.mendoza.edu.ar, www.des.mendoza.edu.ar');
```

Luego fue necesario reiniciar PHP-FPM para que el entorno web tomara la nueva configuración.

---

## 19. Requisitos técnicos

### WordPress origen

```text
WordPress con REST API activa
PHP compatible con la versión del sitio
Permisos para instalar plugins
Acceso a wp-admin
Capacidad para realizar peticiones HTTP salientes
```

### WordPress destino

```text
WordPress con REST API activa
Usuario REST con permisos suficientes
Application Password habilitada
Permisos para crear entradas
Permisos para subir medios
Permisos para asignar categorías y etiquetas
```

### Usuario REST recomendado

Para pruebas:

```text
Administrador
```

Para producción:

```text
Usuario dedicado con permisos mínimos necesarios
```

Ejemplo:

```text
WebDev
```

---

## 20. Permisos necesarios del usuario REST destino

El usuario REST debe poder:

```text
crear entradas
editar entradas propias o asignadas
subir archivos
leer usuarios/me con context=edit
asignar categorías
asignar etiquetas
crear categorías, si está habilitado
crear etiquetas, si está habilitado
```

Si se desea permitir creación automática de términos, el usuario necesita permisos suficientes para administrar términos.

---

## 21. Instalación

1. Descargar el ZIP del plugin.
2. Entrar al WordPress origen.
3. Ir a:

```text
Plugins > Añadir nuevo > Subir plugin
```

4. Subir el archivo ZIP.
5. Activar el plugin.
6. Configurar desde:

```text
Ajustes > Duplicador remoto
```

7. Probar conexión REST.
8. Publicar o actualizar una entrada de prueba.

---

## 22. Procedimiento de prueba recomendado

1. Configurar el estado destino como:

```text
draft
```

2. Crear una entrada de prueba en origen.
3. Agregar:
   - título
   - texto
   - imagen destacada
   - una imagen dentro del contenido
   - una galería
   - una categoría
   - una etiqueta

4. Publicar la entrada.
5. Revisar en destino:

```text
Entradas > Todas las entradas > Borradores
```

6. Confirmar que:
   - la entrada existe
   - el contenido se copió
   - la imagen destacada existe en destino
   - las imágenes internas no apuntan al dominio origen
   - la galería usa imágenes del destino
   - categorías y etiquetas fueron asignadas correctamente

---

## 23. Problemas conocidos y diagnóstico

### Error: URL destino inválida

Causa posible:

```text
Validación de URL demasiado restrictiva o URL mal escrita.
```

Corregido desde la versión 1.0.2.

### Error: cURL error 60

Causa posible:

```text
Problema SSL, proxy corporativo o cadena de certificados interceptada.
```

Diagnóstico recomendado:

```text
openssl s_client
curl -Iv
wp_remote_get() desde WP-CLI
revisión de WP_PROXY_HOST y WP_PROXY_BYPASS_HOSTS
```

### Error: 401 / No estás conectado

Causa posible:

```text
Se usó contraseña normal en vez de Application Password.
Application Password incorrecta.
Application Password revocada.
Wordfence deshabilitó Application Passwords.
Usuario REST incorrecto.
```

Solución:

```text
Generar una nueva Application Password en el perfil del usuario destino.
Habilitar Application Passwords en Wordfence.
Verificar el usuario real, no solo el nombre visible.
```

---

## 24. Historial de versiones

### v1.0.0

Primera versión funcional del plugin.

Incluyó:

```text
Configuración en admin
Conexión REST
Prueba de conexión
Duplicación de entradas
Imagen destacada
Categorías y etiquetas
Logs
Seguridad administrativa básica
```

### v1.0.1

Corrección de error fatal en activación.

Problema corregido:

```text
Class "EW_RPD_Settings" not found
```

Causa:

```text
Orden de carga incorrecto durante register_activation_hook.
```

### v1.0.2

Corrección de validación de URL destino.

Problema corregido:

```text
URL destino invalida
```

Se ajustó la validación para aceptar correctamente dominios públicos como:

```text
https://des.mendoza.edu.ar/
```

### v1.0.3

Versión con ajustes funcionales y de identidad institucional.

Cambios:

```text
Nombre del plugin en español
Autor actualizado
URL institucional actualizada
Sincronización de imágenes internas
Procesamiento de galerías
Reemplazo de URLs del dominio origen por URLs del dominio destino
Soporte para bloques Gutenberg image/gallery
Soporte para shortcodes [gallery]
```

---

## 25. Estado operativo validado

Estado validado al 2026-05-28:

```text
Plugin activado correctamente.
Conexión REST funcionando.
Destino: https://des.mendoza.edu.ar/
Usuario REST validado: WebDev
Resultado: Connection test ok. {"user":"WebDev"}
```

Problemas resueltos durante la puesta en marcha:

```text
Fatal error de activación
Validación de URL destino
Error SSL por proxy
Bypass de proxy para dominio destino
OPcache/PHP-FPM recargado
Application Passwords bloqueadas por Wordfence
Autenticación REST validada
```

---

## 26. Recomendaciones de operación

Para producción se recomienda:

```text
Usar usuario REST dedicado.
No usar contraseña normal de administrador.
Mantener Application Password protegida.
Iniciar con estado destino draft.
Revisar logs después de cada prueba.
Validar galerías e imágenes internas.
No desactivar Wordfence completo.
Permitir Application Passwords solo si es necesario.
Mantener el dominio destino en WP_PROXY_BYPASS_HOSTS si existe proxy corporativo.
```

---

## 27. Resumen ejecutivo

El plugin **Duplicador Remoto de Entradas DGE** permite publicar o actualizar una entrada en un WordPress origen y crear automáticamente una copia real en un WordPress destino.

Además de la entrada, puede sincronizar medios, imagen destacada, imágenes internas, galerías, categorías y etiquetas.

Utiliza la REST API oficial de WordPress con Application Passwords, mantiene relación entre la entrada origen y la remota para evitar duplicados, registra logs operativos y está preparado para entornos con proxy y Wordfence, siempre que las Application Passwords estén habilitadas.
