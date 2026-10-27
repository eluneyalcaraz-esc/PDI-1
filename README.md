# Emprendi

**Descripción:**
Página donde emprendedores y distribuidores puedan empezar o expandir su negocio, crear su propia página o publicación de forma sencilla, contactar con proveedores y vender sus productos.

Integrantes:

- {Tules, Roman} | [@Tules-222](https://github.com/Tules-222)
- {Pogonza, David} | [@PogonzaDavid](https://github.com/PogonzaDavid)
- {Alcaraz, Eluney} | [@eluneyalcaraz-esc](https://github.com/eluneyalcaraz-esc)

Proyecto institucional **E.E.S.T Nº4 de Berazategui**.

## Elevator's Pitch

- Para pequeños o grandes comerciantes, proveedores y empresas.
- Quienes precisan materia prima para sus productos o vender la que poseen.
- Emprendi es una plataforma para conectar emprendimientos, proveedores y compradores.
- Que permite desarrollar tus ideas de negocio con facilidad.
- Diferente a MercadoLibre y Shoppify.
- Nuestro proyecto permite encontrar información, materiales y proveedores, y obtenerlos mediante este portal de compras.

## Requisitos

- PHP 8.1 o superior.
- Extensión PDO habilitada.
- MySQL o MariaDB para las migraciones incluidas en este repositorio.

## Inicio local

1. Copiá `.env.example` como `.env`, configurá el acceso a la base de datos y reemplazá `ADMIN_PIN` por un PIN largo, aleatorio y compartido solo con las personas que administran el sitio.
2. Creá la base indicada en `DB_NAME` (por defecto, `slim_php`).
3. Instalá dependencias y aplicá las migraciones:

   ```bash
   composer install
   composer run-script migrate
   ```

4. Iniciá el servidor:

   ```bash
   composer run-script serve
   ```

   Abrí `http://localhost:8000`. El servidor queda activo hasta que lo detengas
   con `Ctrl+C`.

Las cuentas se registran con el rol fijo `usuario` y una contraseña. Cada
usuario puede administrar sus propias publicaciones; el catálogo muestra
únicamente productos aprobados. Las publicaciones nuevas y las que se editan
quedan pendientes hasta que un administrador las revise en `/admin`.
El panel usa el PIN compartido configurado como `ADMIN_PIN` en `.env`; cualquier
persona que conozca ese PIN puede revisar productos y gestionar cuentas. Desde
el panel se pueden editar datos de usuarios, bloquear o desbloquear el acceso y
eliminar cuentas. Al eliminar una cuenta se anonimiza la información personal,
se retiran sus productos del catálogo y se conserva el historial de pedidos.
Al rechazar una publicación, el administrador debe indicar el motivo, que el
vendedor verá en “Mis productos”.

Cada producto requiere categoría, precio válido, descripción de al menos 30
caracteres y cuatro palabras, confirmación de que es real y al menos una imagen
JPEG, PNG o WebP. Se permiten hasta cinco imágenes de 5 MB cada una. Las imágenes
se guardan en `public/uploads/productos/`; ese directorio se ignora en Git para
no subir archivos de usuarios. El servidor también limita cada cuenta a cinco
publicaciones por hora y veinte por día, y bloquea nombres duplicados del mismo
usuario. La revisión manual es la que determina si una publicación aparece en
el catálogo.

Al aplicar la migración nueva, los productos anteriores que no tengan imágenes
quedan pendientes; su dueño puede agregarles una foto desde “Editar producto”
y volverán a la cola de revisión.

El catálogo ofrece búsqueda, categorías, filtros, favoritos y opiniones. Las
opiniones solo se habilitan para los productos de pedidos que el vendedor marcó
como entregados. Los vendedores pueden consultar los pedidos y cambiar su
estado desde «Ventas recibidas».

Al confirmar el carrito se valida y reserva el stock; el pedido se separa por
vendedor. El vendedor debe cotizar el envío real de Correo Argentino antes de
que el comprador pueda pagar. La cotización y el número de seguimiento se
cargan manualmente: todavía no existe una integración de tarifas, etiquetas ni
seguimiento con la API de Correo Argentino.

Checkout Pro de Mercado Pago está deshabilitado de forma predeterminada. Para
habilitarlo, configurá `APP_URL` como un dominio público con HTTPS, y agregá
`MP_ACCESS_TOKEN` y `MP_WEBHOOK_SECRET` al `.env` desde el panel de
desarrolladores de Mercado Pago. Configurá en esa aplicación la notificación
Webhook de pagos hacia
`https://tu-dominio/integraciones/mercadopago/webhook`. El token y el secreto
son credenciales privadas: nunca los subas al repositorio ni los compartas.
No se aceptan pagos si falta cualquiera de estas variables o si `APP_URL` no
usa HTTPS. El pago es centralizado: los fondos se acreditan a la cuenta de
Mercado Pago de Emprendi; la liquidación a vendedores no está automatizada.
Emprendi no cobra comisión de plataforma, aunque Mercado Pago puede aplicar
sus propios cargos por procesamiento.

Si no están configuradas las credenciales de Mercado Pago o una URL HTTPS
pública, el sitio informa que el pago está deshabilitado y no simula cobros.

Si PHP rechaza archivos antes de llegar a la aplicación, revisá `php.ini` y
configurá `upload_max_filesize=5M`, `post_max_size=26M` y `max_file_uploads=5`.

## Migraciones

Colocá tus archivos `.sql` en `src/database/migrations/` y ejecuta:

```bash
composer run-script migrate
```

## Licencia

Este proyecto está licenciado bajo la [Licencia MIT](./LICENSE).

---

Generado por la [plantilla](https://github.com/PDI-EEST4/slim-template-2026), a discreción de los integrantes del proyecto.
