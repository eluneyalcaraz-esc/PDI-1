ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS categoria VARCHAR(40) NOT NULL DEFAULT 'otros',
    ADD COLUMN IF NOT EXISTS motivo_revision VARCHAR(500) NULL;

CREATE TABLE producto_imagenes (
    producto_id INTEGER NOT NULL,
    orden SMALLINT NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    PRIMARY KEY (producto_id, orden),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
);

UPDATE productos
SET estado = 'pendiente',
    motivo_revision = 'Agregá al menos una imagen para volver a publicar este producto.'
WHERE estado = 'aprobado'
  AND NOT EXISTS (
      SELECT 1
      FROM producto_imagenes
      WHERE producto_imagenes.producto_id = productos.id
  );
