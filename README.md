# Overshine Contacts

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
- El Overshine Contacts es una página informativa de comercio.
- Que permite desarrollar tus ideas de negocio con facilidad.
- Diferente a MercadoLibre y Shoppify.
- Nuestro proyecto permite encontrar información, materiales y proveedores, y obtenerlos mediante este portal de compras.

## Requisitos

- PHP 8.1 o superior.
- Extensión PDO habilitada.
- MySQL o MariaDB para las migraciones incluidas en este repositorio.

## Inicio local

1. Copiá `.env.example` como `.env` y configurá el acceso a tu base de datos.
2. Creá la base indicada en `DB_NAME` (por defecto, `slim_php`).
3. Instalá dependencias y aplicá las migraciones:

   ```bash
   composer install
   composer migrate
   ```

4. Iniciá el servidor:

   ```bash
   composer serve
   ```

   Abrí `http://localhost:8000`. El servidor queda activo hasta que lo detengas
   con `Ctrl+C`.

Las cuentas se registran con el rol fijo `usuario` y una contraseña. Cada
usuario puede administrar sus propias publicaciones; el catálogo es visible
para todos. El carrito se conserva en la base de datos. Al confirmar desde el
carrito se registra un pedido sin procesar pagos.

## Migraciones

Colocá tus archivos `.sql` en `src/database/migrations/` y ejecuta:

```bash
composer migrate
```

## Licencia

Este proyecto está licenciado bajo la [Licencia MIT](./LICENSE).

---

Generado por la [plantilla](https://github.com/PDI-EEST4/slim-template-2026), a discreción de los integrantes del proyecto.
