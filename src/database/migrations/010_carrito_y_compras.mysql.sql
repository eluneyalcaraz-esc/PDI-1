CREATE TABLE IF NOT EXISTS carrito_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL DEFAULT 1,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_carrito_usuario_producto (usuario_id, producto_id),
    CONSTRAINT fk_carrito_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_carrito_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS compras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    total DECIMAL(10, 2) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_compras_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS compra_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    compra_id INT NOT NULL,
    producto_id INT NULL,
    vendedor_id INT NULL,
    nombre_producto VARCHAR(255) NOT NULL,
    nombre_vendedor VARCHAR(255) NOT NULL,
    precio_unitario DECIMAL(10, 2) NOT NULL,
    cantidad INT NOT NULL,
    CONSTRAINT fk_compra_items_compra
        FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
    CONSTRAINT fk_compra_items_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
    CONSTRAINT fk_compra_items_vendedor
        FOREIGN KEY (vendedor_id) REFERENCES usuarios(id) ON DELETE SET NULL
);
