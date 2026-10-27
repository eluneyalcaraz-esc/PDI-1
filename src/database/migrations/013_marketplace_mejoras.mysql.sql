ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS condicion VARCHAR(20) NOT NULL DEFAULT 'nuevo',
    ADD COLUMN IF NOT EXISTS unidad VARCHAR(40) NOT NULL DEFAULT 'unidad',
    ADD COLUMN IF NOT EXISTS cantidad_minima INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS stock INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS peso_gramos INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS largo_cm DECIMAL(7, 2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ancho_cm DECIMAL(7, 2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS alto_cm DECIMAL(7, 2) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reportes INT NOT NULL DEFAULT 0;

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS localidad VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS provincia VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS codigo_postal VARCHAR(12) NULL,
    ADD COLUMN IF NOT EXISTS descripcion_vendedor VARCHAR(500) NULL;

ALTER TABLE compras
    ADD COLUMN IF NOT EXISTS moneda CHAR(3) NOT NULL DEFAULT 'ARS',
    ADD COLUMN IF NOT EXISTS estado_pago VARCHAR(30) NOT NULL DEFAULT 'sin_pago',
    ADD COLUMN IF NOT EXISTS mp_preference_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS mp_init_point VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS mp_payment_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS envio_proveedor VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS envio_estado VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS envio_costo DECIMAL(10, 2) NULL,
    ADD COLUMN IF NOT EXISTS envio_tracking VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS destino_nombre VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS destino_direccion VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS destino_localidad VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS destino_provincia VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS destino_codigo_postal VARCHAR(12) NULL,
    ADD COLUMN IF NOT EXISTS destino_telefono VARCHAR(30) NULL;

CREATE TABLE IF NOT EXISTS producto_favoritos (
    usuario_id INT NOT NULL,
    producto_id INT NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, producto_id),
    CONSTRAINT fk_favoritos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_favoritos_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS producto_reportes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    usuario_id INT NULL,
    motivo VARCHAR(40) NOT NULL,
    detalle VARCHAR(500) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reportes_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    CONSTRAINT fk_reportes_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS producto_resenas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compra_item_id INT NOT NULL UNIQUE,
    producto_id INT NOT NULL,
    comprador_id INT NULL,
    vendedor_id INT NULL,
    puntaje TINYINT NOT NULL,
    comentario VARCHAR(1000) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_resenas_compra_item
        FOREIGN KEY (compra_item_id) REFERENCES compra_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_resenas_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    CONSTRAINT fk_resenas_comprador
        FOREIGN KEY (comprador_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_resenas_vendedor
        FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notificaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    mensaje VARCHAR(500) NOT NULL,
    enlace VARCHAR(255) NULL,
    leida_en TIMESTAMP NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notificaciones_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admin_auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accion VARCHAR(40) NOT NULL,
    entidad VARCHAR(40) NOT NULL,
    entidad_id INT NULL,
    detalle VARCHAR(500) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS mercado_pago_eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evento_id VARCHAR(100) NOT NULL UNIQUE,
    tipo VARCHAR(40) NOT NULL,
    procesado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE compra_items
    ADD COLUMN IF NOT EXISTS producto_unidad VARCHAR(40) NOT NULL DEFAULT 'unidad';
