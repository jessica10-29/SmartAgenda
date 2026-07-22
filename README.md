# SmartAgenda

Agenda web en PHP, MySQL, HTML, CSS y JavaScript para uso personal o laboral.

## Instalación en XAMPP

1. Enciende Apache y MySQL.
2. Abre phpMyAdmin e importa `bd.sql`.
3. Si tu MySQL no usa `root` sin contraseña, actualiza los datos en `conexion.php`.
4. Abre `http://localhost/SmartAgenda/` y crea tu cuenta.

Los archivos subidos se guardan en `uploads/` y se entregan mediante `download.php`, validando siempre el usuario. Para producción configura `SMARTAGENDA_SECRET` como variable de entorno y usa HTTPS.

## Incluye

- Autenticación con `password_hash`, sesiones seguras y protección CSRF.
- CRUD de agenda con detección de duplicados, prioridades, repetición y recordatorios.
- Carga protegida de PDF, DOCX, TXT e imágenes.
- Firma dibujada en mouse o pantalla táctil.
- Visor para abrir archivos subidos y firma visual de PDF e imágenes dentro de la agenda; la copia firmada queda guardada como un nuevo documento.
- Bóveda de claves cifradas con AES-256-CBC.
- Contactos y números marcados como bloqueados/sospechosos.
- Exportación JSON, búsqueda, correcciones ortográficas comunes y diseño responsive.
- Herramientas opcionales del navegador: voz, GPS, Bluetooth, compartir y cámara con permiso.

Las llamadas, WhatsApp, Bluetooth, GPS y la cámara dependen de permisos y capacidades del navegador. Una aplicación web no puede interceptar llamadas ni tomar fotografías silenciosamente.

La firma de PDF usa `pdf-lib` desde un CDN del navegador. Para firmar PDF necesitas conexión a internet; si no hay conexión aún puedes abrir y firmar imágenes, o descargar el documento original.
